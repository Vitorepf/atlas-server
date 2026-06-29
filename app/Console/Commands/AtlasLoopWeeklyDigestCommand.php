<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\WeeklyDigest\AtlasLoopWeeklyDigestComposer;
use App\Services\Ai\AutonomousEvolution\WeeklyDigest\AtlasLoopWeeklyDigestExporter;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;
use Throwable;

/**
 * Arms the dormant weekly-digest chain at the operator surface: builds ledger_source => callable(from,to)
 * sources over the last 7 days (serving-store task activity), composes them via
 * {@see AtlasLoopWeeklyDigestComposer} and renders the snapshot through {@see AtlasLoopWeeklyDigestExporter} —
 * a deterministic facts-only weekly self-report (rows keyed by ledger_source/event_kind). Read-only over the
 * serving store; the exporter writes the markdown under a configured root.
 */
final class AtlasLoopWeeklyDigestCommand extends Command
{
    /** Container key for injected ledger sources (test seam): array<string, callable(string,string):list<array>>. */
    private const SOURCES_BINDING = 'atlas.loop.weekly_digest.sources';

    protected $signature = 'atlas:loop:weekly-digest {--json}';

    protected $description = 'Compose + export the deterministic facts-only weekly self-digest (last 7 days).';

    public function handle(): int
    {
        $to = gmdate(DATE_ATOM);
        $from = gmdate(DATE_ATOM, time() - 7 * 86400);

        $snapshot = (new AtlasLoopWeeklyDigestComposer($this->ledgerSources()))->compose($from, $to);
        $result = (new AtlasLoopWeeklyDigestExporter($this->exportRoot()))->export($snapshot, gmdate('o-\WW'));

        $this->line((string) json_encode([
            'written' => (bool) ($result['written'] ?? false),
            'path' => $result['path'] ?? null,
            'reason' => $result['reason'] ?? null,
            'snapshot_hash' => $snapshot['snapshot_hash'] ?? '',
            'rows_count' => count((array) ($snapshot['rows'] ?? [])),
            'markdown' => $result['markdown'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return array<string, callable(string,string):list<array<string,mixed>>>
     */
    private function ledgerSources(): array
    {
        $app = $this->getLaravel();
        if ($app->bound(self::SOURCES_BINDING)) {
            $bound = $app->make(self::SOURCES_BINDING);
            if (is_array($bound)) {
                return $bound;
            }
        }

        // Real default: the serving-store task activity as one ledger source. Fail-open.
        return [
            'evolution' => fn (string $from, string $to): array => $this->servingActivity(),
        ];
    }

    /**
     * @return list<array{occurred_at:string, event_kind:string, payload:array<string,mixed>}>
     */
    private function servingActivity(): array
    {
        try {
            $facts = [];
            foreach (['served', 'resolved', 'completed', 'cancelled', 'released'] as $status) {
                foreach (AtlasTaskServingStack::queueRepo()->list(['status' => $status]) as $row) {
                    $occurredAt = (string) (data_get($row, 'updated_at') ?? data_get($row, 'resolved_at') ?? data_get($row, 'released_at') ?? '');
                    if ($occurredAt === '') {
                        continue;
                    }
                    $facts[] = [
                        'occurred_at' => $occurredAt,
                        'event_kind' => $status,
                        'payload' => ['task_packet_id' => (string) (data_get($row, 'task_packet_id') ?? '')],
                    ];
                }
            }

            return $facts;
        } catch (Throwable) {
            return [];
        }
    }

    private function exportRoot(): string
    {
        return (string) config('atlas.loop.weekly_digest.export_root', storage_path('atlas/loop/weekly-digest'));
    }
}
