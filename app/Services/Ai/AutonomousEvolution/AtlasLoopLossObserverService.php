<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * L4-4 · daily loss observer for the autonomous Loop.
 *
 * Reads the durable loop audit trail, detects repeated rejection/gate reasons, and
 * turns the dominant loss pattern into an addressable backlog intent. No provider,
 * no policy decisions: just evidence -> threshold -> deduped manifest item.
 */
final class AtlasLoopLossObserverService
{
    public const SCHEMA_VERSION = 'atlas.loop.loss_observer.v1';

    public function __construct(
        private readonly AtlasLoopFunnelService $funnel,
        private readonly AtlasLoopBacklogManifestService $manifest,
        private readonly ?AtlasLoopHarnessGuard $guard = null,
    ) {}

    /**
     * @param  array{window_hours?:int,min_occurrences?:int,write?:bool,manifest_path?:string,manifest_limit?:int}  $options
     * @return array<string,mixed>
     */
    public function observe(?string $campaignId = null, array $options = []): array
    {
        $cfg = (array) config('atlas.loop.loss_observer', []);
        $windowHours = max(1, (int) ($options['window_hours'] ?? $cfg['window_hours'] ?? 24));
        $minOccurrences = max(2, (int) ($options['min_occurrences'] ?? $cfg['min_occurrences'] ?? 3));
        $write = (bool) ($options['write'] ?? true);
        $manifestPath = (string) ($options['manifest_path'] ?? $this->manifest->defaultPath());
        $manifestLimit = max(10, (int) ($options['manifest_limit'] ?? $cfg['manifest_limit'] ?? 200));
        $funnel = $this->funnel->snapshot($campaignId);
        $patterns = $this->dominantPatterns($campaignId, $windowHours, $minOccurrences);

        $guard = $this->guard ?? new AtlasLoopHarnessGuard;
        // SELF-IMPROVEMENT ladder (Lever 4 — "ADEP improves ADEP"): a dominant loss whose target IS one of
        // the loop's OWN pipeline files is the loop improving itself. Two invariants from the harness guard
        // govern it: (1) the petreous set (frozen judge, gates, certifier, never-merge) is NEVER a target —
        // the defendant cannot be dispatched to fix the judge, so it is dropped outright; (2) other harness
        // files become a self-improvement intent — held to the ≥9 bar at cert — only when BOTH meta flags
        // are ON. Otherwise (flags off, or a non-harness target) it stays an ordinary backlog intent.
        $selfImproveEnabled = (bool) config('atlas.loop.meta_harness_targets', false)
            && (bool) config('atlas.loop.meta_harness_self_improve.enabled', false);
        $actions = [];
        foreach ($patterns as $pattern) {
            $path = (string) ($pattern['target_path'] ?? '');
            if ($path === '') {
                continue;
            }
            if ($guard->isForbiddenSelfTarget($path)) {
                $actions[] = ['status' => 'skipped', 'reason' => 'harness_guard:forbidden', 'target_path' => $path];

                continue;
            }
            $selfImprove = $selfImproveEnabled && $guard->isHarnessTarget($path);
            $actions[] = $this->appendBacklogIntent($manifestPath, $pattern, $windowHours, $manifestLimit, $write, $selfImprove);
        }

        // Honest status: a DROPPED (skipped) target — e.g. a petreous self-target the guard refused — is
        // observed, not acted. "acted" requires a real manifest touch (enqueued/duplicate).
        $effectiveActions = array_values(array_filter(
            $actions,
            static fn (array $a): bool => ($a['status'] ?? '') !== 'skipped',
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $effectiveActions !== [] ? ($write ? 'acted' : 'dry_run') : ($patterns !== [] ? 'observed' : 'clear'),
            'campaign_id' => $campaignId,
            'window_hours' => $windowHours,
            'min_occurrences' => $minOccurrences,
            'funnel' => $funnel,
            'dominant_patterns' => $patterns,
            'dominant_count' => count($patterns),
            'actions' => $actions,
            'actions_count' => count($actions),
            'manifest_path' => $manifestPath,
            'writes_enabled' => $write,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function dominantPatterns(?string $campaignId, int $windowHours, int $minOccurrences): array
    {
        if (! DatabaseTableAvailability::has('atlas_loop_explorations')
            || ! DatabaseTableAvailability::has('atlas_loop_tasks')) {
            return [];
        }

        $since = Carbon::now()->subHours($windowHours);
        try {
            $query = DB::table('atlas_loop_explorations as e')
                ->leftJoin('atlas_loop_tasks as t', 't.id', '=', 'e.task_id')
                ->where('e.created_at', '>=', $since)
                ->select([
                    'e.campaign_id',
                    'e.rejected_reasons',
                    'e.objective',
                    't.target_path',
                    't.result',
                ]);
            if ($campaignId !== null && $campaignId !== '') {
                $query->where('e.campaign_id', $campaignId);
            }

            $rows = $query->get();
        } catch (Throwable) {
            return [];
        }

        $groups = [];
        foreach ($rows as $row) {
            $path = trim((string) ($row->target_path ?? ''));
            $reasons = array_merge(
                $this->stringList($row->rejected_reasons ?? null),
                $this->taskResultReasons($row->result ?? null),
            );
            foreach (array_unique(array_filter(array_map([$this, 'normalizeReason'], $reasons))) as $reason) {
                if (! $this->isLossReason($reason)) {
                    continue;
                }
                $groups[$reason] ??= [
                    'reason' => $reason,
                    'reason_family' => $this->reasonFamily($reason),
                    'occurrences' => 0,
                    'target_counts' => [],
                    'campaign_ids' => [],
                ];
                $groups[$reason]['occurrences']++;
                $groups[$reason]['campaign_ids'][(string) $row->campaign_id] = true;
                if ($path !== '') {
                    $groups[$reason]['target_counts'][$path] = ($groups[$reason]['target_counts'][$path] ?? 0) + 1;
                }
            }
        }

        $patterns = [];
        foreach ($groups as $group) {
            if ((int) $group['occurrences'] < $minOccurrences) {
                continue;
            }
            arsort($group['target_counts']);
            // Emit ONE pattern PER affected target — not only the most-frequent one. A loss reason that
            // spans N distinct files is N pieces of heavy-refactor re-attack work; keeping only the top
            // path silently DROPPED the other N-1 (live: complexity_not_reduced spanned 6 files, 5 lost)
            // AND mis-reported the survivor's `occurrences` as the inflated cross-path sum. Each path now
            // carries its OWN honest hit count; the reason-group still had to clear the dominance gate,
            // and downstream dedup (source_key) + the auto-feeder caps keep the emission bounded.
            foreach ($group['target_counts'] as $targetPath => $hits) {
                $patterns[] = [
                    'reason' => (string) $group['reason'],
                    'reason_family' => (string) $group['reason_family'],
                    'occurrences' => (int) $hits,
                    'target_path' => (string) $targetPath,
                    'target_hits' => (int) $hits,
                    'campaign_ids' => array_keys($group['campaign_ids']),
                ];
            }
        }

        usort($patterns, static fn (array $a, array $b): int => [$b['occurrences'], $b['target_hits']] <=> [$a['occurrences'], $a['target_hits']]);

        return $patterns;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $v): string => trim((string) $v), $value)));
    }

    /**
     * @return list<string>
     */
    private function taskResultReasons(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            $value = $decoded;
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach (['reason', 'error'] as $key) {
            $raw = trim((string) ($value[$key] ?? ''));
            if ($raw !== '') {
                $out[] = $raw;
            }
        }
        foreach (['implementation_gate.reports', 'semantic_implementation_certification.reports'] as $path) {
            foreach ((array) data_get($value, $path, []) as $report) {
                foreach ((array) data_get($report, 'reasons', []) as $reason) {
                    $raw = trim((string) $reason);
                    if ($raw !== '') {
                        $out[] = $raw;
                    }
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeReason(string $reason): string
    {
        $reason = preg_replace('/\s+/', ' ', trim($reason)) ?? '';

        return mb_substr($reason, 0, 180);
    }

    private function reasonFamily(string $reason): string
    {
        $family = preg_replace('/[:\s].*$/', '', $reason) ?? $reason;

        return $family !== '' ? mb_substr($family, 0, 80) : 'unknown';
    }

    private function isLossReason(string $reason): bool
    {
        $lower = mb_strtolower(trim($reason));
        if ($lower === '') {
            return false;
        }

        return ! in_array($lower, ['accepted', 'certified', 'ok', 'pass', 'passed', 'success', 'verifier_clean', 'winner'], true);
    }

    /**
     * @param  array<string,mixed>  $pattern
     * @return array<string,mixed>
     */
    private function appendBacklogIntent(string $manifestPath, array $pattern, int $windowHours, int $manifestLimit, bool $write, bool $selfImprove = false): array
    {
        $source = $selfImprove ? 'self_improve' : 'loss_observer';
        $sourceKey = hash('sha256', $source.'|'.$pattern['target_path'].'|'.$pattern['reason']);
        $priority = round(min(1.0, 0.72 + min(0.24, ((int) $pattern['occurrences']) * 0.04)), 4);
        $objective = $selfImprove
            ? sprintf(
                'AUTO-MELHORIA do próprio pipeline do Loop (ADEP melhora ADEP): atacar %s em %s (%d ocorrências/%dh). '
                .'Gated ≥%.1f — tocar o próprio cérebro só passa com qualidade máxima e o conjunto pétreo é intocável.',
                (string) $pattern['reason'],
                (string) $pattern['target_path'],
                (int) $pattern['occurrences'],
                $windowHours,
                (float) config('atlas.loop.quality_bar', AtlasLoopQualityGrader::DEFAULT_BAR),
            )
            : sprintf(
                'Corrigir padrão dominante do Loop: %s em %s (%d ocorrências nas últimas %dh).',
                (string) $pattern['reason'],
                (string) $pattern['target_path'],
                (int) $pattern['occurrences'],
                $windowHours,
            );
        $item = [
            'path' => (string) $pattern['target_path'],
            'objective' => $objective,
            'priority' => $priority,
            'source' => $source,
            'source_key' => $sourceKey,
            'reason' => (string) $pattern['reason'],
            'occurrences' => (int) $pattern['occurrences'],
            'observed_window_hours' => $windowHours,
            'observed_at' => Carbon::now()->toIso8601String(),
        ];
        if ($selfImprove) {
            // The ≥9 self-improvement contract the certifier (per-task bar) enforces, plus the marker the
            // self-improvement objective builder keys on. Touching the loop's own pipeline only at max quality.
            $item['is_self_improvement'] = true;
            $item['quality_bar_gate'] = true;
            $item['quality_bar'] = (float) config('atlas.loop.quality_bar', AtlasLoopQualityGrader::DEFAULT_BAR);
        }

        return $this->manifest->append($manifestPath, $item, $manifestLimit, $write);
    }
}
