<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;

/**
 * L4-2 · daily auto-feeder for the Loop backlog manifest.
 *
 * Converts existing, provider-free signals into addressable intents:
 * loss observer dry-runs, failure-signature corpus, campaign residuals, weak ACOS
 * scorecard receipts, and sweep notes that name real files.
 */
final class AtlasLoopBacklogAutoFeederService
{
    public const SCHEMA_VERSION = 'atlas.loop.backlog_auto_feeder.v1';

    public function __construct(
        private readonly AtlasLoopBacklogManifestService $manifest,
        private readonly AtlasLoopLossObserverService $lossObserver,
        private readonly ?AtlasCognitionScoreCardService $scorecard = null,
    ) {}

    /**
     * @param  array{window_hours?:int,min_signal_count?:int,max_items?:int,write?:bool,manifest_path?:string,manifest_limit?:int,include_scorecard_weak_receipts?:bool,include_sweep_findings?:bool}  $options
     * @return array<string,mixed>
     */
    public function feed(?string $campaignId = null, array $options = []): array
    {
        $cfg = (array) config('atlas.loop.backlog_auto_feed', []);
        $windowHours = max(1, (int) ($options['window_hours'] ?? $cfg['window_hours'] ?? 24));
        $minSignalCount = max(2, (int) ($options['min_signal_count'] ?? $cfg['min_signal_count'] ?? 2));
        $maxItems = max(1, (int) ($options['max_items'] ?? $cfg['max_items'] ?? 8));
        $write = (bool) ($options['write'] ?? true);
        $manifestPath = (string) ($options['manifest_path'] ?? $this->manifest->defaultPath());
        $manifestLimit = max(10, (int) ($options['manifest_limit'] ?? $cfg['manifest_limit'] ?? 200));
        $includeScorecard = (bool) ($options['include_scorecard_weak_receipts'] ?? $cfg['include_scorecard_weak_receipts'] ?? true);
        $includeSweep = (bool) ($options['include_sweep_findings'] ?? $cfg['include_sweep_findings'] ?? true);
        $support = new AtlasLoopBacklogAutoFeederServiceSupport;

        $sources = $support->collectSources(
            $this->lossObserver,
            $this->scorecard,
            $campaignId,
            $windowHours,
            $minSignalCount,
            $manifestPath,
            $manifestLimit,
            $includeScorecard,
            $includeSweep,
        );
        $candidates = $support->rankCandidates($sources, $maxItems);

        $actions = [];
        foreach ($candidates as $candidate) {
            $actions[] = $this->manifest->append($manifestPath, $candidate, $manifestLimit, $write);
        }

        $enqueued = count(array_filter($actions, static fn (array $a): bool => ($a['status'] ?? null) === 'enqueued'));
        $dryRun = count(array_filter($actions, static fn (array $a): bool => ($a['status'] ?? null) === 'dry_run'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $support->summarizeStatus($candidates, $actions, $write),
            'campaign_id' => $campaignId,
            'window_hours' => $windowHours,
            'min_signal_count' => $minSignalCount,
            'max_items' => $maxItems,
            'candidate_count' => count($candidates),
            'source_counts' => array_map('count', $sources),
            'actions' => $actions,
            'actions_count' => count($actions),
            'enqueued_count' => $enqueued,
            'dry_run_count' => $dryRun,
            'manifest_path' => $manifestPath,
            'writes_enabled' => $write,
        ];
    }
}