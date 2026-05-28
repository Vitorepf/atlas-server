<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Http\Controllers\AtlasCodeForgeReviewController;
use App\Models\AtlasProject;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Code Forge Review & Completion Gate v1.
 *
 * Emite:
 *  - Review Packet canonico (atlas.code.forge_review_packet.v1)
 *  - Completion Claim canonico (atlas.code.forge_completion_claim.v1)
 *
 * Orquestra approve/reject/rollback contra `AtlasCodeForgeReviewController` reusando
 * promotion/rollback canonicos. Sem provider externo, sem auto-completion.
 *
 * Correlacao OBRIGATORIA com o fast_path_run_id: `latest_forge_live_execution`
 * (ou history) so e considerada quando bate em `history_id`/`run_id`/`evidence_id`
 * com o run vindo de `latest_atlas_code_forge_fast_path_run`/history.
 */
class AtlasCodeForgeReviewCompletionService
{
    public const REVIEW_PACKET_SCHEMA = 'atlas.code.forge_review_packet.v1';
    public const COMPLETION_CLAIM_SCHEMA = 'atlas.code.forge_completion_claim.v1';

    public static function normalizeRunIdInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int,string>
     */
    public static function canonicalContextRefPaths(): array
    {
        return array_values(array_map(
            static fn (array $ref): string => (string) ($ref['path'] ?? ''),
            self::canonicalContextRefDefinitions(),
        ));
    }

    /**
     * @return array<int,array{path:string,kind:string,reason:string}>
     */
    private static function canonicalContextRefDefinitions(): array
    {
        return [
            ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md', 'kind' => 'canonical_doc', 'reason' => 'Doc canonica do Review & Completion Gate v1.'],
            ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md', 'kind' => 'canonical_doc', 'reason' => 'Fast Path v1 (pai do review/completion gate).'],
            ['path' => 'docs/engineering-knowledge-base/atlas-programming-forge-flow.md', 'kind' => 'canonical_doc', 'reason' => 'Forge Flow canonico (page-mae do fluxo pesado de programacao).'],
            ['path' => 'app/Services/Ai/Programming/AtlasCodeForgeReviewCompletionService.php', 'kind' => 'service_implementation', 'reason' => 'Orquestrador canonico do Review & Completion Gate.'],
            ['path' => 'app/Http/Controllers/AtlasCodeForgeReviewCompletionController.php', 'kind' => 'http_controller', 'reason' => 'Endpoints GET/POST review packet e approve/reject/rollback.'],
            ['path' => 'app/Console/Commands/AtlasCodeForgeReviewCommand.php', 'kind' => 'console_command', 'reason' => 'Entrada CLI replayable do review/completion gate.'],
            ['path' => 'tests/Feature/Ai/Programming/AtlasCodeForgeReviewCompletionTest.php', 'kind' => 'test_evidence', 'reason' => 'Suite feature que prova a cadeia ponta-a-ponta.'],
            ['path' => 'tests/Unit/Ai/Programming/AtlasCodeForgeReviewCompletionServiceTest.php', 'kind' => 'test_evidence', 'reason' => 'Testes unitarios focados (run fail-closed, schemas e refs canonicas).'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function packet(AtlasProject $project, string $runId): array
    {
        $normalizedRunId = self::normalizeRunIdInput($runId);
        if ($normalizedRunId === null) {
            return $this->blockedPacket($project, $runId, 'fast_path_run_id_invalid', 'Fast Path run ID invalido.', 400);
        }
        $runId = $normalizedRunId;

        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $run = $this->findRun($metadata, $runId);
        if ($run === null) {
            return $this->blockedPacket($project, $runId, 'fast_path_run_not_found', 'Fast Path run nao encontrado nesta Obra.', 404);
        }
        $runObraId = (string) ($run['obra_id'] ?? '');
        if ($runObraId !== '' && $runObraId !== (string) $project->getKey()) {
            return $this->blockedPacket($project, $runId, 'fast_path_run_obra_mismatch', 'Run nao pertence a esta Obra.', 403);
        }

        $forgeLive = $this->forgeLiveForRun($metadata, $run);
        $review = $this->reviewForRun($metadata, $run);
        $promotion = is_array($review['promotion'] ?? null) ? (array) $review['promotion'] : null;

        $runtimeStatus = (string) ($forgeLive['status'] ?? 'missing');
        $completionAllowedBefore = false;
        $completionAllowedAfter = $this->completionAllowedAfter($forgeLive, $review);
        $evidencePackDigest = (array) ($forgeLive['evidence_pack_digest'] ?? data_get($forgeLive, 'diff_scope.evidence_pack', []));
        $stageTimelineDigest = (array) data_get($forgeLive, 'stage_timeline');
        $changedFiles = array_values((array) data_get($forgeLive, 'diff_scope.files', $run['evidence_refs'] ?? []));
        $taskContract = is_array(data_get($forgeLive, 'task_contract')) ? (array) data_get($forgeLive, 'task_contract') : null;
        $diffScope = is_array(data_get($forgeLive, 'diff_scope')) ? (array) data_get($forgeLive, 'diff_scope') : null;
        $gates = array_values((array) data_get($forgeLive, 'gates', []));
        $rollbackAvailable = $promotion !== null
            && (string) ($promotion['promotion_status'] ?? '') === 'promoted'
            && (string) ($review['rollback_status'] ?? '') !== 'rolled_back';

        $reviewStatus = match (true) {
            ($review['status'] ?? null) === 'approved' && ($review['final_completion_allowed'] ?? false) === true => 'approved',
            ($review['status'] ?? null) === 'rejected' => 'rejected',
            (string) ($review['rollback_status'] ?? '') === 'rolled_back' => 'rolled_back',
            $runtimeStatus === 'passed' && $review === [] => 'pending',
            $runtimeStatus !== 'passed' => 'blocked',
            default => 'pending',
        };

        $blockers = array_values(array_unique(array_filter(array_merge(
            (array) data_get($forgeLive, 'remaining_blockers', []),
            $reviewStatus === 'rejected' ? ['review_rejected'] : [],
            $reviewStatus === 'blocked' ? ['forge_runtime_not_passed'] : [],
            ! $completionAllowedAfter && $reviewStatus !== 'approved' ? [] : [],
        ), static fn (mixed $v): bool => is_string($v) && $v !== '')));

        return [
            'schema_version' => self::REVIEW_PACKET_SCHEMA,
            'review_packet_id' => (string) ($review['review_packet_id'] ?? Str::ulid()),
            'obra_id' => (string) $project->getKey(),
            'fast_path_run_id' => (string) ($run['fast_path_run_id'] ?? $runId),
            'work_item_id' => $run['work_item_id'] ?? null,
            'execution_id' => $run['execution_id'] ?? null,
            'history_id' => $run['history_id'] ?? null,
            'review_status' => $reviewStatus,
            'review_id' => $review['review_id'] ?? null,
            'reviewer_id' => $review['reviewer_id'] ?? null,
            'reviewed_at' => $review['reviewed_at'] ?? null,
            'reason' => $review['comment'] ?? null,
            'runtime_status' => $runtimeStatus,
            'completion_claim_allowed_before_review' => $completionAllowedBefore,
            'completion_claim_allowed_after_review' => $completionAllowedAfter,
            'evidence_pack_digest' => $evidencePackDigest,
            'stage_timeline_digest' => $stageTimelineDigest,
            'changed_files' => $changedFiles,
            'task_contract' => $taskContract,
            'diff_scope' => $diffScope,
            'gates' => $gates,
            'blockers' => $blockers,
            'rollback_available' => $rollbackAvailable,
            'rollback_status' => $review['rollback_status'] ?? null,
            'approval_requires_human' => true,
            'external_provider_call' => false,
            'source_authority' => 'AtlasCodeForgeReviewCompletionService::packet',
            'http_status' => 200,
            'correlation' => [
                'execution_id_match' => $run['execution_id'] !== null
                    && (string) ($forgeLive['execution_id'] ?? data_get($forgeLive, 'run_id', '')) === (string) $run['execution_id'],
                'history_id_match' => $run['history_id'] !== null
                    && (string) ($forgeLive['run_id'] ?? '') === (string) $run['history_id'],
                'evidence_id_match' => isset($run['evidence_id'])
                    && (string) ($forgeLive['evidence_id'] ?? '') === (string) $run['evidence_id'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function completionClaim(AtlasProject $project, string $runId): array
    {
        $packet = $this->packet($project, $runId);
        if (($packet['http_status'] ?? 200) !== 200) {
            return $this->completionClaimFromBlockedPacket($packet);
        }

        $reviewStatus = (string) ($packet['review_status'] ?? 'pending');
        $finalAllowed = $reviewStatus === 'approved'
            && ($packet['completion_claim_allowed_after_review'] ?? false) === true;

        $status = match (true) {
            $finalAllowed && ($packet['rollback_status'] ?? null) === 'rolled_back' => 'rolled_back',
            $finalAllowed => 'completed',
            $reviewStatus === 'rejected' => 'blocked',
            ($packet['runtime_status'] ?? '') === 'passed' => 'allowed',
            default => 'not_allowed',
        };

        return [
            'schema_version' => self::COMPLETION_CLAIM_SCHEMA,
            'obra_id' => $packet['obra_id'],
            'fast_path_run_id' => $packet['fast_path_run_id'],
            'review_packet_id' => $packet['review_packet_id'],
            'review_id' => $packet['review_id'],
            'completion_status' => $status,
            'human_approved' => $reviewStatus === 'approved',
            'approved_by' => $reviewStatus === 'approved' ? ($packet['reviewer_id'] ?? null) : null,
            'approved_at' => $reviewStatus === 'approved' ? ($packet['reviewed_at'] ?? null) : null,
            'runtime_passed' => (string) ($packet['runtime_status'] ?? '') === 'passed',
            'evidence_pack_verified' => ! empty($packet['evidence_pack_digest']),
            'diff_scope_verified' => ! empty($packet['diff_scope']),
            'rollback_state' => $packet['rollback_status'] ?? null,
            'final_completion_allowed' => $finalAllowed,
            'blockers' => array_values((array) ($packet['blockers'] ?? [])),
            'evidence_refs' => array_values((array) ($packet['changed_files'] ?? [])),
            'ledger_event_ids' => array_values((array) data_get($packet, 'evidence_pack_digest.ledger_event_ids', [])),
            'next_action' => match ($status) {
                'completed' => 'completed',
                'allowed' => 'open_human_review',
                'blocked' => 'inspect_rejection',
                'rolled_back' => 'inspect_rollback_evidence',
                default => 'wait_for_runtime',
            },
            'external_provider_call' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function approve(AtlasProject $project, string $runId, array $options): array
    {
        $packet = $this->packet($project, $runId);
        if (($packet['http_status'] ?? 200) !== 200) {
            return ['status' => 'blocked', 'packet' => $packet];
        }
        if ((string) $packet['runtime_status'] !== 'passed') {
            return ['status' => 'blocked', 'blocker' => 'forge_runtime_not_passed', 'packet' => $packet];
        }
        if (empty($packet['evidence_pack_digest'])) {
            return ['status' => 'blocked', 'blocker' => 'evidence_pack_missing', 'packet' => $packet];
        }

        return $this->decide($project, $packet, 'approved', $options);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function reject(AtlasProject $project, string $runId, array $options): array
    {
        $packet = $this->packet($project, $runId);
        if (($packet['http_status'] ?? 200) !== 200) {
            return ['status' => 'blocked', 'packet' => $packet];
        }

        return $this->decide($project, $packet, 'rejected', $options);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function rollback(AtlasProject $project, string $runId, array $options): array
    {
        $packet = $this->packet($project, $runId);
        if (($packet['http_status'] ?? 200) !== 200) {
            return ['status' => 'blocked', 'packet' => $packet];
        }
        if (! ($packet['rollback_available'] ?? false)) {
            return [
                'status' => 'blocked',
                'blocker' => 'rollback_not_available',
                'reason' => 'Nenhuma promotion canonica disponivel para rollback neste run.',
                'packet' => $packet,
            ];
        }

        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $review = $this->reviewForRun($metadata, $this->findRun($metadata, $runId) ?? []);
        $promotionId = (string) data_get($review, 'promotion.promotion_id', '');
        if ($promotionId === '') {
            return ['status' => 'blocked', 'blocker' => 'promotion_id_missing', 'packet' => $packet];
        }

        $request = Request::create('/_review/rollback', 'POST', [
            'comment' => (string) ($options['reason'] ?? 'forge_review_rollback'),
        ]);

        try {
            $response = app(AtlasCodeForgeReviewController::class)->rollback($request, $project, $promotionId);
        } catch (Throwable $e) {
            return ['status' => 'blocked', 'blocker' => 'rollback_threw', 'reason' => $e->getMessage(), 'packet' => $packet];
        }

        $payload = (array) $response->getData(true);
        $status = (string) ($payload['status'] ?? 'blocked');

        $this->rememberDecision($project, $runId, $packet['review_packet_id'], 'rollback', $status, $options);

        return [
            'status' => $status === 'rolled_back' ? 'rolled_back' : 'blocked',
            'rollback' => $payload,
            'packet' => $this->packet($project->refresh(), $runId),
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function decide(AtlasProject $project, array $packet, string $decision, array $options): array
    {
        $reviewer = $this->stringOrNull($options['reviewer'] ?? null) ?? 'atlas-code-local-operator';
        $reason = $this->stringOrNull($options['reason'] ?? null) ?? sprintf('forge_review_%s', $decision);

        $request = Request::create('/_review/store', 'POST', [
            'decision' => $decision,
            'comment' => $reason,
            'history_id' => $packet['history_id'],
        ]);

        try {
            $response = app(AtlasCodeForgeReviewController::class)->store($request, $project);
        } catch (Throwable $e) {
            return ['status' => 'blocked', 'blocker' => 'review_store_threw', 'reason' => $e->getMessage(), 'packet' => $packet];
        }

        $payload = (array) $response->getData(true);
        $reviewArtifact = (array) data_get($payload, 'review', []);
        $reviewArtifact['reviewer_id'] = $reviewer;
        $reviewArtifact['review_packet_id'] = $packet['review_packet_id'];

        // Persist review_packet_id + reviewer no metadata (review controller usa default reviewer_id).
        $this->persistPacketBinding($project, $packet['review_packet_id'], $reviewArtifact, $packet['fast_path_run_id']);
        $this->rememberDecision($project, $packet['fast_path_run_id'], $packet['review_packet_id'], $decision, (string) ($reviewArtifact['status'] ?? 'unknown'), $options);

        $project->refresh();
        $finalPacket = $this->packet($project, $packet['fast_path_run_id']);
        $claim = $this->completionClaim($project, $packet['fast_path_run_id']);

        return [
            'status' => $finalPacket['review_status'] ?? 'unknown',
            'review_response' => $payload,
            'packet' => $finalPacket,
            'completion_claim' => $claim,
        ];
    }

    /**
     * @param  array<string,mixed>  $reviewArtifact
     */
    private function persistPacketBinding(AtlasProject $project, string $packetId, array $reviewArtifact, string $fastPathRunId): void
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $latest = (array) data_get($metadata, 'latest_atlas_code_forge_review', []);
        if ((string) ($latest['review_id'] ?? '') === (string) ($reviewArtifact['review_id'] ?? '')) {
            $latest['review_packet_id'] = $packetId;
            $latest['reviewer_id'] = $reviewArtifact['reviewer_id'];
            $latest['fast_path_run_id'] = $fastPathRunId;
            $metadata['latest_atlas_code_forge_review'] = $latest;
        }

        $metadata['latest_atlas_code_forge_review_packet'] = [
            'review_packet_id' => $packetId,
            'fast_path_run_id' => $fastPathRunId,
            'reviewer_id' => $reviewArtifact['reviewer_id'],
            'review_id' => $reviewArtifact['review_id'] ?? null,
            'persisted_at' => now()->toIso8601String(),
        ];

        $history = collect((array) ($metadata['atlas_code_forge_review_packet_history'] ?? []))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->reject(fn (array $entry): bool => (string) ($entry['review_packet_id'] ?? '') === $packetId)
            ->values()
            ->all();
        array_unshift($history, $metadata['latest_atlas_code_forge_review_packet']);
        $metadata['atlas_code_forge_review_packet_history'] = array_slice($history, 0, 25);

        $project->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function rememberDecision(AtlasProject $project, string $runId, string $packetId, string $decision, string $status, array $options): void
    {
        $project->refresh();
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $reviewer = $this->stringOrNull($options['reviewer'] ?? null) ?? 'atlas-code-local-operator';
        $reason = $this->stringOrNull($options['reason'] ?? null) ?? sprintf('forge_review_%s', $decision);

        $entry = [
            'schema_version' => 'atlas.code.forge_review_decision_audit.v1',
            'fast_path_run_id' => $runId,
            'review_packet_id' => $packetId,
            'decision' => $decision,
            'status' => $status,
            'reviewer' => $reviewer,
            'reason' => $reason,
            'decided_at' => now()->toIso8601String(),
            'decision_hash' => hash('sha256', json_encode([$runId, $packetId, $decision, $reviewer, $reason], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'source_authority' => 'AtlasCodeForgeReviewCompletionService',
            'external_provider_call' => false,
        ];

        $history = collect((array) ($metadata['atlas_code_forge_completion_history'] ?? []))
            ->filter(fn (mixed $e): bool => is_array($e))
            ->values()
            ->all();
        array_unshift($history, $entry);
        $metadata['atlas_code_forge_completion_history'] = array_slice($history, 0, 25);
        $metadata['latest_atlas_code_forge_completion_claim'] = $this->completionClaim($project, $runId);

        $project->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>|null
     */
    private function findRun(array $metadata, string $runId): ?array
    {
        $latest = data_get($metadata, 'latest_atlas_code_forge_fast_path_run');
        if (is_array($latest) && (string) ($latest['fast_path_run_id'] ?? '') === $runId) {
            return $latest;
        }

        foreach ((array) data_get($metadata, 'atlas_code_forge_fast_path_run_history', []) as $entry) {
            if (is_array($entry) && (string) ($entry['fast_path_run_id'] ?? '') === $runId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Correlaciona forge live execution ao run pelo execution_id/history_id/evidence_id.
     *
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function forgeLiveForRun(array $metadata, array $run): array
    {
        $latest = (array) data_get($metadata, 'latest_forge_live_execution', []);
        if ($latest !== [] && $this->correlated($latest, $run)) {
            return $latest;
        }

        foreach ((array) data_get($metadata, 'atlas_code_forge_live_execution_history', []) as $entry) {
            if (is_array($entry) && $this->correlated($entry, $run)) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $run
     */
    private function correlated(array $entry, array $run): bool
    {
        $executionId = (string) ($run['execution_id'] ?? '');
        $historyId = (string) ($run['history_id'] ?? '');
        $entryExec = (string) ($entry['execution_id'] ?? '');
        $entryRun = (string) ($entry['run_id'] ?? '');
        $entryEvidence = (string) ($entry['evidence_id'] ?? '');

        if ($executionId !== '' && ($entryExec === $executionId || $entryRun === $executionId)) {
            return true;
        }
        if ($historyId !== '' && ($entryRun === $historyId || $entryExec === $historyId)) {
            return true;
        }
        if (isset($run['evidence_id']) && $entryEvidence === (string) $run['evidence_id']) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    private function reviewForRun(array $metadata, array $run): array
    {
        $latest = (array) data_get($metadata, 'latest_atlas_code_forge_review', []);
        if ($latest !== [] && (string) ($latest['fast_path_run_id'] ?? data_get($latest, 'run_id')) === (string) ($run['fast_path_run_id'] ?? '')) {
            return $latest;
        }
        $latestHistoryId = (string) ($run['history_id'] ?? '');
        if ($latest !== [] && $latestHistoryId !== '' && (string) ($latest['history_id'] ?? '') === $latestHistoryId) {
            return $latest;
        }

        foreach ((array) data_get($metadata, 'atlas_code_forge_review_history', []) as $entry) {
            if (is_array($entry) && (string) ($entry['fast_path_run_id'] ?? data_get($entry, 'run_id')) === (string) ($run['fast_path_run_id'] ?? '')) {
                return $entry;
            }
            if (is_array($entry) && $latestHistoryId !== '' && (string) ($entry['history_id'] ?? '') === $latestHistoryId) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $forgeLive
     * @param  array<string,mixed>  $review
     */
    private function completionAllowedAfter(array $forgeLive, array $review): bool
    {
        $runtimeAllowed = (bool) data_get($forgeLive, 'diff_scope.completion_gate.completion_claim_allowed', false)
            || (string) ($forgeLive['status'] ?? '') === 'passed';
        $reviewApproved = ($review['status'] ?? null) === 'approved'
            && (bool) ($review['final_completion_allowed'] ?? false);

        return $runtimeAllowed && $reviewApproved;
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedPacket(AtlasProject $project, string $runId, string $blocker, string $reason, int $httpStatus): array
    {
        return [
            'schema_version' => self::REVIEW_PACKET_SCHEMA,
            'review_packet_id' => (string) Str::ulid(),
            'obra_id' => (string) $project->getKey(),
            'fast_path_run_id' => $runId,
            'review_status' => 'blocked',
            'blocker' => $blocker,
            'reason' => $reason,
            'completion_claim_allowed_before_review' => false,
            'completion_claim_allowed_after_review' => false,
            'evidence_pack_digest' => [],
            'stage_timeline_digest' => [],
            'changed_files' => [],
            'gates' => [],
            'blockers' => [$blocker],
            'rollback_available' => false,
            'approval_requires_human' => true,
            'external_provider_call' => false,
            'http_status' => $httpStatus,
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function completionClaimFromBlockedPacket(array $packet): array
    {
        return [
            'schema_version' => self::COMPLETION_CLAIM_SCHEMA,
            'obra_id' => $packet['obra_id'] ?? null,
            'fast_path_run_id' => $packet['fast_path_run_id'] ?? null,
            'review_packet_id' => $packet['review_packet_id'] ?? null,
            'review_id' => null,
            'completion_status' => 'blocked',
            'human_approved' => false,
            'approved_by' => null,
            'approved_at' => null,
            'runtime_passed' => false,
            'evidence_pack_verified' => false,
            'diff_scope_verified' => false,
            'rollback_state' => null,
            'final_completion_allowed' => false,
            'blockers' => [(string) ($packet['blocker'] ?? 'unknown')],
            'evidence_refs' => [],
            'ledger_event_ids' => [],
            'next_action' => 'inspect_blocker',
            'external_provider_call' => false,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
