<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\QualityFoundry;

use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;

/**
 * Composes the four mode manifests from runtime receipts.
 *
 * Configuration intent is deliberately not accepted as evidence. This is a
 * read-only compatibility surface: it does not invoke providers, mutate a
 * workspace, or authorize a release.
 */
final class QualityFoundryModeReadinessManifestService
{
    public const SCHEMA = 'atlas.quality_foundry.mode_readiness_manifest.v1';

    /** @var list<string> */
    private const MODES = ['kernel', 'dev', 'forge', 'autonomos'];

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function build(array $input): array
    {
        $manifests = [];
        $blockers = [];

        foreach (self::MODES as $mode) {
            $receipt = is_array($input['modes'][$mode] ?? null) ? $input['modes'][$mode] : [];
            $modeBlockers = $this->modeBlockers($receipt);
            foreach ($modeBlockers as $blocker) {
                $blockers[] = $mode.':'.$blocker;
            }
            $manifests[$mode] = [
                'status' => $modeBlockers === [] ? 'ready' : 'blocked',
                'source' => $receipt['source'] ?? null,
                'receipt_hashes' => array_values(array_map('strval', (array) ($receipt['receipt_hashes'] ?? []))),
                'test_refs' => $this->testRefs($receipt),
                'execution' => [
                    'command' => array_values(array_map('strval', (array) ($receipt['command'] ?? []))),
                    'exit_code' => isset($receipt['exit_code']) ? (int) $receipt['exit_code'] : null,
                    'output_hash' => isset($receipt['output_hash']) ? (string) $receipt['output_hash'] : null,
                    'duration_ms' => isset($receipt['duration_ms']) ? (int) $receipt['duration_ms'] : null,
                ],
                'evidence' => is_array($receipt['evidence'] ?? null) ? $receipt['evidence'] : [],
                'blockers' => $modeBlockers,
                'claim_eligible' => false,
            ];
        }

        $idempotency = is_array($input['idempotency'] ?? null) ? $input['idempotency'] : [];
        if ((int) ($idempotency['provider_invocations'] ?? 0) !== 1) {
            $blockers[] = 'provider_invocation_not_once';
        }
        if ((int) ($idempotency['mutations'] ?? 0) !== 1) {
            $blockers[] = 'mutation_not_once';
        }

        $shadow = is_array($input['shadow'] ?? null) ? $input['shadow'] : [];
        if (($shadow['replay_only'] ?? false) !== true) {
            $blockers[] = 'shadow_not_replay_only';
        }
        if (($shadow['mutation_allowed'] ?? true) === true) {
            $blockers[] = 'shadow_mutation_allowed';
        }

        $blockers = array_values(array_unique($blockers));
        $readyModes = count(array_filter($manifests, static fn (array $manifest): bool => $manifest['status'] === 'ready'));
        $ready = $blockers === [] && $readyModes === count(self::MODES) && ($input['mode_parity'] ?? false) === true;
        if (($input['mode_parity'] ?? false) !== true) {
            $blockers[] = 'mode_parity_missing';
        }

        $payload = [
            'schema' => self::SCHEMA,
            'status' => $ready ? 'ready' : 'blocked',
            'completion_allowed' => $ready,
            'required_modes' => self::MODES,
            'manifests' => $manifests,
            'blockers' => array_values(array_unique($blockers)),
            'summary' => [
                'required_modes' => count(self::MODES),
                'ready_modes' => $readyModes,
                'blocked_modes' => count(self::MODES) - $readyModes,
            ],
            'shadow' => [
                'replay_only' => ($shadow['replay_only'] ?? false) === true,
                'mutation_allowed' => ($shadow['mutation_allowed'] ?? true) === true,
            ],
            'parity_evidence' => is_array($input['mode_parity_evidence'] ?? null) ? $input['mode_parity_evidence'] : null,
            'claim_eligible' => false,
            'comparative_claims_allowed' => false,
        ];
        $payload['readiness_hash'] = CanonicalKernelPayload::hash($payload);

        return $payload;
    }

    /** @param array<string,mixed> $receipt @return list<string> */
    private function modeBlockers(array $receipt): array
    {
        $blockers = [];
        if (($receipt['source'] ?? null) !== 'live_receipt') {
            $blockers[] = 'live_receipt_source_required';
        }
        if (! is_array($receipt['receipt_hashes'] ?? null) || $receipt['receipt_hashes'] === []) {
            $blockers[] = 'live_receipts_missing';
        }
        $testRefs = $this->testRefs($receipt);
        if ($testRefs === []) {
            $blockers[] = 'test_refs_missing';
        }
        foreach ($testRefs as $testRef) {
            $path = (string) ($testRef['path'] ?? '');
            $expectedHash = (string) ($testRef['sha256'] ?? '');
            $absolute = $path !== '' ? base_path($path) : '';
            if ($path === '' || ! is_file($absolute)) {
                $blockers[] = 'test_ref_missing';
                continue;
            }
            if ($expectedHash === '' || ! hash_equals($expectedHash, (string) hash_file('sha256', $absolute))) {
                $blockers[] = 'test_ref_hash_mismatch';
            }
        }
        foreach ([
            'kernel_routed' => 'kernel_route_missing',
            'rollback_exercised' => 'rollback_not_exercised',
            'outcome_writer_active' => 'outcome_writer_inactive',
        ] as $field => $reason) {
            if (($receipt[$field] ?? false) !== true) {
                $blockers[] = $reason;
            }
        }
        if ((int) ($receipt['coverage_percent'] ?? 0) !== 100) {
            $blockers[] = 'coverage_not_complete';
        }

        return $blockers;
    }

    /** @return list<array{path:string,sha256:string}> */
    private function testRefs(array $receipt): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $ref): ?array => is_array($ref) && isset($ref['path'], $ref['sha256'])
                ? ['path' => (string) $ref['path'], 'sha256' => (string) $ref['sha256']]
                : null,
            (array) ($receipt['test_refs'] ?? []),
        )));
    }
}
