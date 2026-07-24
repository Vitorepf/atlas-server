<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiInboxItem;
use App\Services\Ai\Mobile\InboxActionRegistry;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * O5 · batch verdict for autonomous-landing reviews — the operator approves/rejects N
 * landings in ONE command instead of one `atlas:cli:inbox respond` per item. Routes every
 * decision through the SAME InboxActionRegistry verdict (audit + evidence ledger included);
 * this surface adds zero new authority. Post-commit contract unchanged: reject only RECORDS
 * and hands back the governed revert command — nothing here touches git.
 */
class AtlasTaskReviewDecideCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:task:review:decide
        {targets* : Um ou mais alvos — inbox item id, commit sha ou task_packet_id}
        {--reject : Rejeitar em vez de aprovar}
        {--reason= : Motivo do veredito (vai pro audit/ledger)}
        {--dry-run : Mostrar o que seria decidido, sem decidir}
        {--json : Print machine-readable JSON}';

    protected $description = 'Veredito em lote (aprovar/rejeitar) de landings do autônomo no inbox de review.';

    public function handle(InboxActionRegistry $registry): int
    {
        $action = (bool) $this->option('reject') ? 'task_landing_review_reject' : 'task_landing_review_approve';
        $reason = trim((string) $this->option('reason')) ?: null;
        $dryRun = (bool) $this->option('dry-run');

        $results = [];
        foreach ((array) $this->argument('targets') as $target) {
            $target = trim((string) $target);
            $item = $this->resolveOpenItem($target);
            if ($item === null) {
                $results[] = ['target' => $target, 'ok' => false, 'reason' => 'no_open_landing_review_item'];

                continue;
            }

            if ($dryRun) {
                $results[] = [
                    'target' => $target,
                    'ok' => true,
                    'dry_run' => true,
                    'would' => $action,
                    'inbox_item_id' => (string) $item->getKey(),
                    'sha' => (string) data_get($item->payload, 'task_landing_review.sha'),
                    'task_packet_id' => (string) data_get($item->payload, 'task_landing_review.task_packet_id'),
                ];

                continue;
            }

            try {
                $handled = $registry->handle($item, $action, $reason !== null ? ['reason' => $reason] : [], 'review-decide:'.$item->getKey().':'.$action);
                $decision = (array) data_get($handled, 'result.payload.task_landing_review', []);
                $results[] = [
                    'target' => $target,
                    'ok' => (bool) ($handled['ok'] ?? false),
                    'inbox_item_id' => (string) $item->getKey(),
                    'verdict' => $decision['verdict'] ?? null,
                    'sha' => $decision['sha'] ?? null,
                    'task_packet_id' => $decision['task_packet_id'] ?? null,
                    'revert_command' => $decision['revert_command'] ?? null,
                ];
            } catch (Throwable $e) {
                $results[] = ['target' => $target, 'ok' => false, 'reason' => mb_substr($e->getMessage(), 0, 200)];
            }
        }

        $payload = [
            'schema_version' => 'atlas.task_landing.review_decide.v1',
            'action' => $action,
            'dry_run' => $dryRun,
            'decided' => count(array_filter($results, static fn (array $r): bool => $r['ok'] && ! ($r['dry_run'] ?? false))),
            'results' => $results,
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            foreach ($results as $r) {
                $this->line(sprintf(
                    '%s %s %s%s',
                    $r['ok'] ? '<info>✓</info>' : '<error>✗</error>',
                    $r['target'],
                    $r['ok'] ? (($r['dry_run'] ?? false) ? 'faria: '.$r['would'] : 'veredito: '.($r['verdict'] ?? '?')) : (string) ($r['reason'] ?? ''),
                    isset($r['revert_command']) && $r['revert_command'] !== null ? ' · revert: '.$r['revert_command'] : '',
                ));
            }
        }

        return count(array_filter($results, static fn (array $r): bool => ! $r['ok'])) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Resolve an OPEN landing-review inbox item by item id, commit sha, or task_packet_id.
     */
    private function resolveOpenItem(string $target): ?AiInboxItem
    {
        $open = AiInboxItem::query()
            ->where('category', 'task_landing_review')
            ->whereNotIn('status', ['resolved', 'dismissed', 'expired']);

        if (preg_match('/^[0-9a-f]{7,40}$/i', $target) === 1) {
            // Sha: exact dedupe key first, prefix scan as fallback (short shas).
            $bySha = (clone $open)->where('dedupe_key', 'task-landing-review:'.$target)->latest('created_at')->first();
            if ($bySha !== null) {
                return $bySha;
            }
            $byPrefix = (clone $open)->where('dedupe_key', 'like', 'task-landing-review:'.$target.'%')->latest('created_at')->first();
            if ($byPrefix !== null) {
                return $byPrefix;
            }
        }

        // id é coluna uuid no pgsql — whereKey com string não-uuid explode; só tenta se parecer uuid.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $target) === 1) {
            $byId = (clone $open)->whereKey($target)->first();
            if ($byId !== null) {
                return $byId;
            }
        }

        return (clone $open)
            ->where('payload->task_landing_review->task_packet_id', $target)
            ->latest('created_at')
            ->first();
    }
}
