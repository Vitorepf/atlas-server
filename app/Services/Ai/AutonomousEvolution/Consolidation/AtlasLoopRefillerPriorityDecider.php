<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Consolidation;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNextWorkDecider;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSelectAdjuster;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkClassPriorService;
use Throwable;

/**
 * PRIORITY-DECISION collaborator extracted from {@see AtlasLoopQueueRefiller}: turns a discovered
 * target into the {priority, receipt} pair the refill uses to enqueue a task. Keeps the legacy
 * fallback, the work-class-prior nudge, and the SELECT diversity adjuster all on one bench so the
 * refiller stops carrying them. Behavior is BYTE-IDENTICAL to the in-refiller version that lived
 * at lines ~214-329: same null guards, same flag gates, same try/catch fail-open shape.
 */
final class AtlasLoopRefillerPriorityDecider
{
    public function __construct(
        private readonly ?AtlasLoopNextWorkDecider $nextWorkDecider = null,
        private readonly ?AtlasLoopWorkClassPriorService $workClassPrior = null,
        private readonly ?AtlasLoopSelectAdjuster $selectAdjuster = null,
    ) {}

    /**
     * @param  array<string,mixed>  $signals
     * @return array{priority:int, receipt:array<string,mixed>}
     */
    public function decide(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, string $repoRoot, string $shapeHint): array
    {
        $legacy = (int) round(((float) $target->score) * 100);
        if ($this->nextWorkDecider === null || ! $this->frozenDecisionScheme($campaign)) {
            return ['priority' => $legacy, 'receipt' => []];
        }
        try {
            $d = $this->nextWorkDecider->decide($repoRoot, ltrim((string) $target->target_path, '/'), $signals, (float) $target->score, $shapeHint);
            [$priority, $receipt] = $this->applyWorkClassPrior((int) $d['priority'], (array) $d['receipt'], (string) $target->target_path);
            [$priority, $receipt] = $this->applySelectAdjuster($campaign, $target, $signals, $priority, $receipt);

            return ['priority' => $priority, 'receipt' => $receipt];
        } catch (Throwable) {
            return ['priority' => $legacy, 'receipt' => []];
        }
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array{0:int, 1:array<string,mixed>}
     */
    public function applyWorkClassPrior(int $priority, array $receipt, string $targetPath): array
    {
        if ($this->workClassPrior === null || ! (bool) config('atlas.loop.work_class_prior_enabled', false)) {
            return [$priority, $receipt];
        }
        try {
            $band = (int) ($receipt['band'] ?? 0);
            $offset = (int) ($receipt['offset'] ?? max(0, $priority - $band));
            if ($offset <= 0) {
                return [$priority, $receipt];
            }
            $prior = $this->workClassPrior->priorFor($targetPath);
            $weight = $this->workClassPrior->deprioritizationWeight($prior);
            if ($weight <= 0.0) {
                return [$priority, $receipt];
            }
            $maxFraction = max(0.0, min(1.0, (float) config('atlas.loop.work_class_prior_max_penalty_fraction', 0.8)));
            $penalty = (int) round($weight * $maxFraction * $offset);
            $receipt['_work_class_prior'] = [
                'work_class' => $prior['work_class'] ?? null,
                'real_attempts' => $prior['real_attempts'] ?? 0,
                'certified' => $prior['certified'] ?? 0,
                'wilson_lower' => $prior['wilson_lower'] ?? 0.0,
                'hopeless' => $prior['hopeless'] ?? false,
                'penalty' => $penalty,
            ];

            return [$band + max(0, $offset - $penalty), $receipt];
        } catch (Throwable) {
            return [$priority, $receipt];
        }
    }

    /**
     * @param  array<string,mixed>  $signals
     * @param  array<string,mixed>  $receipt
     * @return array{0:int, 1:array<string,mixed>}
     */
    public function applySelectAdjuster(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, int $priority, array $receipt): array
    {
        if ($this->selectAdjuster === null || ! (bool) config('atlas.loop.select_adjuster_enabled', false)) {
            return [$priority, $receipt];
        }
        try {
            $band = (int) ($receipt['band'] ?? 0);
            $offset = max(0, $priority - $band);
            if ($offset <= 0) {
                return [$priority, $receipt];
            }
            $maxFraction = max(0.0, min(1.0, (float) config('atlas.loop.select_adjuster_max_penalty_fraction', 0.5)));
            [$newPriority, $fragment] = $this->selectAdjuster->adjust($campaign, $target, $signals, $band, $offset, $maxFraction);
            if (is_array($fragment)) {
                $receipt['_select_adjuster'] = $fragment;
            }

            return [$newPriority, $receipt];
        } catch (Throwable) {
            return [$priority, $receipt];
        }
    }

    public function frozenDecisionScheme(AtlasLoopCampaign $campaign): bool
    {
        $cfg = is_array($campaign->config) ? $campaign->config : [];
        if (array_key_exists('decision_priority_enabled', $cfg)) {
            return (bool) $cfg['decision_priority_enabled'];
        }

        return (bool) config('atlas.loop.decision_priority_enabled', false);
    }
}
