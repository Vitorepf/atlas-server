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
        'client_class',
    ];

    private const CLIENT_CLASSES = ['local', 'subscription_ui', 'internal_runtime'];

    // AC3: redact any key at any depth of provider_specific_fields whose name looks like a raw
    // secret/transcript — the bounded top-level keys themselves stay visible (a validator needs
    // to know a provider sent extra fields), only sensitive-looking nested values are stripped.
    private const SENSITIVE_KEY_SUBSTRINGS = [
        'secret', 'password', 'token', 'api_key', 'apikey', 'raw_prompt',
        'credential', 'authorization', 'provider_trace',
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

        $providerSpecificRaw = array_diff_key($raw, array_flip(self::CANONICAL_INPUT_FIELDS));
        [$providerSpecific, $redactedProviderFields] = $this->redactSensitive($providerSpecificRaw);

        $clientClassRaw = strtolower(trim((string) ($raw['client_class'] ?? '')));
        $clientClass = in_array($clientClassRaw, self::CLIENT_CLASSES, true) ? $clientClassRaw : 'unknown';

        [$repairableFailureReasons, $proofHints] = $verifiedSuccess
            ? [[], []]
            : $this->repairGuidance($claimedSuccess, $testsReported, $evidenceRefs, $raw);

        return [
            'role' => (string) ($raw['role'] ?? 'muscle'),
            'provider_id' => (string) ($raw['provider_id'] ?? ''),
            'model_id' => (string) ($raw['model_id'] ?? ''),
            'client_class' => $clientClass,
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
            'repairable_failure' => ! $verifiedSuccess,
            'repairable_failure_reasons' => $repairableFailureReasons,
            'proof_hints' => $proofHints,
            'provider_specific_fields' => $providerSpecific,
            'redacted_provider_fields' => $redactedProviderFields,
        ];
    }

    /**
     * AC2: never leaves a weak/malformed output with just a status — names concretely what's
     * missing and what to do about it, so a repair loop targets an actual field.
     *
     * @param  list<string>  $testsReported
     * @param  list<string>  $evidenceRefs
     * @param  array<string,mixed>  $raw
     * @return array{0:list<string>,1:list<string>}
     */
    private function repairGuidance(bool $claimedSuccess, array $testsReported, array $evidenceRefs, array $raw): array
    {
        $reasons = [];
        $hints = [];

        if (! $claimedSuccess) {
            $reasons[] = 'provider_did_not_claim_success';
            $hints[] = 'have_the_provider_explicitly_report_claimed_success_true_with_proof';
        }
        if ($testsReported === []) {
            $reasons[] = 'missing_tests_reported';
            $hints[] = 'attach_a_runnable_test_command_to_tests_reported';
        }
        if ($evidenceRefs === []) {
            $reasons[] = 'missing_evidence_refs';
            $hints[] = 'attach_concrete_evidence_refs_such_as_test_output_or_commit_hash';
        }
        if (array_key_exists('changed_files', $raw) && $raw['changed_files'] !== null && ! is_array($raw['changed_files'])) {
            $reasons[] = 'malformed_changed_files_field';
            $hints[] = 'resubmit_changed_files_as_a_list_of_file_paths';
        }

        if ($reasons === []) {
            return [[], []];
        }

        return [$reasons, $hints];
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    private function redactSensitive(array $value): array
    {
        $redactedKeys = [];
        $clean = $this->redactSensitiveRecursive($value, $redactedKeys);

        sort($redactedKeys);

        return [$clean, $redactedKeys];
    }

    /**
     * @param  array<string,mixed>  $value
     * @param  list<string>  $redactedKeys
     * @return array<string,mixed>
     */
    private function redactSensitiveRecursive(array $value, array &$redactedKeys): array
    {
        $clean = [];
        foreach ($value as $key => $item) {
            $keyLower = strtolower((string) $key);
            $isSensitive = false;
            foreach (self::SENSITIVE_KEY_SUBSTRINGS as $needle) {
                if (str_contains($keyLower, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                $redactedKeys[] = (string) $key;

                continue;
            }
            $clean[$key] = is_array($item) ? $this->redactSensitiveRecursive($item, $redactedKeys) : $item;
        }

        return $clean;
    }

    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $scalars = array_filter($value, static fn (mixed $v): bool => is_scalar($v));
        return array_values(array_filter(array_map('strval', $scalars), static fn (string $s): bool => $s !== ''));
    }
}
