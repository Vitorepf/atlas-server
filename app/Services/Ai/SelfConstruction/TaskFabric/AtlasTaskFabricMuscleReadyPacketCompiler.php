<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Final cheap guard before enqueue. Compiles a high-level evolution intent into a muscle-ready
 * packet draft ONLY when it carries an implementation target, a test target, runnable acceptance,
 * required evidence, no human/operator/provider steady-state dependency, and no scope collision
 * against already-claimed live targets.
 *
 * Refuses attractive-but-shallow ideas BEFORE they become a give_back or a shallow-green packet:
 * test-only or implementation-only intents, intents with no runnable proof, and any intent whose
 * target paths collide with work already in flight.
 *
 * Pure: no file writes, no enqueue, no git, no provider calls.
 */
final class AtlasTaskFabricMuscleReadyPacketCompiler
{
    public const SCHEMA = 'atlas.task_fabric.muscle_ready_packet_compiler.v1';

    /**
     * @param  array<string,mixed>  $intent  {objective?:string, implementation_target?:string,
     *                                        test_target?:string, acceptance_criteria?:list<string>,
     *                                        required_evidence?:list<string>, requires_operator?:bool,
     *                                        requires_human?:bool, requires_external_provider?:bool}
     * @param  list<string>  $liveTargets  paths already claimed by in-flight work
     * @return array{schema:string, admitted:bool, blockers:list<string>, packet?:array<string,mixed>}
     */
    public function compile(array $intent, array $liveTargets = []): array
    {
        $blockers = [];

        // Duplicate live_targets is a caller-input integrity failure — refused before anything else,
        // and before any packet draft is attempted.
        if (count($liveTargets) !== count(array_unique($liveTargets))) {
            $blockers[] = 'duplicate_live_targets';
        }

        $objective = trim((string) ($intent['objective'] ?? ''));
        if ($objective === '') {
            $blockers[] = 'missing_objective';
        }

        $impl = trim((string) ($intent['implementation_target'] ?? ''));
        if ($impl === '') {
            $blockers[] = 'missing_implementation_target';
        }

        $test = trim((string) ($intent['test_target'] ?? ''));
        if ($test === '') {
            $blockers[] = 'missing_test_target';
        }

        $acceptance = array_values(array_filter(
            array_map('strval', (array) ($intent['acceptance_criteria'] ?? [])),
            static fn (string $a): bool => trim($a) !== '',
        ));
        if (! $this->hasRunnableAcceptance($acceptance)) {
            $blockers[] = 'no_runnable_acceptance';
        } elseif (! $this->isBoundToTestTarget($acceptance, $test)) {
            // A generic "php artisan test exits 0" with no test path or --filter names nothing:
            // it can pass by running an unrelated suite. Acceptance must name the concrete target.
            $blockers[] = 'acceptance_not_bound_to_test_target';
        }

        $evidence = array_values(array_filter(
            array_map('strval', (array) ($intent['required_evidence'] ?? [])),
            static fn (string $e): bool => trim($e) !== '',
        ));
        if ($evidence === []) {
            $blockers[] = 'missing_required_evidence';
        }

        if ((bool) ($intent['requires_operator'] ?? false)) {
            $blockers[] = 'requires_operator';
        }
        if ((bool) ($intent['requires_human'] ?? false)) {
            $blockers[] = 'requires_human';
        }
        if ((bool) ($intent['requires_external_provider'] ?? false)) {
            $blockers[] = 'requires_external_provider';
        }

        $liveFamilies = [];
        foreach ($liveTargets as $liveTarget) {
            $liveFamilies[$this->family((string) $liveTarget)] = true;
        }

        if ($impl !== '' && in_array($impl, $liveTargets, true)) {
            $blockers[] = 'scope_collision_implementation_target';
        } elseif ($impl !== '' && isset($liveFamilies[$this->family($impl)])) {
            $blockers[] = 'family_collision_implementation_target';
        }
        if ($test !== '' && $test !== $impl && in_array($test, $liveTargets, true)) {
            $blockers[] = 'scope_collision_test_target';
        } elseif ($test !== '' && $test !== $impl && isset($liveFamilies[$this->family($test)])) {
            $blockers[] = 'family_collision_test_target';
        }

        $blockers = array_values(array_unique($blockers));

        if ($blockers !== []) {
            return [
                'schema' => self::SCHEMA,
                'admitted' => false,
                'blockers' => $blockers,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'admitted' => true,
            'blockers' => [],
            'packet' => [
                'objective' => $objective,
                'allowed_files' => [$impl, $test],
                'scope_in' => [$impl, $test],
                'acceptance_criteria' => $acceptance,
                'required_evidence' => $evidence,
            ],
        ];
    }

    /** Derives the implementation/test family for a path: basename without extension or Test suffix. */
    private function family(string $path): string
    {
        $base = basename($path);
        $base = preg_replace('/\.php$/', '', $base) ?? $base;

        return preg_replace('/Test$/', '', $base) ?? $base;
    }

    /** @param  list<string>  $acceptance */
    private function hasRunnableAcceptance(array $acceptance): bool
    {
        foreach ($acceptance as $criterion) {
            if (preg_match('/php\s+artisan\s+test|phpunit|exits?\s+0|passes\b/i', $criterion) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Requires the acceptance text to name the concrete test target: either the test_target
     * path itself, its basename, or an explicit --filter=/PHPUnit class filter argument.
     *
     * @param  list<string>  $acceptance
     */
    private function isBoundToTestTarget(array $acceptance, string $test): bool
    {
        if ($test === '') {
            return false;
        }

        $basename = basename($test);

        foreach ($acceptance as $criterion) {
            if (str_contains($criterion, $test) || str_contains($criterion, $basename)) {
                return true;
            }
            if (preg_match('/--filter[=\s]/i', $criterion) === 1) {
                return true;
            }
        }

        return false;
    }
}
