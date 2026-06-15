<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;

/**
 * Builds the task spec for an EXTRACT-CLASS (ENORMOUS) refactor: move a cohesive cluster of methods
 * off an over-complex class into a NEW class, driving the worst method's cyclomatic down IN PLACE.
 *
 * The contract is a `refactor_*` task (so the framework materializer exempts it from forced
 * diff-earned — a behavior-preserving refactor stays green when reverted) carrying BOTH
 * complexity_proof (so the existing complexity branch fires) AND structural_proof (so the judge +
 * certifier route the verdict to the per-method-identity gate, which supersedes the new-file-lock so
 * the legitimate new class file is provable). allowed_globs covers the target AND the exact new class
 * path; the production target stays editable but no sibling/test/config can be touched. The
 * anti-relocation invariant lives in the structural verdict — a method moved INTACT earns nothing.
 */
final class AtlasLoopExtractClassObjectiveBuilder
{
    public const OBJECTIVE_KIND = 'refactor_extract_class';

    /**
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}
     */
    public function build(string $targetRel, string $siblingTestRel, string $worstMethod, int $cyclomatic, ?string $provider = null): array
    {
        $targetRel = ltrim(str_replace('\\', '/', $targetRel), '/');
        $siblingTestRel = ltrim(str_replace('\\', '/', $siblingTestRel), '/');
        $newClassRel = $this->newClassPath($targetRel);
        $newClassName = $this->classNameFromPath($newClassRel);

        $command = './vendor/bin/phpunit '.escapeshellarg($siblingTestRel);
        $acceptance = [
            'commands' => [$command],
            // Provider edits the TARGET and CREATES the new class; the sibling test + config are FROZEN.
            'allowed_globs' => [$targetRel, $newClassRel],
            'frozen_globs' => [$siblingTestRel, 'tests/**', 'phpunit.xml', 'phpunit.xml.dist', 'composer.json'],
            'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
            'complexity_proof' => true,   // fires the existing complexity branch in judge + certifier
            'structural_proof' => true,   // swaps the verdict to the per-method-identity (anti-relocation) gate
            'revert_recheck' => false,    // behavior-preserving: stays green when reverted (proof is the AST drop)
            'timeout_seconds' => max(60, (int) config('atlas.loop.framework_refactor_timeout_seconds', 300)),
        ];

        $payload = [
            'materializer' => 'framework',
            'objective_kind' => self::OBJECTIVE_KIND,
            'target_relative_path' => $targetRel,
            'target_repo_path' => $targetRel,
            'acceptance' => $acceptance,
            'allowed_files' => [$targetRel, $newClassRel],
            'validation_commands' => [$command],
            'extract_class_new_path' => $newClassRel,
        ];
        if ($provider !== null && $provider !== '') {
            $payload['provider'] = $provider;
        }

        $acceptanceHash = hash('sha256', json_encode([
            'objective_kind' => self::OBJECTIVE_KIND,
            'commands' => $acceptance['commands'],
            'allowed_globs' => $acceptance['allowed_globs'],
            'structural_proof' => true,
            'target' => $targetRel,
        ], JSON_THROW_ON_ERROR));

        return [
            'objective' => $this->objectiveText($targetRel, $newClassRel, $newClassName, $worstMethod, $cyclomatic),
            'payload' => $payload,
            'acceptance_hash' => $acceptanceHash,
        ];
    }

    /**
     * The new class file path — same directory as the target, name = <Target>Support.php — so PSR-4
     * resolves it under the target's namespace. Must be passed to the provider VERBATIM so its diff
     * lands inside allowed_globs.
     */
    public function newClassPath(string $targetRel): string
    {
        $dir = trim(str_replace('\\', '/', dirname($targetRel)), '/');
        $base = basename($targetRel, '.php');

        return ($dir === '' || $dir === '.') ? $base.'Support.php' : $dir.'/'.$base.'Support.php';
    }

    private function classNameFromPath(string $rel): string
    {
        return basename($rel, '.php');
    }

    private function objectiveText(string $targetRel, string $newClassRel, string $newClassName, string $worstMethod, int $cyclomatic): string
    {
        return "Refactor {$targetRel} by EXTRACTING a cohesive cluster of its logic into a NEW class. "
            ."The worst method {$worstMethod} (cyclomatic {$cyclomatic}) must get strictly SIMPLER IN PLACE: "
            ."move a cohesive group of its branches/helpers into a new class and delegate to it. "
            ."Create the new class at EXACTLY this path: {$newClassRel} (class {$newClassName}, in the namespace "
            ."that PSR-4 maps to that path). Edit ONLY {$targetRel} and {$newClassRel} — do NOT modify any "
            ."test, phpunit config, or composer.json. CRITICAL: do NOT add new branches/conditionals/loops "
            ."— only RELOCATE existing ones into the new class; the total decision count must stay flat or "
            ."fall. A method merely MOVED intact (same complexity, just re-homed) will be REJECTED — a "
            ."SPECIFIC method's complexity must drop IN PLACE and the extracted helpers must each be simpler "
            ."than the original worst method. PRESERVE behavior exactly: the existing sibling test "
            ."{$worstMethod} relies on must stay GREEN. Your change is correct only when this passes: "
            ."the frozen sibling test for {$targetRel}.";
    }
}
