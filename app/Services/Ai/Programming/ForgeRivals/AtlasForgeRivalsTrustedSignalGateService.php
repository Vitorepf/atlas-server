<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\JsonFileStore;

/**
 * Atlas Forge Rivals · Trusted Signal Gate.
 *
 * Read-only pre-ledger gate for model-routing intelligence. It answers
 * whether a materialized run has enough local evidence to be recorded into
 * the Provider Performance Ledger as measured evidence. It never writes the
 * ledger, never calls providers, and never changes Atlas Decide topology.
 */
final class AtlasForgeRivalsTrustedSignalGateService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.trusted_signal_gate.v1';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsReplayService $replay,
        private readonly AtlasForgeRivalsEvidenceBundleManifestService $bundle,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function inspect(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return $this->blocked(['run_id_required'], null, 'php artisan atlas:forge:rivals trusted-signal --run-id=<id> --task-category=<cat> --role=<role> --json');
        }

        $paths = $this->paths->paths($runId);
        if (! is_dir($paths['base'])) {
            return $this->blocked(['run_not_found:'.$paths['run_id']], $paths['run_id'], 'php artisan atlas:forge:rivals runs --run-id='.$paths['run_id'].' --json');
        }

        $manifest = $this->readJson($paths['manifest_json']);
        $scorecard = $this->readJson($paths['scorecard_json']);
        $evidencePack = $this->readJson($paths['evidence'].'/evidence_pack.json');
        $taskCategory = $this->firstString($input['task_category'] ?? null, $input['category'] ?? null, $manifest['task_category'] ?? null, $manifest['category'] ?? null);
        $role = $this->firstString($input['role'] ?? null, $manifest['role'] ?? null);
        $mode = strtolower($this->firstString($manifest['mode'] ?? null, $evidencePack['mode_for_evidence'] ?? null, 'unknown'));
        $blockers = [];

        if ($manifest === []) {
            $blockers[] = 'manifest_missing';
        }
        if ($scorecard === []) {
            $blockers[] = 'scorecard_missing';
        }
        if ($evidencePack === []) {
            $blockers[] = 'evidence_pack_missing';
        }
        if ($taskCategory === '') {
            $blockers[] = 'task_category_required_for_ledger_signal';
        }
        if ($role === '') {
            $blockers[] = 'role_required_for_ledger_signal';
        }
        if ($mode === 'local_fake' || $mode === 'fake' || $mode === 'diagnostic') {
            $blockers[] = 'diagnostic_or_local_fake_run_not_trusted_signal';
        }

        $bundleVerification = null;
        if (trim((string) ($input['input'] ?? '')) !== '') {
            $bundleVerification = $this->bundle->verify($input);
            if (($bundleVerification['status'] ?? '') !== 'ok') {
                $blockers[] = 'evidence_bundle_verification_failed';
            }
        }

        $replay = $this->replay->replay([
            'run_id' => $paths['run_id'],
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_FINAL,
        ]);
        if (($replay['replay_passes'] ?? false) !== true) {
            $blockers[] = 'replay_failed_or_missing';
        }
        if (($scorecard['replay_passes'] ?? false) !== true) {
            $blockers[] = 'scorecard_replay_passes_not_true';
        }
        if ($this->evidenceHash($evidencePack) === '') {
            $blockers[] = 'ledger_evidence_hash_not_derivable';
        }
        if ($this->scorePresent($scorecard) === false) {
            $blockers[] = 'scorecard_missing_arm_scores';
        }
        if ($this->stringList($scorecard['hard_failures'] ?? []) !== []) {
            $blockers[] = 'scorecard_has_hard_failures';
        }

        $blockers = array_values(array_unique($blockers));
        $ready = $blockers === [];

        return [
            'status' => $ready ? 'ok' : 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $paths['run_id'],
            'trusted_signal_ready' => $ready,
            'can_feed_provider_performance_ledger' => $ready,
            'can_feed_atlas_decide_advisory_signal' => $ready,
            'score_or_claim_allowed' => false,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'task_category' => $taskCategory !== '' ? $taskCategory : null,
            'role' => $role !== '' ? $role : null,
            'mode' => $mode,
            'evidence_pack_hash' => $this->evidenceHash($evidencePack),
            'replay_passes' => (bool) ($replay['replay_passes'] ?? false),
            'scorecard_replay_passes' => (bool) ($scorecard['replay_passes'] ?? false),
            'bundle_verified' => $bundleVerification === null ? null : (bool) ($bundleVerification['bundle_verified'] ?? false),
            'blockers' => $blockers,
            'atlas_decide_ingestion_contract' => $this->atlasDecideIngestionContract($ready, $paths['run_id'], $taskCategory, $role),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => $ready
                ? 'php artisan atlas:forge:rivals ledger-record --run-id='.$paths['run_id'].' --task-category='.$taskCategory.' --role='.$role.' --json'
                : 'fix trusted-signal blockers before ledger-record',
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function blocked(array $blockers, ?string $runId, string $nextCommand): array
    {
        return [
            'status' => 'blocked',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'trusted_signal_ready' => false,
            'can_feed_provider_performance_ledger' => false,
            'can_feed_atlas_decide_advisory_signal' => false,
            'score_or_claim_allowed' => false,
            'claim_ready' => false,
            'external_claim_allowed' => false,
            'blockers' => $blockers,
            'atlas_decide_ingestion_contract' => $this->atlasDecideIngestionContract(false, $runId, '', ''),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
            'next_command' => $nextCommand,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        return JsonFileStore::readArray($path) ?? [];
    }

    private function firstString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return strtolower(trim($value));
            }
        }

        return '';
    }

    private function evidenceHash(array $evidencePack): string
    {
        $artifacts = (array) ($evidencePack['artifacts'] ?? []);
        $parts = [];
        foreach (['manifest', 'atlas_receipt', 'rival_receipt', 'workspace_hashes'] as $key) {
            $row = $artifacts[$key] ?? null;
            if (is_array($row) && isset($row['sha256']) && is_string($row['sha256'])) {
                $parts[] = $key.':'.$row['sha256'];
            }
        }

        return $parts === [] ? '' : hash('sha256', implode('|', $parts));
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasDecideIngestionContract(bool $ready, ?string $runId, string $taskCategory, string $role): array
    {
        $runId = $runId !== null ? trim($runId) : '';
        $taskCategory = trim($taskCategory);
        $role = trim($role);
        $ledgerCommand = $ready && $runId !== '' && $taskCategory !== '' && $role !== ''
            ? 'php artisan atlas:forge:rivals ledger-record --run-id='.$runId.' --task-category='.$taskCategory.' --role='.$role.' --json'
            : null;

        return [
            'schema_version' => 'atlas.forge.rivals.trusted_signal_atlas_decide_ingestion_contract.v1',
            'status' => $ready ? 'ready_for_ledger_ingestion_only' : 'blocked_before_ledger_ingestion',
            'allowed_effect' => $ready
                ? 'append_measured_evidence_to_provider_performance_ledger'
                : 'none_until_trusted_signal_ready',
            'not_allowed_effects' => [
                'provider_topology_update',
                'automatic_model_routing_change',
                'external_claim',
                'model_preference_from_single_run',
                'score_claim_without_statistical_repeat',
            ],
            'single_run_is_enough_for' => $ready
                ? ['ledger_entry_candidate', 'advisory_signal_input']
                : [],
            'single_run_is_not_enough_for' => [
                'model_preference',
                'external_claim',
                'provider_topology_change',
                'statistical_confidence',
            ],
            'post_ingest_commands' => array_values(array_filter([
                $ledgerCommand,
                'php artisan atlas:forge:rivals decide-learning --json',
                'php artisan atlas:forge:rivals statistical-repeat-dry-run --json',
            ])),
            'requires_after_ingestion' => [
                'ledger_record_success',
                'decide_learning_recheck',
                'statistical_repeat_plan_or_dry_run',
                'repeat_runs_before_policy_review_candidate',
            ],
            'score_or_claim_allowed' => false,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'canonical_phrase' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
    }

    private function scorePresent(array $scorecard): bool
    {
        return is_numeric($scorecard['atlas_score'] ?? null)
            && is_numeric($scorecard['rival_score'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return AiStringListNormalizer::castItemsToStrings($value);
    }
}
