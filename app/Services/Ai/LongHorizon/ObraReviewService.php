<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Models\AiForgeIntake;
use App\Models\AiForgeMilestone;
use App\Models\AiForgeWorkPacket;
use App\Models\AiForgeWorkPacketExecutionCycle;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;

/**
 * TEOS-I3 · Weekly/Monthly Obra Review.
 *
 * Generates an advisory-only review receipt for Forge Obras. It does not
 * approve, pause, close or mutate an Obra. The receipt tells the operator
 * whether the current evidence suggests continue, adjust scope, pause, or
 * close.
 */
class ObraReviewService
{
    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function review(array $input = []): array
    {
        $intakeRef = $this->stringOrNull($input['intake'] ?? $input['intake_id'] ?? null);
        $now = ($input['now'] ?? null) instanceof CarbonImmutable ? $input['now'] : CarbonImmutable::now();

        if (! DatabaseTableAvailability::has('ai_forge_intakes')) {
            return $this->blocked($intakeRef, $now, 'ai_forge_intakes table is missing');
        }

        $intake = $this->resolveIntake($intakeRef);
        if (! $intake) {
            return $this->blocked($intakeRef, $now, $intakeRef ? 'forge intake not found' : 'no forge intake available');
        }

        $ageDays = CarbonImmutable::parse($intake->created_at)->diffInDays($now);
        $reviewKind = $ageDays >= 30
            ? AtlasLongHorizonCanon::OBRA_REVIEW_MONTHLY_ARCHITECTURE
            : AtlasLongHorizonCanon::OBRA_REVIEW_WEEKLY_SYNTHESIS;

        $milestones = $this->milestones($intake);
        $workPackets = $this->workPackets($intake);
        $cycles = $this->cycles($intake);
        $blockers = $this->blockers($intake, $milestones, $cycles);
        $staleRisks = $this->staleRisks($intake, $now, $cycles);

        $payload = [
            'schema_version' => AtlasLongHorizonCanon::OBRA_REVIEW_RECEIPT_SCHEMA_VERSION,
            'status' => self::STATUS_READY,
            'generated_at' => $now->toJSON(),
            'review_kind' => $reviewKind,
            'intake' => [
                'id' => (string) $intake->id,
                'uuid' => (string) $intake->uuid,
                'obra_title' => (string) $intake->obra_title,
                'status' => (string) $intake->status,
                'risk_band' => (string) $intake->risk_band,
                'age_days' => $ageDays,
            ],
            'summary' => [
                'milestones_total' => count($milestones),
                'milestones_blocked' => count(array_filter($milestones, fn (array $m): bool => $m['status'] === 'blocked')),
                'work_packets_total' => count($workPackets),
                'work_packets_open' => count(array_filter($workPackets, fn (array $p): bool => ! in_array($p['status'], ['done', 'completed', 'closed'], true))),
                'execution_cycles_total' => count($cycles),
                'blockers_count' => count($blockers),
                'stale_risks_count' => count($staleRisks),
            ],
            'weekly_synthesis' => [
                'recent_cycles' => array_slice($cycles, 0, 10),
                'open_work_packets' => array_values(array_filter($workPackets, fn (array $p): bool => ! in_array($p['status'], ['done', 'completed', 'closed'], true))),
                'blockers' => $blockers,
                'next_actions' => $this->nextActions($blockers, $staleRisks),
            ],
            'monthly_architecture_review' => [
                'required' => $reviewKind === AtlasLongHorizonCanon::OBRA_REVIEW_MONTHLY_ARCHITECTURE,
                'spec_vs_reality_signal' => $this->specRealitySignal($intake, $milestones, $workPackets),
                'stale_risks' => $staleRisks,
                'decision_options' => AtlasLongHorizonCanon::OBRA_REVIEW_DECISIONS,
                'recommended_decision' => $this->recommendedDecision($blockers, $staleRisks, $workPackets),
                'operator_decision_required' => $reviewKind === AtlasLongHorizonCanon::OBRA_REVIEW_MONTHLY_ARCHITECTURE,
            ],
            'evidence_refs' => $this->evidenceRefs($intake, $milestones, $workPackets, $cycles),
            'claim_policy' => [
                'advisory_only' => true,
                'does_not_mutate_obra' => true,
                'benchmark_not_run' => true,
                'rivals_compared' => false,
            ],
        ];
        $payload['review_hash'] = $this->hashReview($payload);

        return $payload;
    }

    private function resolveIntake(?string $intakeRef): ?AiForgeIntake
    {
        $query = AiForgeIntake::query()->orderByDesc('created_at');
        if ($intakeRef !== null) {
            $query->where(function ($q) use ($intakeRef): void {
                $q->where('id', $intakeRef)->orWhere('uuid', $intakeRef);
            });
        }

        return $query->first();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function milestones(AiForgeIntake $intake): array
    {
        if (! DatabaseTableAvailability::has('ai_forge_milestones')) {
            return [];
        }

        return AiForgeMilestone::query()
            ->where('intake_id', $intake->id)
            ->orderBy('position')
            ->get()
            ->map(fn (AiForgeMilestone $m): array => [
                'id' => (string) $m->id,
                'milestone_id' => (string) $m->milestone_id,
                'title' => (string) $m->title,
                'status' => (string) $m->status,
                'blocker_reason' => $m->blocker_reason,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function workPackets(AiForgeIntake $intake): array
    {
        if (! DatabaseTableAvailability::has('ai_forge_work_packets')) {
            return [];
        }

        return AiForgeWorkPacket::query()
            ->where('intake_id', $intake->id)
            ->orderBy('packet_position')
            ->get()
            ->map(fn (AiForgeWorkPacket $p): array => [
                'id' => (string) $p->id,
                'packet_id' => (string) $p->packet_id,
                'title' => (string) $p->title,
                'status' => (string) $p->status,
                'risk_band' => (string) $p->risk_band,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function cycles(AiForgeIntake $intake): array
    {
        if (! DatabaseTableAvailability::has('ai_forge_work_packet_execution_cycles')) {
            return [];
        }

        return AiForgeWorkPacketExecutionCycle::query()
            ->where('intake_id', $intake->id)
            ->orderByDesc('cycle_position')
            ->get()
            ->map(fn (AiForgeWorkPacketExecutionCycle $c): array => [
                'id' => (string) $c->id,
                'work_packet_canonical_id' => (string) $c->work_packet_canonical_id,
                'status' => (string) $c->status,
                'outcome_status' => $c->outcome_status,
                'failure_reason_present' => $c->failure_reason !== null,
                'evidence_count' => count((array) ($c->evidence_refs ?? [])),
                'completed_at' => $c->completed_at?->toJSON(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $milestones
     * @param  array<int,array<string,mixed>>  $cycles
     * @return array<int,array<string,mixed>>
     */
    private function blockers(AiForgeIntake $intake, array $milestones, array $cycles): array
    {
        $blockers = [];
        if ($intake->blocker_reason) {
            $blockers[] = ['kind' => 'intake_blocker', 'reason' => $intake->blocker_reason];
        }
        foreach ($milestones as $milestone) {
            if ($milestone['status'] === 'blocked') {
                $blockers[] = [
                    'kind' => 'milestone_blocker',
                    'ref' => $milestone['milestone_id'],
                    'reason' => $milestone['blocker_reason'] ?: 'milestone_blocked',
                ];
            }
        }
        foreach ($cycles as $cycle) {
            if ($cycle['status'] === 'failed' || $cycle['failure_reason_present']) {
                $blockers[] = [
                    'kind' => 'cycle_failure',
                    'ref' => $cycle['work_packet_canonical_id'],
                    'reason' => 'execution_cycle_failed',
                ];
            }
        }

        return $blockers;
    }

    /**
     * @param  array<int,array<string,mixed>>  $cycles
     * @return array<int,array<string,mixed>>
     */
    private function staleRisks(AiForgeIntake $intake, CarbonImmutable $now, array $cycles): array
    {
        $risks = [];
        if ($intake->updated_at && CarbonImmutable::parse($intake->updated_at)->diffInDays($now) >= 14) {
            $risks[] = ['kind' => 'intake_not_updated_recently', 'age_days' => CarbonImmutable::parse($intake->updated_at)->diffInDays($now)];
        }
        if ($cycles === []) {
            $risks[] = ['kind' => 'no_execution_cycles_recorded'];
        }
        foreach ($cycles as $cycle) {
            if ($cycle['status'] === 'completed' && (int) $cycle['evidence_count'] === 0) {
                $risks[] = ['kind' => 'completed_cycle_without_evidence', 'ref' => $cycle['work_packet_canonical_id']];
            }
        }

        return $risks;
    }

    /**
     * @param  array<int,array<string,mixed>>  $blockers
     * @param  array<int,array<string,mixed>>  $staleRisks
     * @return array<int,string>
     */
    private function nextActions(array $blockers, array $staleRisks): array
    {
        if ($blockers !== []) {
            return ['resolve_blockers_before_next_milestone', 'run_continuity_certification_after_resolution'];
        }
        if ($staleRisks !== []) {
            return ['refresh_stale_context', 'emit_new_continuation_pack'];
        }

        return ['continue_current_plan', 'record_next_weekly_synthesis'];
    }

    /**
     * @param  array<int,array<string,mixed>>  $milestones
     * @param  array<int,array<string,mixed>>  $workPackets
     */
    private function specRealitySignal(AiForgeIntake $intake, array $milestones, array $workPackets): string
    {
        if ($intake->status === 'blocked') {
            return 'blocked_intake';
        }
        if ($milestones === [] || $workPackets === []) {
            return 'under_specified';
        }

        return 'aligned_enough_for_review';
    }

    /**
     * @param  array<int,array<string,mixed>>  $blockers
     * @param  array<int,array<string,mixed>>  $staleRisks
     * @param  array<int,array<string,mixed>>  $workPackets
     */
    private function recommendedDecision(array $blockers, array $staleRisks, array $workPackets): string
    {
        if ($blockers !== []) {
            return AtlasLongHorizonCanon::OBRA_REVIEW_DECISION_PAUSE;
        }
        if ($staleRisks !== []) {
            return AtlasLongHorizonCanon::OBRA_REVIEW_DECISION_ADJUST_SCOPE;
        }
        $open = array_filter($workPackets, fn (array $p): bool => ! in_array($p['status'], ['done', 'completed', 'closed'], true));

        return $open === []
            ? AtlasLongHorizonCanon::OBRA_REVIEW_DECISION_CLOSE
            : AtlasLongHorizonCanon::OBRA_REVIEW_DECISION_CONTINUE;
    }

    /**
     * @param  array<int,array<string,mixed>>  $milestones
     * @param  array<int,array<string,mixed>>  $workPackets
     * @param  array<int,array<string,mixed>>  $cycles
     * @return array<int,string>
     */
    private function evidenceRefs(AiForgeIntake $intake, array $milestones, array $workPackets, array $cycles): array
    {
        $refs = ['forge_intake:'.$intake->uuid];
        foreach ($milestones as $milestone) {
            $refs[] = 'forge_milestone:'.$milestone['milestone_id'];
        }
        foreach ($workPackets as $packet) {
            $refs[] = 'forge_work_packet:'.$packet['packet_id'];
        }
        foreach ($cycles as $cycle) {
            $refs[] = 'forge_execution_cycle:'.$cycle['id'];
        }

        return array_values(array_unique($refs));
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(?string $intakeRef, CarbonImmutable $now, string $reason): array
    {
        $payload = [
            'schema_version' => AtlasLongHorizonCanon::OBRA_REVIEW_RECEIPT_SCHEMA_VERSION,
            'status' => self::STATUS_BLOCKED,
            'generated_at' => $now->toJSON(),
            'intake_ref' => $intakeRef,
            'blockers' => [$reason],
            'claim_policy' => [
                'advisory_only' => true,
                'does_not_mutate_obra' => true,
                'benchmark_not_run' => true,
                'rivals_compared' => false,
            ],
        ];
        $payload['review_hash'] = $this->hashReview($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashReview(array $payload): string
    {
        unset($payload['generated_at']);

        return MissionCanonicalHash::sha256($payload);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
