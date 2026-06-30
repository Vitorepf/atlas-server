<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure normalizer. Converts raw model task proposals into strict, provider-free
 * intermediate contracts ready for the task fabric.
 *
 * Required fields (AC2 — rejection if absent or empty):
 *   objective     — non-empty string describing the task goal.
 *   allowed_files — non-empty list of file paths that may be modified.
 *   acceptance    — non-empty list of acceptance criteria strings.
 *   evidence      — non-empty list of proof requirements.
 *
 * Optional fields (defaulted to [] when absent):
 *   scope_in      — list of what is in scope.
 *   risks         — list of known risks.
 *   dependencies  — list of dependency identifiers.
 *
 * AC3 — rejection policy:
 *   A proposal is rejected when any required field is missing or empty.
 *   The normalizer NEVER invents field values; it only rejects or passes through.
 *
 * AC4 outputs: normalized_contracts, rejected_inputs, missing_fields
 *   (union of all missing fields across rejected proposals),
 *   task_fabric_ready (true when at least one contract is normalized and none rejected).
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainOutputContractNormalizer
{
    public const SCHEMA = 'atlas.external_brain.output_contract_normalizer.v1';

    private const REQUIRED_FIELDS  = ['objective', 'allowed_files', 'acceptance', 'evidence'];
    private const OPTIONAL_FIELDS  = ['scope_in', 'risks', 'dependencies'];
    private const PROVIDER_NAMES   = ['claude', 'codex', 'openai', 'anthropic', 'gpt', 'gemini', 'fable', 'opus'];
    private const GENERIC_PHRASES  = ['make it work', 'do the thing', 'fix it', 'improve this', 'update the'];
    private const RUNNABLE_MARKERS = ['phpunit', 'artisan', 'bin/php', 'pytest', 'jest', 'rspec'];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function normalize(array $facts): array
    {
        $proposals = is_array($facts['proposals'] ?? null) ? $facts['proposals'] : [];

        $normalizedContracts = [];
        $rejectedInputs      = [];
        $missingFieldsUnion  = [];

        foreach ($proposals as $proposal) {
            $id = (string) ($proposal['id'] ?? '');

            [$contract, $missing, $violations] = $this->tryNormalize($proposal);

            $hasProblems = ! empty($missing) || ! empty($violations);

            if ($hasProblems) {
                $repairHints = array_merge(
                    array_map(static fn (string $f): string => "add required field: {$f}", $missing),
                    array_column($violations, 'repair_hint'),
                );
                $rejectedInputs[] = [
                    'id'               => $id,
                    'rejection_reason' => ! empty($missing) ? 'missing_required_fields' : 'semantic_violation',
                    'missing_fields'   => $missing,
                    'violation_reasons' => array_column($violations, 'code'),
                    'repair_hints'     => $repairHints,
                ];
                foreach ($missing as $field) {
                    $missingFieldsUnion[$field] = true;
                }
            } else {
                $normalizedContracts[] = array_merge(['id' => $id], $contract);
            }
        }

        $taskFabricReady = ! empty($normalizedContracts) && empty($rejectedInputs);

        return [
            'schema_version'       => self::SCHEMA,
            'normalized_contracts' => $normalizedContracts,
            'rejected_inputs'      => $rejectedInputs,
            'missing_fields'       => array_values(array_keys($missingFieldsUnion)),
            'task_fabric_ready'    => $taskFabricReady,
        ];
    }

    /**
     * @return array{array<string,mixed>, list<string>, list<array{code:string,repair_hint:string}>}
     */
    private function tryNormalize(array $proposal): array
    {
        $missing    = [];
        $violations = [];
        $contract   = [];

        $objective = trim((string) ($proposal['objective'] ?? ''));
        if ($objective === '') {
            $missing[] = 'objective';
        } else {
            $contract['objective'] = $objective;
        }

        $allowedFiles = $this->toStringList($proposal['allowed_files'] ?? null);
        if (empty($allowedFiles)) {
            $missing[] = 'allowed_files';
        } else {
            $contract['allowed_files'] = $allowedFiles;
        }

        $acceptance = $this->toStringList($proposal['acceptance'] ?? null);
        if (empty($acceptance)) {
            $missing[] = 'acceptance';
        } else {
            $contract['acceptance'] = $acceptance;
        }

        $evidence = $this->toStringList($proposal['evidence'] ?? null);
        if (empty($evidence)) {
            $missing[] = 'evidence';
        } else {
            $contract['evidence'] = $evidence;
        }

        foreach (self::OPTIONAL_FIELDS as $field) {
            $contract[$field] = $this->toStringList($proposal[$field] ?? null);
        }

        // ── New canonical fields ──────────────────────────────────────────────

        $isTestPath = static fn (string $f): bool =>
            str_contains($f, 'Test.php') || str_contains($f, '/tests/') || str_contains($f, '/Tests/');

        $implCount = empty($allowedFiles) ? 0 : count(array_filter($allowedFiles, static function (string $f) use ($isTestPath): bool { return ! $isTestPath($f); }));
        $testCount = empty($allowedFiles) ? 0 : count(array_filter($allowedFiles, $isTestPath));

        $taskFamily = match(true) {
            empty($allowedFiles) => 'unknown',
            $implCount === 0     => 'test_suite',
            $testCount === 0     => 'service_layer',
            default              => 'mixed',
        };

        $hasRunnableAcceptance = false;
        foreach ($acceptance as $a) {
            foreach (self::RUNNABLE_MARKERS as $marker) {
                if (str_contains(strtolower($a), $marker)) { $hasRunnableAcceptance = true; break 2; }
            }
        }

        $contract['task_family']                 = $taskFamily;
        $contract['leverage_reason']             = trim((string) ($proposal['leverage_reason'] ?? ''));
        $contract['expected_capability_delta']   = (float) ($proposal['expected_capability_delta'] ?? 0.0);
        $contract['implementation_file_count']   = $implCount;
        $contract['test_file_count']             = $testCount;
        $contract['runnable_acceptance_present'] = $hasRunnableAcceptance;
        $contract['repair_hints']                = [];

        // ── Semantic violations ───────────────────────────────────────────────

        if (! empty($allowedFiles) && $implCount === 0) {
            $violations[] = ['code' => 'test_only_scope', 'repair_hint' => 'include at least one implementation file in allowed_files'];
        }

        if (! empty($acceptance) && ! $hasRunnableAcceptance) {
            $violations[] = ['code' => 'missing_runnable_acceptance', 'repair_hint' => 'add a runnable acceptance criterion (e.g. phpunit/artisan test command) to acceptance'];
        }

        if (! empty($evidence)) {
            $hasRunnable = false;
            foreach ($evidence as $e) {
                foreach (self::RUNNABLE_MARKERS as $marker) {
                    if (str_contains(strtolower($e), $marker)) { $hasRunnable = true; break 2; }
                }
            }
            if (! $hasRunnable) {
                $violations[] = ['code' => 'missing_runnable_proof', 'repair_hint' => 'add a runnable proof (e.g. phpunit command) to evidence'];
            }
        }

        if ($objective !== '') {
            $low = strtolower($objective);
            foreach (self::PROVIDER_NAMES as $p) {
                if (str_contains($low, $p)) {
                    $violations[] = ['code' => 'provider_dependency', 'repair_hint' => 'remove provider-specific references from objective; make the contract provider-agnostic'];
                    break;
                }
            }
        }

        if ($objective !== '' && strlen($objective) < 20) {
            $violations[] = ['code' => 'generic_objective', 'repair_hint' => 'expand objective to specify the class and behavior to build (min 20 chars)'];
        } elseif ($objective !== '') {
            $low = strtolower($objective);
            foreach (self::GENERIC_PHRASES as $phrase) {
                if (str_contains($low, $phrase)) {
                    $violations[] = ['code' => 'generic_objective', 'repair_hint' => 'expand objective to specify the class and behavior to build (min 20 chars)'];
                    break;
                }
            }
        }

        return [$contract, $missing, $violations];
    }

    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== ''));
    }
}
