<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Forge Rivals · Battery Report (multi-case v1).
 *
 * Renders a battery-level report.md and JSON envelope by aggregating
 * battery.json over (a) categories — backend, frontend, bugfix, … — and
 * (b) the canonical L1-L5 difficulty ladder. Each case keeps its
 * difficulty_level + difficulty_weight; the battery score is the
 * weighted sum of completed-case weights divided by the weighted sum of
 * all-case weights, expressed as a percentage.
 *
 * Hard contract:
 *   - claim_ready=false unless EVERY case reached `completed`. A single
 *     `failed`, `invalid`, `skipped` or `pending` case keeps the battery
 *     non-claimable, regardless of the weighted score.
 *   - The score is informational. It does not unlock any external claim.
 *   - The service never invokes providers, never edits worktrees, never
 *     unlocks external_rivals_certification.
 *
 * Schema: atlas.forge.rivals.battery_report.v1
 */
final class AtlasForgeRivalsBatteryReportService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.battery_report.v1';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly AtlasForgeRivalsBatteryStateService $battery,
    ) {}

    /**
     * Render the battery report. When the battery does not exist for the
     * supplied run_id, returns status=blocked. Otherwise writes
     * `runs/<run_id>/evidence/battery_report.md` and returns the JSON
     * envelope.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function render(array $input): array
    {
        $runId = trim((string) ($input['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'status' => 'blocked',
                'blockers' => ['run_id_required'],
                'next_command' => 'php artisan atlas:forge:rivals battery-report --run-id=<id> --json',
            ];
        }
        $battery = $this->battery->load($runId);
        if ($battery === null) {
            return [
                'status' => 'blocked',
                'blockers' => ['battery_not_found:'.$runId],
                'run_id' => $runId,
                'next_command' => 'php artisan atlas:forge:rivals run-battery --preset=release --mode=fair --atlas-model=sonnet --rival=claude_sonnet --json',
            ];
        }

        $cases = (array) ($battery['cases'] ?? []);

        $categoryAggregate = $this->aggregateBy($cases, 'task_category');
        $difficultyAggregate = $this->aggregateBy($cases, 'difficulty_level');

        $scoreBreakdown = $this->weightedScoreBreakdown($cases);
        $planningScore = $this->weightedScoreFor($cases, 'planning_weight');
        $executionScore = $this->weightedScoreFor($cases, 'execution_weight');
        $difficultyScoreTotal = $this->weightedScoreFor($cases, 'difficulty_score');

        $claimReady = $this->isClaimReady($cases, (string) ($battery['mode'] ?? ''));

        $markdown = $this->renderMarkdown(
            $battery,
            $categoryAggregate,
            $difficultyAggregate,
            $scoreBreakdown,
            $claimReady,
            $planningScore,
            $executionScore,
            $difficultyScoreTotal,
        );

        $paths = $this->paths->paths($runId);
        $evidenceDir = $paths['evidence'];
        if (! is_dir($evidenceDir)) {
            @mkdir($evidenceDir, 0o755, true);
        }
        $reportPath = $evidenceDir.'/battery_report.md';
        @file_put_contents($reportPath, $markdown);

        $envelope = [
            'status' => 'ok',
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'generated_at' => $this->nowIso(),
            'battery_status' => $battery['battery_status'] ?? null,
            'preset' => $battery['preset'] ?? null,
            'case_set' => $battery['case_set'] ?? null,
            'mode' => $battery['mode'] ?? null,
            'atlas_model' => $battery['atlas_model'] ?? null,
            'rival_model' => $battery['rival_model'] ?? null,
            'case_count' => (int) ($battery['case_count'] ?? count($cases)),
            'aggregate_verdict' => $battery['aggregate_verdict'] ?? null,
            'category_aggregate' => $categoryAggregate,
            'difficulty_aggregate' => $difficultyAggregate,
            'weighted_score' => $scoreBreakdown,
            'planning_score' => $planningScore,
            'execution_score' => $executionScore,
            'difficulty_score' => $difficultyScoreTotal,
            'claim_ready' => $claimReady,
            'human_review_required' => ! $claimReady,
            'external_provider_call' => (bool) ($battery['external_provider_call'] ?? false),
            'provider_tokens_spent' => (bool) ($battery['provider_tokens_spent'] ?? false),
            'separated_from_external_rivals_certification' => true,
            'report_path' => $reportPath,
            'next_command' => $claimReady
                ? 'php artisan atlas:forge:rivals collect-evidence --run-id='.$runId.' --json'
                : 'php artisan atlas:forge:rivals resume --run-id='.$runId.' --json',
            'note' => 'Battery report is informational. It does not unlock external_rivals_certification or promote claim_ready=true unless every case completed without invalid/failed/skipped/pending state.',
        ];

        return $envelope;
    }

    /**
     * Aggregate per-state counters keyed by a case attribute (task_category
     * or difficulty_level). Returns ordered entries with completed/failed/
     * invalid/skipped/pending counters plus a weighted_score per group.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return list<array<string,mixed>>
     */
    private function aggregateBy(array $cases, string $key): array
    {
        $buckets = [];
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $bucketKey = trim((string) ($row[$key] ?? ''));
            if ($bucketKey === '') {
                $bucketKey = 'unspecified';
            }
            $bucket = $buckets[$bucketKey] ?? [
                'key' => $bucketKey,
                'case_count' => 0,
                'completed' => 0,
                'failed' => 0,
                'invalid' => 0,
                'skipped' => 0,
                'pending' => 0,
                'running' => 0,
                'completed_weight' => 0.0,
                'total_weight' => 0.0,
                'case_ids' => [],
            ];
            $state = (string) ($row['state'] ?? AtlasForgeRivalsBatteryStateService::CASE_STATE_PENDING);
            $weight = (float) ($row['difficulty_weight']
                ?? AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight(
                    (string) ($row['difficulty_level'] ?? '')
                ));
            $bucket['case_count']++;
            $bucket['total_weight'] += $weight;
            $bucket['case_ids'][] = (string) ($row['case_id'] ?? '');
            $stateKey = match ($state) {
                AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED => 'completed',
                AtlasForgeRivalsBatteryStateService::CASE_STATE_FAILED => 'failed',
                AtlasForgeRivalsBatteryStateService::CASE_STATE_INVALID => 'invalid',
                AtlasForgeRivalsBatteryStateService::CASE_STATE_SKIPPED => 'skipped',
                AtlasForgeRivalsBatteryStateService::CASE_STATE_RUNNING => 'running',
                default => 'pending',
            };
            $bucket[$stateKey]++;
            if ($stateKey === 'completed') {
                $bucket['completed_weight'] += $weight;
            }
            $buckets[$bucketKey] = $bucket;
        }

        $out = [];
        foreach ($buckets as $bucket) {
            $totalWeight = $bucket['total_weight'];
            $bucket['weighted_score_percent'] = $totalWeight > 0
                ? round(($bucket['completed_weight'] / $totalWeight) * 100, 2)
                : 0.0;
            $bucket['case_ids'] = array_values(array_filter($bucket['case_ids'], static fn (string $i): bool => $i !== ''));
            $out[] = $bucket;
        }

        // For difficulty, sort by L1->L5 ladder; otherwise alphabetical.
        if ($key === 'difficulty_level') {
            $order = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS;
            usort($out, static function (array $a, array $b) use ($order): int {
                $ai = array_search($a['key'], $order, true);
                $bi = array_search($b['key'], $order, true);
                $ai = $ai === false ? PHP_INT_MAX : (int) $ai;
                $bi = $bi === false ? PHP_INT_MAX : (int) $bi;
                if ($ai !== $bi) {
                    return $ai <=> $bi;
                }

                return strcmp((string) $a['key'], (string) $b['key']);
            });
        } else {
            usort($out, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));
        }

        return $out;
    }

    /**
     * Compute the weighted-score breakdown for the whole battery. Score is
     * sum(completed_weights) / sum(all_weights), capped at 100. Returns
     * the breakdown so the report can render counters per L1-L5 level.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function weightedScoreBreakdown(array $cases): array
    {
        $totalWeight = 0.0;
        $completedWeight = 0.0;
        $byLevel = [];
        foreach (AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS as $level) {
            $byLevel[$level] = [
                'level' => $level,
                'weight' => AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level),
                'case_count' => 0,
                'completed' => 0,
                'completed_weight' => 0.0,
                'total_weight' => 0.0,
            ];
        }
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $level = (string) ($row['difficulty_level'] ?? '');
            if ($level === '' || ! isset($byLevel[$level])) {
                $level = AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_L3;
            }
            $weight = (float) ($row['difficulty_weight']
                ?? AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level));
            $byLevel[$level]['case_count']++;
            $byLevel[$level]['total_weight'] += $weight;
            $totalWeight += $weight;
            if (($row['state'] ?? '') === AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED) {
                $byLevel[$level]['completed']++;
                $byLevel[$level]['completed_weight'] += $weight;
                $completedWeight += $weight;
            }
        }

        return [
            'total_weight' => $totalWeight,
            'completed_weight' => $completedWeight,
            'score_percent' => $totalWeight > 0 ? round(($completedWeight / $totalWeight) * 100, 2) : 0.0,
            'by_level' => array_values($byLevel),
        ];
    }

    /**
     * Generic weighted-score computation for an arbitrary case attribute
     * (planning_weight, execution_weight, difficulty_score). Falls back to
     * `difficulty_weight` (always set by the adapter) when the requested
     * attribute is absent so the score block is never empty for a
     * matrix-corpus case-set.
     *
     * @param  list<array<string,mixed>>  $cases
     * @return array<string,mixed>
     */
    private function weightedScoreFor(array $cases, string $weightKey): array
    {
        $totalWeight = 0.0;
        $completedWeight = 0.0;
        $cap = 0;
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $weight = is_numeric($row[$weightKey] ?? null)
                ? (float) $row[$weightKey]
                : (float) ($row['difficulty_weight'] ?? AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight(
                    (string) ($row['difficulty_level'] ?? '')
                ));
            $totalWeight += $weight;
            $cap++;
            if (($row['state'] ?? '') === AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED) {
                $completedWeight += $weight;
            }
        }

        return [
            'weight_key' => $weightKey,
            'case_count' => $cap,
            'total_weight' => round($totalWeight, 4),
            'completed_weight' => round($completedWeight, 4),
            'score_percent' => $totalWeight > 0 ? round(($completedWeight / $totalWeight) * 100, 2) : 0.0,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $cases
     */
    private function isClaimReady(array $cases, string $mode): bool
    {
        if ($mode === AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE) {
            return false;
        }
        if ($cases === []) {
            return false;
        }
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $state = (string) ($row['state'] ?? '');
            if ($state !== AtlasForgeRivalsBatteryStateService::CASE_STATE_COMPLETED) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $battery
     * @param  list<array<string,mixed>>  $categoryAggregate
     * @param  list<array<string,mixed>>  $difficultyAggregate
     * @param  array<string,mixed>  $scoreBreakdown
     */
    private function renderMarkdown(
        array $battery,
        array $categoryAggregate,
        array $difficultyAggregate,
        array $scoreBreakdown,
        bool $claimReady,
        array $planningScore = [],
        array $executionScore = [],
        array $difficultyScoreTotal = [],
    ): string {
        $cases = (array) ($battery['cases'] ?? []);
        $caseCount = (int) ($battery['case_count'] ?? count($cases));
        $preset = (string) ($battery['preset'] ?? 'unknown');
        $caseSet = (string) ($battery['case_set'] ?? '');
        $mode = (string) ($battery['mode'] ?? '');
        $atlasModel = (string) ($battery['atlas_model'] ?? '');
        $rivalModel = (string) ($battery['rival_model'] ?? '');
        $batteryStatus = (string) ($battery['battery_status'] ?? '');
        $aggregateVerdict = (string) ($battery['aggregate_verdict'] ?? '');
        $startedAt = (string) ($battery['started_at'] ?? '');
        $finishedAt = (string) ($battery['finished_at'] ?? '');
        $score = (float) ($scoreBreakdown['score_percent'] ?? 0);
        $resumeCount = (int) ($battery['resume_count'] ?? 0);

        $lines = [];
        $lines[] = '# Atlas Forge Rivals · Battery Report';
        $lines[] = '';
        $lines[] = '> Schema: '.self::SCHEMA_VERSION.' · run_id: `'.$battery['run_id'].'`';
        $lines[] = '> Generated: '.$this->nowIso();
        $lines[] = '';
        $lines[] = '## Overview';
        $lines[] = '';
        $lines[] = '| Field | Value |';
        $lines[] = '| --- | --- |';
        $lines[] = '| preset | `'.$preset.'` |';
        $lines[] = '| case_set | `'.($caseSet !== '' ? $caseSet : '—').'` |';
        $lines[] = '| mode | `'.$mode.'` |';
        $lines[] = '| atlas_model | `'.$atlasModel.'` |';
        $lines[] = '| rival_model | `'.$rivalModel.'` |';
        $lines[] = '| case_count | '.$caseCount.' |';
        $lines[] = '| battery_status | `'.$batteryStatus.'` |';
        $lines[] = '| aggregate_verdict | `'.$aggregateVerdict.'` |';
        $lines[] = '| started_at | '.$startedAt.' |';
        $lines[] = '| finished_at | '.($finishedAt !== '' ? $finishedAt : 'still pending') .' |';
        $lines[] = '| resume_count | '.$resumeCount.' |';
        $lines[] = '| weighted_score (difficulty ladder) | '.number_format($score, 2).'% |';
        if (! empty($planningScore)) {
            $lines[] = '| planning_score (matrix planning_weight) | '.number_format((float) ($planningScore['score_percent'] ?? 0), 2).'% |';
        }
        if (! empty($executionScore)) {
            $lines[] = '| execution_score (matrix execution_weight) | '.number_format((float) ($executionScore['score_percent'] ?? 0), 2).'% |';
        }
        if (! empty($difficultyScoreTotal)) {
            $lines[] = '| difficulty_score (matrix difficulty_score) | '.number_format((float) ($difficultyScoreTotal['score_percent'] ?? 0), 2).'% |';
        }
        $lines[] = '| claim_ready | '.($claimReady ? '**true**' : '**false** (see ZERO claim below)').' |';
        $lines[] = '| external_provider_call | '.(($battery['external_provider_call'] ?? false) ? 'true' : 'false').' |';
        $lines[] = '| separated_from_external_rivals_certification | **always true** |';
        $lines[] = '';

        $lines[] = '## Difficulty ladder (L1 → L5)';
        $lines[] = '';
        $lines[] = '| Level | Weight | Cases | Completed | Failed | Invalid | Skipped | Pending | Score |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- |';
        foreach ($difficultyAggregate as $row) {
            $lines[] = '| '.$row['key'].' | '.number_format(AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight((string) $row['key']), 2)
                .' | '.$row['case_count']
                .' | '.$row['completed']
                .' | '.$row['failed']
                .' | '.$row['invalid']
                .' | '.$row['skipped']
                .' | '.((int) ($row['pending'] ?? 0) + (int) ($row['running'] ?? 0))
                .' | '.number_format((float) $row['weighted_score_percent'], 2).'% |';
        }
        $lines[] = '';

        $lines[] = '## Category breakdown';
        $lines[] = '';
        $lines[] = '| Category | Cases | Completed | Failed | Invalid | Skipped | Pending | Score |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
        foreach ($categoryAggregate as $row) {
            $lines[] = '| `'.$row['key'].'`'
                .' | '.$row['case_count']
                .' | '.$row['completed']
                .' | '.$row['failed']
                .' | '.$row['invalid']
                .' | '.$row['skipped']
                .' | '.((int) ($row['pending'] ?? 0) + (int) ($row['running'] ?? 0))
                .' | '.number_format((float) $row['weighted_score_percent'], 2).'% |';
        }
        $lines[] = '';

        $lines[] = '## Case-by-case';
        $lines[] = '';
        $lines[] = '| # | case_id | category | L | state | verdict | attempts |';
        $lines[] = '| --- | --- | --- | --- | --- | --- | --- |';
        foreach ($cases as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lines[] = '| '.((int) ($row['case_index'] ?? 0) + 1)
                .' | `'.($row['case_id'] ?? '').'`'
                .' | `'.($row['task_category'] ?? '—').'`'
                .' | '.($row['difficulty_level'] ?? '—')
                .' | `'.($row['state'] ?? 'unknown').'`'
                .' | `'.($row['verdict'] ?? '—').'`'
                .' | '.($row['attempts'] ?? 0).' |';
        }
        $lines[] = '';

        $lines[] = '## Claim status';
        $lines[] = '';
        if ($claimReady) {
            $lines[] = '`claim_ready=true` — every case reached `completed`. The battery is internally valid for further auditing.';
            $lines[] = '';
            $lines[] = '**This does NOT unlock `external_rivals_certification`.** That gate is separately governed and stays sealed by this harness.';
        } else {
            $lines[] = '`claim_ready=false`. **ZERO claim** while any case is failed, invalid, skipped, or pending.';
            $lines[] = '';
            $lines[] = '`external_rivals_certification` remains **blocked**. Resume the battery (`atlas:forge:rivals resume --run-id='.$battery['run_id'].'`) or fix the failed cases before reading a winner from this report.';
        }
        $lines[] = '';

        return implode("\n", $lines)."\n";
    }

    private function nowIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
