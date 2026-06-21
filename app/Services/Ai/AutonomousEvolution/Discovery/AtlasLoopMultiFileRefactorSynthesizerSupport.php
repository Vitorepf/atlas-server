<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;

final class AtlasLoopMultiFileRefactorSynthesizerSupport
{
    /**
     * @param  list<string>  $files
     * @return array{covered:list<string>, frozen:list<array{path:string,content:string}>, sibling_rels:list<string>, hub_frozen:?array{path:string,content:string}}|null
     */
    public function collectCoveredArtifacts(array $files, string $hub, string $repoRoot, AtlasLoopHarnessGuard $guard, AtlasLoopSiblingTestResolver $resolver): ?array
    {
        $covered = [];
        $frozen = [];
        $siblingRels = [];
        $hubFrozen = null;

        foreach ($files as $file) {
            if ($guard->isForbiddenSelfTarget($file)) {
                return null;
            }

            $sib = $resolver->resolve($file);
            $sibRel = is_string($sib['sibling_path'] ?? null) ? ltrim((string) $sib['sibling_path'], '/') : '';
            $body = '';
            if (($sib['has_sibling'] ?? false) && $sibRel !== '' && is_file($repoRoot.'/'.$sibRel)) {
                $body = (string) @file_get_contents($repoRoot.'/'.$sibRel);
            }

            if ($body === '') {
                if ($file === $hub) {
                    return null;
                }

                continue;
            }

            $covered[] = $file;
            $frozen[] = ['path' => $sibRel, 'content' => $body];
            $siblingRels[] = $sibRel;
            if ($file === $hub) {
                $hubFrozen = ['path' => $sibRel, 'content' => $body];
            }
        }

        $covered = array_values(array_unique($covered));
        sort($covered);
        if (count($covered) < 2 || ! in_array($hub, $covered, true)) {
            return null;
        }

        $siblingRels = array_values(array_unique($siblingRels));
        sort($siblingRels);

        return [
            'covered' => $covered,
            'frozen' => $frozen,
            'sibling_rels' => $siblingRels,
            'hub_frozen' => $hubFrozen,
        ];
    }

    /**
     * @param  array{covered:list<string>, frozen:list<array{path:string,content:string}>, sibling_rels:list<string>, hub_frozen:?array{path:string,content:string}}  $artifacts
     * @param  list<string>  $files
     * @param  callable(list<string>): bool  $clusterFramingThrashes
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}
     */
    public function synthesizeFromArtifacts(array $artifacts, array $files, string $hub, AtlasLoopObraClusterCandidate $cluster, string $provider, bool $hubFirstEnabled, bool $clusterFramingDegradeEnabled, callable $clusterFramingThrashes): array
    {
        $covered = $artifacts['covered'];
        $frozen = $artifacts['frozen'];
        $siblingRels = $artifacts['sibling_rels'];
        $hubFrozen = $artifacts['hub_frozen'];
        $allClusterFilesCovered = count($covered) === count($files);

        $task = ($hubFrozen !== null && $allClusterFilesCovered && ($hubFirstEnabled || ($clusterFramingDegradeEnabled && $clusterFramingThrashes($covered))))
            ? $this->buildHubFirstSingleFileTask($hub, $hubFrozen, $cluster)
            : $this->buildMultiFileTask($hub, $covered, $frozen, $siblingRels, $cluster);

        return $provider === ''
            ? $task
            : [
                'objective' => $task['objective'],
                'payload' => $task['payload'] + ['provider' => $provider],
                'acceptance_hash' => $task['acceptance_hash'],
            ];
    }

    /**
     * @param  list<string>  $covered
     * @param  list<array{path:string,content:string}>  $frozen
     * @param  list<string>  $siblingRels
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}
     */
    public function buildMultiFileTask(string $hub, array $covered, array $frozen, array $siblingRels, AtlasLoopObraClusterCandidate $cluster): array
    {
        $command = './vendor/bin/phpunit '.implode(' ', array_map('escapeshellarg', $siblingRels));
        $acceptance = [
            'commands' => [$command],
            'allowed_globs' => $covered,
            'frozen_globs' => ['tests/**', 'phpunit.xml', 'phpunit.xml.dist', 'composer.json'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
            'complexity_proof' => true,
            'complexity_aggregation' => 'max_per_method_primary_total_non_increasing',
            'revert_recheck' => false,
            'timeout_seconds' => max(60, (int) config('atlas.loop.multi_file_refactor_timeout_seconds', 600)),
        ];

        $objective = 'Refactor a coupled cluster ('.basename($hub).' + '.(count($covered) - 1).' caller(s)) to '
            .'substantially REDUCE complexity ACROSS the files (simplify/extract/dedupe shared logic) while '
            .'PRESERVING behavior — every file\'s existing tests must stay green.';

        $acceptanceHash = hash('sha256', json_encode([
            'commands' => $acceptance['commands'],
            'allowed_globs' => $acceptance['allowed_globs'],
            'frozen_globs' => $acceptance['frozen_globs'],
            'metric_kind' => $acceptance['metric_kind'],
            'objective_kind' => AtlasLoopMultiFileRefactorSynthesizer::OBJECTIVE_KIND,
            'multi_file' => true,
        ], JSON_THROW_ON_ERROR));

        return [
            'objective' => $objective,
            'payload' => [
                'materializer' => 'framework',
                'objective_kind' => AtlasLoopMultiFileRefactorSynthesizer::OBJECTIVE_KIND,
                'multi_file' => true,
                'cluster_hash' => $cluster->clusterHash,
                'target_relative_path' => $hub,
                'target_repo_path' => $hub,
                'frozen_tests' => $frozen,
                'acceptance' => $acceptance,
                'allowed_files' => $covered,
                'validation_commands' => [$command],
                'leverage_signals' => $cluster->leverageSignals,
            ],
            'acceptance_hash' => $acceptanceHash,
        ];
    }

    /**
     * @param  array{path:string,content:string}  $hubFrozen
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}
     */
    public function buildHubFirstSingleFileTask(string $hub, array $hubFrozen, AtlasLoopObraClusterCandidate $cluster): array
    {
        $hubSiblingRel = trim((string) $hubFrozen['path']);
        $command = './vendor/bin/phpunit '.escapeshellarg($hubSiblingRel);
        $acceptance = [
            'commands' => [$command],
            'allowed_globs' => [$hub],
            'frozen_globs' => ['tests/**', 'phpunit.xml', 'phpunit.xml.dist', 'composer.json'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
            'complexity_proof' => true,
            'complexity_aggregation' => 'max_per_method_primary_total_non_increasing',
            'revert_recheck' => false,
            'timeout_seconds' => max(60, (int) config('atlas.loop.multi_file_refactor_timeout_seconds', 600)),
        ];

        $objective = 'HUB-FIRST: refactor the cluster hub ('.basename($hub).') to substantially REDUCE its '
            .'complexity (simplify/extract/dedupe) while PRESERVING behavior — its existing tests must stay green. '
            .'Coupled callers are addressed in follow-up; landing the hub simplification alone is a real certified win.';

        $acceptanceHash = hash('sha256', json_encode([
            'commands' => $acceptance['commands'],
            'allowed_globs' => $acceptance['allowed_globs'],
            'frozen_globs' => $acceptance['frozen_globs'],
            'metric_kind' => $acceptance['metric_kind'],
            'objective_kind' => AtlasLoopMultiFileRefactorSynthesizer::OBJECTIVE_KIND,
            'multi_file' => false,
            'hub_first' => true,
        ], JSON_THROW_ON_ERROR));

        return [
            'objective' => $objective,
            'payload' => [
                'materializer' => 'framework',
                'objective_kind' => AtlasLoopMultiFileRefactorSynthesizer::OBJECTIVE_KIND,
                'multi_file' => false,
                'hub_first' => true,
                'cluster_hash' => $cluster->clusterHash,
                'target_relative_path' => $hub,
                'target_repo_path' => $hub,
                'frozen_tests' => [$hubFrozen],
                'acceptance' => $acceptance,
                'allowed_files' => [$hub],
                'validation_commands' => [$command],
                'leverage_signals' => $cluster->leverageSignals,
            ],
            'acceptance_hash' => $acceptanceHash,
        ];
    }
}
