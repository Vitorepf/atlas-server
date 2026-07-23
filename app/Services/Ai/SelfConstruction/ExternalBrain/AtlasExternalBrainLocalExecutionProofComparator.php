<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure comparator that compares local execution proof across clients to detect
 * fake-green success and inconsistent gate output.
 *
 * Verdicts:
 *   - consistent: all clients agree on test output and gate output
 *   - mismatched_test_output: clients disagree on test results
 *   - missing_gate_output: some clients are missing gate output
 *   - fake_green: a client claims success but test output disagrees
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainLocalExecutionProofComparator
{
    public const SCHEMA = 'atlas.external_brain.local_execution_proof_comparator.v1';

    public const VERDICT_CONSISTENT = 'consistent';
    public const VERDICT_MISMATCHED_TEST = 'mismatched_test_output';
    public const VERDICT_MISSING_GATE = 'missing_gate_output';
    public const VERDICT_FAKE_GREEN = 'fake_green';

    /**
     * @param  array<int, array<string, mixed>>  $clientProofs
     * @return array<string, mixed>
     */
    public function compare(array $clientProofs): array
    {
        if (count($clientProofs) < 2) {
            return [
                'schema_version' => self::SCHEMA,
                'verdict' => self::VERDICT_CONSISTENT,
                'reasons' => ['insufficient_clients_for_comparison'],
                'client_count' => count($clientProofs),
            ];
        }

        $testOutputs = [];
        $gateOutputs = [];
        $outcomes = [];

        foreach ($clientProofs as $proof) {
            if (! is_array($proof)) {
                continue;
            }
            $clientId = (string) ($proof['client_id'] ?? '');
            $testOutput = (string) ($proof['test_output'] ?? '');
            $gateOutput = (string) ($proof['gate_output'] ?? '');
            $outcome = strtolower(trim((string) ($proof['outcome'] ?? '')));

            $testOutputs[$clientId] = $testOutput;
            $gateOutputs[$clientId] = $gateOutput;
            $outcomes[$clientId] = $outcome;
        }

        $uniqueTestOutputs = array_unique(array_values($testOutputs));
        $uniqueGateOutputs = array_filter(array_values($gateOutputs), static fn (string $v): bool => $v !== '');
        $hasMissingGate = count($gateOutputs) !== count($uniqueGateOutputs);

        // Check for fake green: client claims success but test output is empty or failed.
        $fakeGreenClients = [];
        foreach ($outcomes as $clientId => $outcome) {
            if ($outcome === 'success' || $outcome === 'completed') {
                $testOutput = $testOutputs[$clientId] ?? '';
                if ($testOutput === '' || str_contains(strtolower($testOutput), 'fail')) {
                    $fakeGreenClients[] = $clientId;
                }
            }
        }

        $verdict = match (true) {
            $fakeGreenClients !== [] => self::VERDICT_FAKE_GREEN,
            $hasMissingGate => self::VERDICT_MISSING_GATE,
            count($uniqueTestOutputs) > 1 => self::VERDICT_MISMATCHED_TEST,
            default => self::VERDICT_CONSISTENT,
        };

        $reasons = [];
        if ($verdict === self::VERDICT_FAKE_GREEN) {
            $reasons[] = 'fake_green_clients:'.implode(',', $fakeGreenClients);
        }
        if ($verdict === self::VERDICT_MISSING_GATE) {
            $reasons[] = 'missing_gate_output_for_some_clients';
        }
        if ($verdict === self::VERDICT_MISMATCHED_TEST) {
            $reasons[] = 'test_output_mismatch:'.count($uniqueTestOutputs).'_distinct_outputs';
        }
        if ($verdict === self::VERDICT_CONSISTENT) {
            $reasons[] = 'all_clients_agree';
        }

        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'reasons' => $reasons,
            'client_count' => count($clientProofs),
            'fake_green_clients' => $fakeGreenClients,
        ];
    }
}
