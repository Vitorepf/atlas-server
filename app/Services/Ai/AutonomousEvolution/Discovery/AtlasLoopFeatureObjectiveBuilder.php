<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;

/**
 * Lever 3 — the FEATURE lane: the loop IMPLEMENTS new behavior (not just behavior-preserving
 * refactors), including big, multi-file features.
 *
 * The contract is a `feature` task whose acceptance is a FROZEN spec test pinning the new behavior,
 * gated pass/fail (METRIC_GATE) with revert_recheck=true. revert_recheck is the anti-gaming keystone:
 * after the candidate makes the test GREEN, the judge REVERTS the diff and re-runs — if it is still
 * green the change was vacuous/test-gamed (the test was already passing) and it is rejected
 * (acceptance_not_diff_earned). So the feature must genuinely EARN the test going from RED→GREEN, and
 * the test itself is frozen (the provider can never touch it). allowed_globs are the implementation
 * files (the provider may CREATE new ones among them — multi-file features ride the Lever-2 diff
 * transport that carries new files through the cert gate).
 */
final class AtlasLoopFeatureObjectiveBuilder
{
    public const OBJECTIVE_KIND = 'feature';

    /**
     * @param  list<string>  $implFiles  files the provider may edit/CREATE to implement the feature
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}
     */
    public function build(string $featureName, string $featureSpec, string $acceptanceTestRel, array $implFiles, ?string $provider = null): array
    {
        $acceptanceTestRel = ltrim(str_replace('\\', '/', $acceptanceTestRel), '/');
        $implFiles = array_values(array_unique(array_map(
            static fn (string $f): string => ltrim(str_replace('\\', '/', $f), '/'),
            $implFiles,
        )));

        $command = './vendor/bin/phpunit '.escapeshellarg($acceptanceTestRel);
        $acceptance = [
            'commands' => [$command],
            // The provider implements ONLY here (and may create new files among these); the spec test
            // + config are FROZEN so the test can never be weakened to pass trivially.
            'allowed_globs' => $implFiles,
            'frozen_globs' => array_values(array_unique([$acceptanceTestRel, 'tests/**', 'phpunit.xml', 'phpunit.xml.dist', 'composer.json'])),
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_GATE, // the spec test passes or it does not
            'revert_recheck' => true, // diff_earned: revert => must go RED, else the change was vacuous/gamed
            'timeout_seconds' => max(60, (int) config('atlas.loop.feature_timeout_seconds', 600)),
        ];

        $payload = [
            'materializer' => 'framework',
            'objective_kind' => self::OBJECTIVE_KIND,
            'feature_name' => $featureName,
            'acceptance' => $acceptance,
            'allowed_files' => $implFiles,
            'validation_commands' => [$command],
            'feature_acceptance_test' => $acceptanceTestRel,
        ];
        if ($provider !== null && $provider !== '') {
            $payload['provider'] = $provider;
        }

        $acceptanceHash = hash('sha256', json_encode([
            'objective_kind' => self::OBJECTIVE_KIND,
            'commands' => $acceptance['commands'],
            'allowed_globs' => $acceptance['allowed_globs'],
            'metric_kind' => $acceptance['metric_kind'],
            'revert_recheck' => true,
            'feature' => $featureName,
        ], JSON_THROW_ON_ERROR));

        return [
            'objective' => $this->objectiveText($featureName, $featureSpec, $acceptanceTestRel, $implFiles),
            'payload' => $payload,
            'acceptance_hash' => $acceptanceHash,
        ];
    }

    /**
     * @param  list<string>  $implFiles
     */
    private function objectiveText(string $featureName, string $featureSpec, string $acceptanceTestRel, array $implFiles): string
    {
        $files = implode(', ', $implFiles);

        return "IMPLEMENT the feature \"{$featureName}\" so the frozen acceptance test passes.\n\n"
            ."SPEC: {$featureSpec}\n\n"
            ."The acceptance test is at {$acceptanceTestRel} and is currently RED — make it GREEN by "
            ."implementing the REAL behavior it requires. Run it and iterate until every case passes:\n"
            ."    ./vendor/bin/phpunit ".$acceptanceTestRel."\n\n"
            ."HARD RULES: implement ONLY in these files (create any new file you need among them): {$files}. "
            ."Do NOT touch the acceptance test, any other test, phpunit config, or composer.json — those are "
            ."FROZEN. Your change is correct ONLY when the frozen test goes from RED to GREEN by REAL "
            ."implementation: the judge will REVERT your diff and re-run — if the test still passes with your "
            ."change reverted, it was vacuous and is REJECTED. So the behavior must genuinely be NEW. Build it "
            ."properly (clear, cohesive, no dead code); a feature that passes by luck or a stub will be refused.";
    }
}
