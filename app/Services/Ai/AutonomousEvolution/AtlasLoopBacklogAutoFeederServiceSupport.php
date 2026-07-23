<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AtlasLoopBacklogAutoFeederServiceSupport
{
    /**
     * @return array<string,list<array<string,mixed>>>
     */
    public function collectSources(
        AtlasLoopLossObserverService $lossObserver,
        ?AtlasCognitionScoreCardService $scorecard,
        ?string $campaignId,
        int $windowHours,
        int $minSignalCount,
        string $manifestPath,
        int $manifestLimit,
        bool $includeScorecard,
        bool $includeSweep,
    ): array {
        $sources = [
            'loss_observer' => $this->fromLossObserver($lossObserver, $campaignId, $windowHours, $minSignalCount, $manifestPath, $manifestLimit),
            'failure_corpus' => $this->fromFailureCorpus($windowHours, $minSignalCount),
            'campaign_residuals' => $this->fromCampaignResiduals($campaignId, $windowHours, $minSignalCount),
        ];

        if ($includeScorecard) {
            $sources['scorecard_weak_receipts'] = $this->fromScorecardWeakReceipts($scorecard);
        }

        if ($includeSweep) {
            $sources['sweep_findings'] = $this->fromSweepFindings($minSignalCount);
        }

        return $sources;
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $sources
     * @return list<array<string,mixed>>
     */
    public function rankCandidates(array $sources, int $maxItems): array
    {
        $candidates = $this->dedupeCandidates(array_merge(...array_values($sources)));
        usort($candidates, static fn (array $a, array $b): int => ((float) ($b['priority'] ?? 0)) <=> ((float) ($a['priority'] ?? 0)));

        return array_slice($candidates, 0, $maxItems);
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  list<array<string,mixed>>  $actions
     */
    public function summarizeStatus(array $candidates, array $actions, bool $write): string
    {
        if ($candidates === []) {
            return 'clear';
        }

        if (! $write) {
            return 'dry_run';
        }

        $enqueued = count(array_filter($actions, static fn (array $action): bool => ($action['status'] ?? null) === 'enqueued'));

        return $enqueued > 0 ? 'acted' : 'observed';
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fromLossObserver(
        AtlasLoopLossObserverService $lossObserver,
        ?string $campaignId,
        int $windowHours,
        int $minSignalCount,
        string $manifestPath,
        int $manifestLimit,
    ): array {
        try {
            $observed = $lossObserver->observe($campaignId, [
                'window_hours' => $windowHours,
                'min_occurrences' => $minSignalCount,
                'write' => false,
                'manifest_path' => $manifestPath,
                'manifest_limit' => $manifestLimit,
            ]);
        } catch (Throwable) {
            return [];
        }

        $items = [];
        foreach ((array) ($observed['actions'] ?? []) as $action) {
            if (($action['status'] ?? null) === 'duplicate' || ! is_array($action['item'] ?? null)) {
                continue;
            }

            $items[] = $action['item'];
        }

        return $items;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fromFailureCorpus(int $windowHours, int $minSignalCount): array
    {
        if (! DatabaseTableAvailability::has('failure_signatures')) {
            return [];
        }

        try {
            $rows = DB::table('failure_signatures')
                ->where('recorded_at', '>=', Carbon::now()->subHours($windowHours))
                ->orderByDesc('recorded_at')
                ->limit(500)
                ->get(['signature_key', 'category', 'sub_cause', 'context_summary', 'recurrence_count']);
        } catch (Throwable) {
            return [];
        }

        $groups = [];
        foreach ($rows as $row) {
            $reason = trim((string) ($row->category ?? '').':'.(string) ($row->sub_cause ?? ''), ':');
            $reason = $reason !== '' ? $reason : (string) ($row->signature_key ?? 'failure_signature');
            foreach ($this->extractExistingPaths((string) ($row->context_summary ?? '')) as $path) {
                $key = $path.'|'.$reason;
                $groups[$key] ??= ['path' => $path, 'reason' => $reason, 'occurrences' => 0];
                $groups[$key]['occurrences'] += max(1, (int) ($row->recurrence_count ?? 1));
            }
        }

        return $this->itemsFromGroups($groups, 'auto_feed:failure_corpus', $windowHours, $minSignalCount, 0.70);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fromCampaignResiduals(?string $campaignId, int $windowHours, int $minSignalCount): array
    {
        if (! DatabaseTableAvailability::all(['atlas_loop_tasks', 'atlas_loop_proposals'])) {
            return [];
        }

        try {
            $query = DB::table('atlas_loop_tasks as t')
                ->leftJoin('atlas_loop_proposals as p', 'p.task_id', '=', 't.id')
                ->whereNull('p.id')
                ->whereNotNull('t.target_path')
                ->where('t.updated_at', '>=', Carbon::now()->subHours($windowHours))
                ->whereIn('t.status', ['done', 'failed', 'deferred'])
                ->select(['t.campaign_id', 't.target_path', 't.result', 't.status', 't.objective']);

            if ($campaignId !== null && $campaignId !== '') {
                $query->where('t.campaign_id', $campaignId);
            }

            $rows = $query->limit(500)->get();
        } catch (Throwable) {
            return [];
        }

        $groups = [];
        foreach ($rows as $row) {
            $path = ltrim(trim((string) ($row->target_path ?? '')), '/');
            if (! $this->existingRepoFile($path)) {
                continue;
            }

            $reason = $this->taskResidualReason($row->result ?? null, (string) ($row->status ?? 'done'));
            $key = $path.'|'.$reason;
            $groups[$key] ??= ['path' => $path, 'reason' => $reason, 'occurrences' => 0];
            $groups[$key]['occurrences']++;
        }

        return $this->itemsFromGroups($groups, 'auto_feed:campaign_residual', $windowHours, $minSignalCount, 0.66);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fromScorecardWeakReceipts(?AtlasCognitionScoreCardService $scorecard): array
    {
        $scorecard ??= app(AtlasCognitionScoreCardService::class);

        try {
            $report = $scorecard->build();
        } catch (Throwable) {
            return [];
        }

        return $this->scorecardWeakReceiptItems((array) ($report['subsystems'] ?? []));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function fromSweepFindings(int $minSignalCount): array
    {
        $docs = [
            base_path('docs/fable-lista-4-14-itens.md'),
            base_path('docs/fable-lista-5-14-itens.md'),
            base_path('docs/fable-lista-6-14-itens.md'),
            base_path('docs/engineering-knowledge-base/atlas-aaeos-loop-evolution-backlog.md'),
        ];
        $groups = [];
        foreach ($docs as $doc) {
            if (! is_file($doc)) {
                continue;
            }

            $text = (string) @file_get_contents($doc);
            if (! preg_match('/sweep|backlog|buraco|falha|residual|stale/i', $text)) {
                continue;
            }

            foreach ($this->extractExistingPaths($text) as $path) {
                $key = $path.'|sweep_finding';
                $groups[$key] ??= ['path' => $path, 'reason' => 'sweep_finding', 'occurrences' => 0];
                $groups[$key]['occurrences']++;
            }
        }

        return $this->itemsFromGroups($groups, 'auto_feed:sweep_finding', 0, max(1, $minSignalCount), 0.64);
    }

    /**
     * @param  list<mixed>  $subsystems
     * @return list<array<string,mixed>>
     */
    public function scorecardWeakReceiptItems(array $subsystems): array
    {
        $items = [];
        foreach ($subsystems as $row) {
            if (! is_array($row)) {
                continue;
            }

            $item = $this->scorecardWeakReceiptItem($row);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>|null
     */
    public function scorecardWeakReceiptItem(array $row): ?array
    {
        $docStatus = (string) ($row['doc_status'] ?? 'unknown');
        $pipelineStatus = (string) ($row['pipeline_status'] ?? 'unknown');
        if ($docStatus === AtlasCognitionScoreCardService::STATUS_READY
            && $pipelineStatus === AtlasCognitionScoreCardService::STATUS_READY) {
            return null;
        }

        $path = $this->pathFromServiceClass((string) ($row['service_class'] ?? ''));
        if ($path === null || ! $this->existingRepoFile($path)) {
            return null;
        }

        $acronym = (string) ($row['acronym'] ?? basename($path, '.php'));
        $reason = 'scorecard_weak_receipt:doc='.$docStatus.';pipeline='.$pipelineStatus;

        return [
            'path' => $path,
            'objective' => sprintf(
                'Completar evidência fraca do scorecard ACOS para %s em %s (doc=%s, pipeline=%s).',
                $acronym,
                $path,
                $docStatus,
                $pipelineStatus,
            ),
            'priority' => $pipelineStatus === AtlasCognitionScoreCardService::STATUS_PARTIAL ? 0.68 : 0.62,
            'source' => 'auto_feed:scorecard_weak_receipt',
            'source_key' => hash('sha256', 'auto_feed:scorecard_weak_receipt|'.$path.'|'.$acronym),
            'reason' => $reason,
            'occurrences' => 1,
            'observed_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,array{path:string,reason:string,occurrences:int}>  $groups
     * @return list<array<string,mixed>>
     */
    public function itemsFromGroups(array $groups, string $source, int $windowHours, int $minSignalCount, float $basePriority): array
    {
        $items = [];
        foreach ($groups as $group) {
            $occurrences = (int) $group['occurrences'];
            if ($occurrences < $minSignalCount) {
                continue;
            }

            $path = (string) $group['path'];
            $reason = (string) $group['reason'];
            $window = $windowHours > 0 ? " nas últimas {$windowHours}h" : '';
            $items[] = [
                'path' => $path,
                'objective' => sprintf(
                    'Atacar backlog auto-alimentado: %s em %s (%d sinais%s).',
                    $reason,
                    $path,
                    $occurrences,
                    $window,
                ),
                'priority' => round(min(1.0, $basePriority + min(0.24, $occurrences * 0.04)), 4),
                'source' => $source,
                'source_key' => hash('sha256', $source.'|'.$path.'|'.$reason),
                'reason' => $reason,
                'occurrences' => $occurrences,
                'observed_window_hours' => $windowHours,
                'observed_at' => Carbon::now()->toIso8601String(),
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public function dedupeCandidates(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $key = (string) ($item['source_key'] ?? hash('sha256', ($item['source'] ?? '').'|'.($item['path'] ?? '').'|'.($item['objective'] ?? '')));
            if (! isset($out[$key]) || (float) ($item['priority'] ?? 0) > (float) ($out[$key]['priority'] ?? 0)) {
                $out[$key] = $item;
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    public function extractExistingPaths(string $text): array
    {
        preg_match_all('#\b((?:app|tests|routes|config|database|docs)/[A-Za-z0-9_./-]+(?:\.php|\.md|\.json|\.yml|\.yaml)?)#', $text, $matches);
        $paths = [];
        foreach ($matches[1] ?? [] as $raw) {
            $path = trim((string) $raw, " \t\n\r\0\x0B.,:;)'\"]");
            if ($this->existingRepoFile($path)) {
                $paths[$path] = true;
            }
        }

        return array_keys($paths);
    }

    public function existingRepoFile(string $path): bool
    {
        $path = ltrim(trim($path), '/');

        return $path !== '' && is_file(base_path($path));
    }

    public function pathFromServiceClass(string $serviceClass): ?string
    {
        if (! str_starts_with($serviceClass, 'App\\')) {
            return null;
        }

        return 'app/'.str_replace('\\', '/', ltrim(substr($serviceClass, strlen('App\\')), '\\')).'.php';
    }

    public function taskResidualReason(mixed $result, string $status): string
    {
        if (is_string($result)) {
            $decoded = json_decode($result, true);
            $result = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if (! is_array($result)) {
            return 'task_'.$status.'_without_proposal';
        }

        foreach (['reason', 'error', 'status'] as $key) {
            $raw = trim((string) ($result[$key] ?? ''));
            if ($raw !== '') {
                return mb_substr($raw, 0, 160);
            }
        }

        return 'task_'.$status.'_without_proposal';
    }
}
