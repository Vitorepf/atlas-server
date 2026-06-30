<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure normalizer. Converts raw provider-pool outputs (Cursor subscription
 * clients, Claude, Codex, Hermes, or any local provider) into ONE Atlas
 * muscle/brain result contract before gates or outcome learning consume them.
 *
 * Canonical output fields: role, provider_id, model_id, task_packet_id,
 * changed_files, proposed_patch_ref, tests_reported, evidence_refs,
 * cost_summary, status, claimed_success, verified_success, uncertainty.
 *
 * claimed_success vs verified_success (AC2):
 *   claimed_success    = the provider's own success claim.
 *   verified_success   = claimed_success AND there is runnable evidence
 *                         (both tests_reported and evidence_refs are non-empty).
 *   status             = 'verified_success' | 'pending_verification' | 'failed'.
 *   An output without runnable evidence is NEVER reported as success — it is
 *   pending_verification at best, regardless of what the provider claimed.
 *
 * provider_specific_fields (AC3): any input key that is not part of the
 * canonical contract (including a raw 'metadata' blob) is collected
 * UNCHANGED under the bounded 'provider_specific_fields' key. It is never
 * merged back into the canonical fields, so a provider cannot use it to
 * override status, allowed/changed_files, evidence, or cost boundaries.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainProviderPoolOutputNormalizer
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_output_normalizer.v1';

    private const CANONICAL_INPUT_FIELDS = [
        'role',
        'provider_id',
        'model_id',
        'task_packet_id',
        'changed_files',
        'proposed_patch_ref',
        'tests_reported',
        'evidence_refs',
        'cost_summary',
        'claimed_success',
        'status',
        'uncertainty',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function normalize(array $facts): array
    {
        $outputs = is_array($facts['outputs'] ?? null) ? $facts['outputs'] : [];

        $normalized = [];
        foreach ($outputs as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $normalized[] = $this->normalizeOne($raw);
        }

        return [
            'schema_version' => self::SCHEMA,
            'normalized_outputs' => $normalized,
            'output_count' => count($normalized),
            'pending_verification_count' => count(array_filter($normalized, static fn (array $o): bool => $o['status'] === 'pending_verification')),
            'verified_success_count' => count(array_filter($normalized, static fn (array $o): bool => $o['status'] === 'verified_success')),
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function normalizeOne(array $raw): array
    {
        $changedFiles = $this->toStringList($raw['changed_files'] ?? null);
        $testsReported = $this->toStringList($raw['tests_reported'] ?? null);
        $evidenceRefs = $this->toStringList($raw['evidence_refs'] ?? null);
        $costSummary = is_array($raw['cost_summary'] ?? null) ? $raw['cost_summary'] : [];
        $statusClaim = strtolower(trim((string) ($raw['status'] ?? '')));
        $claimedSuccess = array_key_exists('claimed_success', $raw)
            ? (bool) $raw['claimed_success']
            : $statusClaim === 'success';

        $hasRunnableEvidence = $testsReported !== [] && $evidenceRefs !== [];
        $verifiedSuccess = $claimedSuccess && $hasRunnableEvidence;

        $status = match (true) {
            $verifiedSuccess => 'verified_success',
            $claimedSuccess => 'pending_verification',
            default => 'failed',
        };

        $providerSpecific = array_diff_key($raw, array_flip(self::CANONICAL_INPUT_FIELDS));

        return [
            'role' => (string) ($raw['role'] ?? 'muscle'),
            'provider_id' => (string) ($raw['provider_id'] ?? ''),
            'model_id' => (string) ($raw['model_id'] ?? ''),
            'task_packet_id' => (string) ($raw['task_packet_id'] ?? ''),
            'changed_files' => $changedFiles,
            'proposed_patch_ref' => (string) ($raw['proposed_patch_ref'] ?? ''),
            'tests_reported' => $testsReported,
            'evidence_refs' => $evidenceRefs,
            'cost_summary' => $costSummary,
            'status' => $status,
            'claimed_success' => $claimedSuccess,
            'verified_success' => $verifiedSuccess,
            'uncertainty' => (float) ($raw['uncertainty'] ?? ($verifiedSuccess ? 0.0 : 1.0)),
            'provider_specific_fields' => $providerSpecific,
        ];
    }

    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== ''));
    }
}
