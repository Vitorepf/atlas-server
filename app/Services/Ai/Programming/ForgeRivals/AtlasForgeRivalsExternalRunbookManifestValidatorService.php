<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Validates a plan-only external execution runbook before any provider spend.
 */
final class AtlasForgeRivalsExternalRunbookManifestValidatorService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.external_runbook_manifest_validation.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function validate(array $input): array
    {
        $path = trim((string) ($input['plan_manifest'] ?? $input['input'] ?? ''));
        if ($path === '') {
            return $this->blocked(['plan_manifest_required']);
        }

        if (! is_file($path)) {
            return $this->blocked(['plan_manifest_not_found:'.$path], ['plan_manifest_path' => $path]);
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return $this->blocked(['plan_manifest_empty:'.$path], ['plan_manifest_path' => $path]);
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return $this->blocked(['plan_manifest_invalid_json:'.$path], ['plan_manifest_path' => $path]);
        }

        $blockers = $this->validateManifest($manifest);
        $status = $blockers === [] ? 'ok' : 'blocked';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'validation_status' => $status === 'ok'
                ? 'ready_for_human_review_before_real_provider_confirmation'
                : 'blocked_before_any_provider_execution',
            'plan_manifest_path' => $path,
            'manifest_schema_version' => $manifest['schema_version'] ?? null,
            'plan_fingerprint' => $manifest['plan_fingerprint'] ?? null,
            'computed_plan_fingerprint' => $this->fingerprint($manifest),
            'case_set' => $manifest['case_set'] ?? null,
            'missing_bucket_count' => count(array_filter(
                (array) ($manifest['missing_buckets'] ?? []),
                static fn (mixed $row): bool => is_array($row),
            )),
            'model_gap_status' => $manifest['model_gap_summary']['status'] ?? null,
            'strong_claim_gate_status' => $manifest['strong_claim_gate']['status'] ?? null,
            'required_confirmations_before_any_real_command' => $manifest['required_confirmations_before_any_real_command'] ?? [],
            'blockers' => $blockers,
            'real_execution_allowed_by_this_validation' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => $status === 'ok'
                ? 'operator may review cost and run real provider commands only with all explicit confirmations'
                : 'regenerate external-execution-plan and revalidate before any provider execution',
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return list<string>
     */
    private function validateManifest(array $manifest): array
    {
        $blockers = [];
        if (($manifest['schema_version'] ?? null) !== 'atlas.forge.rivals.external_execution_runbook_manifest.v1') {
            $blockers[] = 'plan_manifest_schema_mismatch';
        }
        if (($manifest['status'] ?? null) !== 'plan_only_requires_operator_review') {
            $blockers[] = 'plan_manifest_not_plan_only';
        }
        if (($manifest['plan_fingerprint'] ?? null) !== $this->fingerprint($manifest)) {
            $blockers[] = 'plan_manifest_fingerprint_mismatch';
        }
        if (($manifest['external_provider_call'] ?? true) !== false) {
            $blockers[] = 'plan_manifest_must_not_mark_provider_called';
        }
        if (($manifest['provider_tokens_spent'] ?? true) !== false) {
            $blockers[] = 'plan_manifest_must_not_mark_tokens_spent';
        }
        if (($manifest['advisory_only'] ?? false) !== true) {
            $blockers[] = 'plan_manifest_must_be_advisory_only';
        }
        if (($manifest['should_update_provider_topology'] ?? true) !== false) {
            $blockers[] = 'plan_manifest_must_not_update_provider_topology';
        }
        if (($manifest['owner_of_model_routing'] ?? null) !== 'atlas_decide') {
            $blockers[] = 'plan_manifest_model_routing_owner_must_be_atlas_decide';
        }
        if (($manifest['routing_effect'] ?? null) !== 'none') {
            $blockers[] = 'plan_manifest_routing_effect_must_be_none';
        }
        if (($manifest['strong_claim_gate']['status'] ?? null) !== 'blocked_until_real_reproducible_evidence_complete') {
            $blockers[] = 'plan_manifest_strong_claim_gate_must_block';
        }

        $requiredConfirmations = [
            'confirm_runbook_reviewed',
            'confirm_provider_cost',
            'confirm_real_provider_call',
        ];
        foreach ($requiredConfirmations as $confirmation) {
            if (! in_array($confirmation, (array) ($manifest['required_confirmations_before_any_real_command'] ?? []), true)) {
                $blockers[] = 'plan_manifest_missing_required_confirmation:'.$confirmation;
            }
        }

        $missingBuckets = array_values(array_filter(
            (array) ($manifest['missing_buckets'] ?? []),
            static fn (mixed $row): bool => is_array($row),
        ));
        if ($missingBuckets === []) {
            $blockers[] = 'plan_manifest_missing_buckets_required_for_external_gap_review';
        }

        foreach ($missingBuckets as $index => $bucket) {
            $dryRun = (string) ($bucket['dry_run_command'] ?? '');
            $realTemplate = (string) ($bucket['real_execution_command_template'] ?? '');
            if ($dryRun === '' || ! str_contains($dryRun, '--dry-run')) {
                $blockers[] = 'missing_bucket_dry_run_command_invalid:'.$index;
            }
            foreach ($requiredConfirmations as $confirmation) {
                $flag = '--'.str_replace('_', '-', preg_replace('/^confirm_/', 'confirm-', $confirmation) ?? $confirmation);
                if ($realTemplate === '' || ! str_contains($realTemplate, $flag)) {
                    $blockers[] = 'missing_bucket_real_command_missing_confirmation:'.$index.':'.$confirmation;
                }
            }
        }

        if (($manifest['model_gap_summary']['schema_version'] ?? null) !== 'atlas.forge.rivals.external_execution_model_gap_summary.v1') {
            $blockers[] = 'plan_manifest_model_gap_summary_missing_or_invalid';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function blocked(array $blockers, array $extra = []): array
    {
        return array_replace([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'validation_status' => 'blocked_before_any_provider_execution',
            'blockers' => $blockers,
            'real_execution_allowed_by_this_validation' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => 'provide --plan-manifest=<external_execution_runbook_manifest.v1.json>',
        ], $extra);
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function fingerprint(array $manifest): string
    {
        unset($manifest['plan_fingerprint']);

        return hash('sha256', $this->stableJson($manifest));
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $value
     */
    private function stableJson(array $value): string
    {
        $normalized = $this->ksortRecursive($value);

        return (string) json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function ksortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->ksortRecursive($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->ksortRecursive($item), $value);
    }
}
