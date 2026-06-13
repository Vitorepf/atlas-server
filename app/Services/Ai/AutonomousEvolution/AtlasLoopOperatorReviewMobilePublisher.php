<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * L5-12 · The parked review queue becomes a human interface.
 *
 * L4-7 built the governed operator-review queue ({@see AtlasLoopOperatorReviewQueueService}).
 * The mobile approve/reject handler exists ({@see \App\Services\Ai\Mobile\InboxActionRegistry}).
 * This publisher is the MISSING SPINE between them: it reads the governed queue and emits
 * each parked proposal as a mobile inbox item carrying the diff and the provider-safe
 * review actions, so the operator's phone actually shows "Aprovar / Rejeitar".
 *
 * HARD INVARIANTS preserved:
 *  - never-merge: this publisher writes ONLY an inbox item. The merge still happens inside
 *    {@see AtlasLoopAutoMergeService} after a fresh frozen-judge re-proof. No bypass.
 *  - no code action ids: it emits only `loop_operator_review_approve|reject`. The
 *    ProposalInboxEmitter sanitizer would strip any `commit|merge|apply_patch` id anyway.
 *  - read-only over the queue: it does not mutate proposal state; it surfaces what the
 *    governed queue already considers parked.
 *  - idempotent: dedupe key is bound to the proposal hash, so a 24/7 schedule re-publishing
 *    the same parked proposal returns the existing open inbox item, never a duplicate.
 */
final class AtlasLoopOperatorReviewMobilePublisher
{
    public const SCHEMA_VERSION = 'atlas.loop.operator_review.mobile_publisher.v1';

    public function __construct(
        private readonly AtlasLoopOperatorReviewQueueService $queue,
        private readonly ProposalInboxEmitter $emitter,
    ) {}

    /**
     * Publish parked Loop proposals to the mobile inbox as review items.
     *
     * @return array<string,mixed>
     */
    public function publish(int $limit = 10): array
    {
        if (DatabaseTableAvailability::missing(['ai_context_bundles', 'ai_inbox_items']) !== []) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'inbox_tables_missing',
                'published' => [],
                'published_count' => 0,
                'queue_count' => 0,
            ];
        }

        $queue = $this->queue->queue($limit);
        if (($queue['status'] ?? null) === 'table_missing') {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'queue_table_missing',
                'published' => [],
                'published_count' => 0,
                'queue_count' => 0,
            ];
        }

        $items = is_array($queue['items'] ?? null) ? $queue['items'] : [];
        $published = [];
        foreach ($items as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $record = $this->publishOne($entry);
            if ($record !== null) {
                $published[] = $record;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'published' => $published,
            'published_count' => count($published),
            'queue_count' => (int) ($queue['count'] ?? count($items)),
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>|null
     */
    private function publishOne(array $entry): ?array
    {
        $proposalId = $this->str($entry['id'] ?? null);
        $proposalHash = $this->str($entry['proposal_hash'] ?? null);
        $ref = $proposalId ?? $proposalHash;
        if ($ref === null) {
            return null;
        }

        $targetPath = $this->str($entry['target_path'] ?? null) ?? 'unknown_target';
        $objective = $this->str($entry['objective'] ?? null) ?? 'Revisar proposta parked do Loop.';
        $reason = $this->str($entry['reason'] ?? null) ?? 'operator_review';
        $reviewStatus = $this->str($entry['review_status'] ?? null) ?? 'parked_for_operator_review';
        $diffExcerpt = $this->str($entry['diff_excerpt'] ?? null) ?? '';
        $basePath = $this->resolveBasePath($proposalId, $proposalHash);

        $item = $this->emitter->emit([
            'title' => 'Revisar proposta parked do Loop: '.$targetPath,
            'finding' => 'Uma proposta certificada esta parqueada na fila governada aguardando decisao do operador.',
            'problem' => 'Motivo do park: '.$reason.' (status: '.$reviewStatus.'). Objetivo: '.$objective,
            'solution' => 'Aprovar (re-prova o contrato congelado e mergeia no escopo governado) ou Rejeitar (quarentena o alvo).',
            'best_solution_rationale' => 'A decisao roda pela mesma fila governada do CLI; nada commita/mergeia sem re-prova do juiz congelado.',
            'worth_it' => 'Fecha o ciclo telefone -> inbox -> fila governada sem segunda UI e sem bypass do never-merge.',
            'alternatives' => ['Decidir pelo CLI atlas:loop:operator-review.', 'Discutir com o Atlas antes de decidir.'],
            'category' => 'loop_operator_review',
            'source_type' => 'atlas_loop_proposal',
            'source_id' => $ref,
            'dedupe_key' => 'loop-operator-review:'.($proposalHash ?? $ref),
            'confidence' => 0.7,
            'diff_refs' => $diffExcerpt !== ''
                ? [['type' => 'loop_proposal_diff', 'path' => $targetPath, 'excerpt' => $diffExcerpt]]
                : [['type' => 'loop_proposal_diff', 'path' => $targetPath]],
            'source_refs' => [['type' => 'atlas_loop_proposal', 'id' => $ref]],
            'payload' => [
                'loop_operator_review' => [
                    'schema_version' => 'atlas.mobile.loop_operator_review.v1',
                    'proposal_ref' => $ref,
                    'proposal_hash' => $proposalHash,
                    'review_status' => $reviewStatus,
                    'reason' => $reason,
                    'target_path' => $targetPath,
                    'base_path' => $basePath,
                ],
                // Provider-safe claim policy: this item only OPENS a review surface.
                'policy' => [
                    'auto_commit' => false,
                    'auto_merge' => false,
                    'requires_operator_review' => true,
                    'requires_tests_passed' => true,
                ],
            ],
            'available_actions' => [
                ['id' => 'loop_operator_review_approve', 'label' => 'Aprovar', 'style' => 'primary'],
                ['id' => 'loop_operator_review_reject', 'label' => 'Rejeitar', 'style' => 'destructive'],
                ['id' => 'review_patch', 'label' => 'Ver diff', 'style' => 'default'],
            ],
        ]);

        if ($item === null) {
            return null;
        }

        return [
            'inbox_item_id' => (string) $item->getKey(),
            'proposal_ref' => $ref,
            'proposal_hash' => $proposalHash,
            'target_path' => $targetPath,
            'review_status' => $reviewStatus,
            'dedupe_key' => (string) $item->dedupe_key,
        ];
    }

    private function resolveBasePath(?string $proposalId, ?string $proposalHash): ?string
    {
        $proposal = null;
        if ($proposalId !== null) {
            $proposal = AtlasLoopProposal::query()->whereKey($proposalId)->first();
        }
        if ($proposal === null && $proposalHash !== null) {
            $proposal = AtlasLoopProposal::query()->where('proposal_hash', $proposalHash)->first();
        }
        if (! $proposal instanceof AtlasLoopProposal) {
            return null;
        }

        $base = $this->str($proposal->campaign?->base_workspace);

        return ($base !== null && is_dir($base.'/.git')) ? $base : null;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
