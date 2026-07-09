<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\Mobile\ProposalInboxEmitter;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * GAP-COCKPIT-01 · the review-cockpit PRODUCER for the LIVE autonomous engine.
 *
 * Every real `atlas:task` landing already leaves a durable receipt
 * ({@see AgentControlPlaneTaskQueueOrchestrator::markResolved} appends
 * `atlas.self_construction.resolved_receipt.v1` to `.resolved.jsonl`). Until now those
 * landings passed the operator's inbox entirely — the reader (`atlas:cli:inbox`) and the
 * verdict handler ({@see \App\Services\Ai\Mobile\InboxActionRegistry}) were live but starved.
 *
 * This publisher is PULL-based on purpose: it scans the receipts ledger and emits one
 * idempotent review item per landed commit (dedupe key bound to the commit sha), instead of
 * hooking the hot report path — so it never races the shared-main workers editing
 * AtlasTaskServingService, can be run on any cadence, and re-running is always safe.
 *
 * It supersedes the dormant {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopOperatorReviewMobilePublisher}
 * (same design, but coupled to the dead loop's parked-proposal queue). Design mirrored, source repointed
 * to the LIVE receipts.
 *
 * HARD INVARIANTS:
 *  - post-commit review: the commit already landed via the scoped committer. This publisher
 *    writes ONLY an inbox item; approve certifies, reject records a verdict and points at
 *    `atlas:task:revert` — nothing here (or in the verdict handler) touches git.
 *  - idempotent: republishing the same landing returns the existing open item, never a duplicate.
 *  - fail-open on evidence enrichment: a missing/gc'd commit sha only drops the diff excerpt.
 */
final class AtlasTaskLandingReviewPublisher
{
    public const SCHEMA_VERSION = 'atlas.task_landing.review_publisher.v1';

    public function __construct(
        private readonly ProposalInboxEmitter $emitter,
        private readonly ?string $receiptsPathOverride = null,
        private readonly ?string $repoRootOverride = null,
    ) {}

    /**
     * Publish the latest landed-task receipts as operator review items.
     *
     * @return array<string,mixed>
     */
    public function publish(int $limit = 10): array
    {
        $receipts = $this->latestReceipts(max(1, $limit));
        $published = [];
        foreach ($receipts as $receipt) {
            $record = $this->publishOne($receipt);
            if ($record !== null) {
                $published[] = $record;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'receipts_scanned' => count($receipts),
            'published' => $published,
            'published_count' => count($published),
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>|null
     */
    private function publishOne(array $receipt): ?array
    {
        $sha = $this->str($receipt['commit_sha'] ?? null);
        $taskPacketId = $this->str($receipt['task_packet_id'] ?? null);
        if ($sha === null || $taskPacketId === null) {
            return null;
        }

        $objective = $this->str($receipt['objective_excerpt'] ?? null) ?? $taskPacketId;
        $agentId = $this->str($receipt['agent_id'] ?? null) ?? 'unknown_agent';
        $allowedFiles = array_values(array_filter(array_map(
            fn (mixed $f): ?string => $this->str($f),
            is_array($receipt['allowed_files'] ?? null) ? $receipt['allowed_files'] : [],
        )));
        $diffExcerpt = $this->diffExcerpt($sha);

        $item = $this->emitter->emit([
            'title' => 'Revisar landing do autônomo: '.$objective,
            'finding' => 'Uma task do autônomo vivo landou commit escopado na main e aguarda revisão do operador.',
            'problem' => 'Task '.$taskPacketId.' (agente '.$agentId.') commitou na main sem olho humano no diff.',
            'solution' => 'Aprovar (certifica a landing) ou Rejeitar (registra o veredito e indica o comando de revert governado).',
            'best_solution_rationale' => 'Fecha o ciclo landing -> inbox -> veredito sem tocar git: revert continua explícito, governado pelo atlas:task:revert.',
            'worth_it' => 'Toda landing do motor autônomo passa a ser visível e vetável no cockpit do terminal.',
            'alternatives' => ['Revisar direto com git show '.$sha.'.', 'Rodar atlas:review:deep sobre os arquivos da task.'],
            'category' => 'task_landing_review',
            // source_id is a uuid column in production pgsql — the task_packet_id is NOT a
            // uuid, so it rides in payload.task_landing_review + source_refs instead.
            'source_type' => 'atlas_task_landing',
            'dedupe_key' => 'task-landing-review:'.$sha,
            'confidence' => 0.7,
            'diff_refs' => array_map(
                fn (string $path): array => ['type' => 'task_landing_file', 'path' => $path],
                $allowedFiles,
            ),
            'source_refs' => [['type' => 'atlas_task_resolved_receipt', 'id' => $taskPacketId]],
            'payload' => [
                'task_landing_review' => [
                    'schema_version' => 'atlas.task_landing.review.v1',
                    'task_packet_id' => $taskPacketId,
                    'sha' => $sha,
                    'agent_id' => $agentId,
                    'allowed_files' => $allowedFiles,
                    'resolved_at' => $this->str($receipt['resolved_at'] ?? null),
                    'diff_stat' => $diffExcerpt,
                ],
            ],
            'available_actions' => [
                ['id' => 'task_landing_review_approve', 'label' => 'Aprovar landing', 'style' => 'primary'],
                ['id' => 'task_landing_review_reject', 'label' => 'Rejeitar landing', 'style' => 'destructive'],
                ['id' => 'review_patch', 'label' => 'Ver diff', 'style' => 'default'],
            ],
        ]);

        if ($item === null) {
            return null;
        }

        return [
            'inbox_item_id' => (string) $item->getKey(),
            'task_packet_id' => $taskPacketId,
            'sha' => $sha,
            'agent_id' => $agentId,
            'dedupe_key' => (string) $item->dedupe_key,
        ];
    }

    /**
     * Newest-first tail of valid resolved receipts.
     *
     * @return list<array<string,mixed>>
     */
    private function latestReceipts(int $limit): array
    {
        $path = $this->receiptsPathOverride ?? AgentControlPlaneTaskQueueOrchestrator::resolvedReceiptsPath();
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines)) {
            return [];
        }

        $receipts = [];
        // Newest receipts append at the end; walk backwards until we have $limit valid ones.
        for ($i = count($lines) - 1; $i >= 0 && count($receipts) < $limit; $i--) {
            $decoded = json_decode($lines[$i], true);
            if (! is_array($decoded)) {
                continue;
            }
            if (! str_starts_with((string) ($decoded['schema_version'] ?? ''), 'atlas.self_construction.resolved_receipt')) {
                continue;
            }
            $receipts[] = $decoded;
        }

        return $receipts;
    }

    /**
     * Read-only `git show --stat` excerpt for the landed commit. Fail-open: any git
     * failure (gc'd sha, not a repo) just returns null and the item ships without it.
     */
    private function diffExcerpt(string $sha): ?string
    {
        if (preg_match('/^[0-9a-f]{7,40}$/i', $sha) !== 1) {
            return null;
        }

        try {
            $process = new Process(
                ['git', 'show', '--stat', '--no-color', '--format=%h %s', $sha],
                $this->repoRootOverride ?? base_path(),
            );
            $process->setTimeout(10);
            $process->run();
            if ($process->getExitCode() !== 0) {
                return null;
            }
            $out = trim($process->getOutput());

            return $out === '' ? null : mb_substr($out, 0, 2000);
        } catch (Throwable) {
            return null;
        }
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
