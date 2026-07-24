<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskLandingReviewPublisher;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * GAP-COCKPIT-01 surface · pulls the latest LIVE autonomous landings (resolved receipts)
 * into the operator review inbox. Idempotent per commit sha — safe on any cadence.
 * Consume the items with `atlas:cli:inbox list|show|respond`.
 */
class AtlasTaskLandingReviewPublishCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:task:review:publish
        {--limit=10 : How many recent landed-task receipts to scan}
        {--json : Print machine-readable JSON}';

    protected $description = 'Publish landed atlas:task commits as operator review items in the inbox (approve/reject).';

    public function handle(AtlasTaskLandingReviewPublisher $publisher): int
    {
        $result = $publisher->publish(max(1, (int) $this->option('limit')));

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Receipts lidos: %d · itens de review publicados/abertos: %d',
            (int) ($result['receipts_scanned'] ?? 0),
            (int) ($result['published_count'] ?? 0),
        ));

        $rows = array_map(static fn (array $r): array => [
            $r['inbox_item_id'] ?? '',
            $r['task_packet_id'] ?? '',
            substr((string) ($r['sha'] ?? ''), 0, 10),
            $r['agent_id'] ?? '',
        ], (array) ($result['published'] ?? []));

        if ($rows !== []) {
            $this->table(['inbox_item_id', 'task_packet_id', 'sha', 'agent'], $rows);
            $this->line('Decida com: php artisan atlas:cli:inbox respond --id=<inbox_item_id> --action=task_landing_review_approve|task_landing_review_reject');
        } else {
            $this->line('Nenhum receipt novo para publicar (ou itens já abertos no inbox).');
        }

        return self::SUCCESS;
    }
}
