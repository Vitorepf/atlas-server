<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Metrics;

use DateTimeImmutable;
use FilesystemIterator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * E2E CYCLE LIVENESS — an HONEST end-to-end "is the loop's whole pipeline alive" metric.
 *
 * It answers ONE question, ungameably: of the 5 canonical loop phases (loop-final-state-vision +
 * loop-delivery-pipeline-design-dominant), HOW MANY emitted REAL evidence in the last {@see WINDOW_DAYS} days?
 *
 *   F1 COMPREHEND          — any file under storage/app/atlas/loop/projection-outcomes/ touched within window
 *   F2 ORIGINATE           — a row in atlas_loop_origination_outcomes within window
 *   F3 DECOMPOSE           — a row in atlas_loop_decomposition_outcomes within window
 *   F4 DELIVERY-CONTRACT   — a row in atlas_loop_delivery_contracts within window
 *   F5 PROPOSAL-CERTIFIED  — a certified proposal (atlas_loop_proposals) whose terminal state moved within window
 *
 * ANTI-GOODHART (load-bearing):
 *   - The score is an INTEGER 0..5 = a plain COUNT of live phases. NEVER a percentage, NEVER a weighted mean
 *     (any weighting would invite gaming the cheap phases).
 *   - Each phase is a single boolean = "REAL evidence recorded in the window". The mere EXISTENCE of a campaign,
 *     a generated task, or a loaded class is NOT liveness of any phase — only a recorded OUTCOME counts (a task
 *     generated is not an outcome stamped; a green class_exists is not a delivery).
 *   - The window is HARD: a record outside the window does not count.
 *
 * The four outcome ledgers (origination/decomposition/delivery + the projection-outcomes dir) carry NO
 * campaign_id by schema — they are global loop telemetry. So this metric reports whether each canonical phase
 * is alive ANYWHERE in the window; campaign_id is the report subject, echoed in the envelope. Read-only +
 * provider-free: it never writes, never grades a model's self-claim.
 */
final class AtlasLoopE2ECycleLiveness
{
    public const SCHEMA = 'atlas.loop.e2e_cycle_liveness.v1';

    public const WINDOW_DAYS = 7;

    /** FIXED phase order — first_dead_phase walks F1..F5 in exactly this sequence. */
    private const PHASE_ORDER = ['comprehend', 'originate', 'decompose', 'delivery_contract', 'proposal_certified'];

    /**
     * F5 strategy: a proposal whose status reached a certified/promoted terminal state. Production FORCES
     * 'certified_for_review' (AtlasLoopProposal::STATUS_CERTIFIED — the table only ever holds certified
     * proposals); 'certified'/'promoted' are accepted for forward-compat. There is no certified_at column, so
     * the status-IN check is the SINGLE source — no silent fallback.
     *
     * @var list<string>
     */
    private const CERTIFIED_STATUSES = ['certified_for_review', 'certified', 'promoted'];

    private readonly string $projectionOutcomesDir;

    /** The projection-outcomes dir is a path SEAM (default = the real storage path); tests point it at a tmp dir. */
    public function __construct(?string $projectionOutcomesDir = null)
    {
        $this->projectionOutcomesDir = $projectionOutcomesDir ?? storage_path('app/atlas/loop/projection-outcomes');
    }

    /**
     * @return array{schema:string, campaign_id:string, phases:array{comprehend:bool, originate:bool, decompose:bool, delivery_contract:bool, proposal_certified:bool}, score:int, window_days:int, first_dead_phase:?string, computed_at:string}
     */
    public function measure(string $campaignId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Carbon::now()->toImmutable();
        $cutoff = $now->modify('-'.self::WINDOW_DAYS.' days');
        $cutoffStr = $cutoff->format('Y-m-d H:i:s');

        $phases = [
            'comprehend' => $this->comprehendAlive($cutoff->getTimestamp()),
            'originate' => $this->rowInWindow('atlas_loop_origination_outcomes', 'created_at', $cutoffStr),
            'decompose' => $this->rowInWindow('atlas_loop_decomposition_outcomes', 'created_at', $cutoffStr),
            'delivery_contract' => $this->rowInWindow('atlas_loop_delivery_contracts', 'created_at', $cutoffStr),
            'proposal_certified' => $this->certifiedProposalInWindow($cutoffStr),
        ];

        $score = count(array_filter($phases));

        $firstDead = null;
        foreach (self::PHASE_ORDER as $phase) {
            if (! $phases[$phase]) {
                $firstDead = $phase;
                break;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'campaign_id' => $campaignId,
            'phases' => $phases,
            'score' => $score,
            'window_days' => self::WINDOW_DAYS,
            'first_dead_phase' => $firstDead,
            'computed_at' => $now->format(DateTimeImmutable::ATOM),
        ];
    }

    /** F1 COMPREHEND — any projection-outcome file modified within the window. Pure filesystem stat, no DB. */
    private function comprehendAlive(int $cutoffTs): bool
    {
        if (! is_dir($this->projectionOutcomesDir)) {
            return false;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectionOutcomesDir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getMTime() >= $cutoffTs) {
                return true;
            }
        }

        return false;
    }

    /** A read-only "does a row exist with $column within the window" check. */
    private function rowInWindow(string $table, string $column, string $cutoffStr): bool
    {
        return DB::table($table)->where($column, '>=', $cutoffStr)->exists();
    }

    /** F5 — a certified/promoted proposal whose terminal state moved within the window (status-IN strategy). */
    private function certifiedProposalInWindow(string $cutoffStr): bool
    {
        return DB::table('atlas_loop_proposals')
            ->whereIn('status', self::CERTIFIED_STATUSES)
            ->where('updated_at', '>=', $cutoffStr)
            ->exists();
    }
}
