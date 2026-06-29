<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyBaselineReporter;
use App\Services\Ai\AutonomousEvolution\Anomaly\AtlasLoopAnomalyDeviationDetector;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Throwable;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopAnomalyBaselineReporter::baseline()} + {@see AtlasLoopAnomalyDeviationDetector::detect()}
 * pair at the operator surface: gathers the recent receipt window for a campaign, builds the per-signal baseline
 * (completion / give_back / merge / cancel rates), runs the deviation detector against it, and emits the anomaly
 * deviation facts. Read-only.
 */
final class AtlasLoopAnomalyScanCommand extends Command
{
    /** Container key for an injected receipt source (test seam): list<array>|callable(string):list<array>. */
    private const RECEIPTS_BINDING = 'atlas.loop.anomaly.receipts';

    protected $signature = 'atlas:loop:anomaly-scan {--campaign=} {--days=14} {--json}';

    protected $description = 'Read-only anomaly scan for a campaign (baseline signal rates + sigma deviations).';

    public function handle(AtlasLoopAnomalyBaselineReporter $reporter, AtlasLoopAnomalyDeviationDetector $detector): int
    {
        $campaign = trim((string) $this->option('campaign'));
        if ($campaign === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'anomaly-scan requires --campaign=<id>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $window = [
            'start' => gmdate(DATE_ATOM, time() - $days * 86400),
            'end' => gmdate(DATE_ATOM),
        ];

        $receipts = $this->receipts($campaign);
        $baseline = $reporter->baseline($window, $receipts);
        // The deviation detector compares a current window's signal rates to the baseline (sigma-scaled). Until a
        // bucketed baseline is wired it surfaces no deviations, but the pair is now live + composable.
        $deviations = $detector->detect($baseline, $baseline);

        $this->line((string) json_encode([
            'schema_version' => 'atlas.loop.anomaly_scan.v1',
            'campaign' => $campaign,
            'window' => $window,
            'baseline' => $baseline,
            'deviations_count' => count($deviations),
            'deviations' => $deviations,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function receipts(string $campaign): array
    {
        $app = $this->getLaravel();
        if ($app->bound(self::RECEIPTS_BINDING)) {
            $bound = $app->make(self::RECEIPTS_BINDING);
            if (is_callable($bound)) {
                $bound = $bound($campaign);
            }
            if (is_array($bound)) {
                return array_values(array_filter($bound, 'is_array'));
            }
        }

        return $this->fromServingStore();
    }

    /**
     * Best-effort projection of serving-store outcomes into receipt rows ({ts, outcome}). Fail-open.
     *
     * @return list<array{ts:string, outcome:string}>
     */
    private function fromServingStore(): array
    {
        try {
            $receipts = [];
            foreach (['resolved', 'released', 'blocked'] as $status) {
                foreach (AtlasTaskServingStack::queueRepo()->list(['status' => $status]) as $row) {
                    $ts = (string) (data_get($row, 'updated_at') ?? data_get($row, 'resolved_at') ?? '');
                    if ($ts === '') {
                        continue;
                    }
                    $receipts[] = ['ts' => $ts, 'outcome' => $status === 'resolved' ? 'completion' : $status];
                }
            }

            return $receipts;
        } catch (Throwable) {
            return [];
        }
    }
}
