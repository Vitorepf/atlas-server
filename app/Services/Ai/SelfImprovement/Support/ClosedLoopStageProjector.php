<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement\Support;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementClosedLoopService as ClosedLoop;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService as Backlog;

/**
 * Pure stage-map projection for Self-Improvement Closed Loop (Level 7).
 *
 * No I/O, no Eloquent, no Carbon, no providers. Callers assemble inputs;
 * this class only maps arrays → stage map / health / capability flags.
 */
final class ClosedLoopStageProjector
{
    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $activation
     * @param  array<string,mixed>|null  $obraSnapshot
     * @param  array<string,mixed>|null  $workIntake
     * @param  array<string,mixed>|null  $resultEntry
     * @param  array<string,mixed>|null  $trustSnapshot  reserved (signature parity with service)
     * @param  array<string,mixed>  $nextRecommendation
     * @return array<string,array<string,mixed>>
     */
    public static function computeStages(
        array $proposal,
        ?array $activation,
        ?array $obraSnapshot,
        ?array $workIntake,
        ?array $resultEntry,
        ?array $trustSnapshot,
        array $nextRecommendation,
    ): array {
        unset($trustSnapshot);

        $stages = [];
        foreach (ClosedLoop::STAGES as $stage) {
            $stages[$stage] = [
                'stage' => $stage,
                'status' => 'todo',
                'label' => self::stageLabel($stage),
                'tone' => 'ink',
                'evidence' => null,
                'completed_at' => null,
            ];
        }

        // 1. proposal_captured — always done if proposal exists.
        $stages[ClosedLoop::STAGE_PROPOSAL_CAPTURED]['status'] = 'done';
        $stages[ClosedLoop::STAGE_PROPOSAL_CAPTURED]['tone'] = 'moss';
        $stages[ClosedLoop::STAGE_PROPOSAL_CAPTURED]['completed_at'] = self::stringOrNull($proposal['created_at'] ?? null);
        $stages[ClosedLoop::STAGE_PROPOSAL_CAPTURED]['evidence'] = 'proposal:'.($proposal['proposal_id'] ?? '');

        // 2. power_gate_evaluated.
        $gate = is_array($proposal['power_gate'] ?? null) ? $proposal['power_gate'] : null;
        if ($gate !== null) {
            $outcome = (string) ($gate['outcome'] ?? 'unknown');
            $stages[ClosedLoop::STAGE_POWER_GATE_EVALUATED]['status'] = 'done';
            $stages[ClosedLoop::STAGE_POWER_GATE_EVALUATED]['tone'] = match ($outcome) {
                'approved' => 'moss',
                'human_review_required', 'needs_revision' => 'bronze',
                'rejected' => 'rec-red',
                default => 'ink',
            };
            $stages[ClosedLoop::STAGE_POWER_GATE_EVALUATED]['evidence'] = 'power_gate:'.($gate['gate_id'] ?? 'unknown');
            $stages[ClosedLoop::STAGE_POWER_GATE_EVALUATED]['completed_at'] = self::stringOrNull($proposal['updated_at'] ?? null);

            if (in_array($outcome, ['rejected', 'needs_revision'], true)) {
                $stages[ClosedLoop::STAGE_POWER_GATE_EVALUATED]['status'] = 'blocked';
            }
        }

        // 3. human_approved — true when proposal is APPROVED_FOR_ACTIVATION
        //    or beyond (activated, obra_created, etc).
        $status = (string) $proposal['status'];
        if (in_array($status, [
            Backlog::STATUS_APPROVED_FOR_ACTIVATION,
            Backlog::STATUS_ACTIVATED,
            Backlog::STATUS_OBRA_CREATED,
            Backlog::STATUS_FORGE_RUNNING,
            Backlog::STATUS_AWAITING_REVIEW,
            Backlog::STATUS_MEASURING_DELTA,
            Backlog::STATUS_LEARNED,
        ], true)) {
            $stages[ClosedLoop::STAGE_HUMAN_APPROVED]['status'] = 'done';
            $stages[ClosedLoop::STAGE_HUMAN_APPROVED]['tone'] = 'moss';
            $stages[ClosedLoop::STAGE_HUMAN_APPROVED]['completed_at'] = self::stringOrNull($proposal['updated_at'] ?? null);
        }

        // 4. activation_created.
        if ($activation !== null) {
            $stages[ClosedLoop::STAGE_ACTIVATION_CREATED]['status'] = 'done';
            $stages[ClosedLoop::STAGE_ACTIVATION_CREATED]['tone'] = 'moss';
            $stages[ClosedLoop::STAGE_ACTIVATION_CREATED]['evidence'] = 'activation:'.($activation['activation_id'] ?? '');
            $stages[ClosedLoop::STAGE_ACTIVATION_CREATED]['completed_at'] = self::stringOrNull($activation['generated_at'] ?? null);
        }

        // 5. obra_created.
        if ($obraSnapshot !== null) {
            $stages[ClosedLoop::STAGE_OBRA_CREATED]['status'] = 'done';
            $stages[ClosedLoop::STAGE_OBRA_CREATED]['tone'] = 'moss';
            $stages[ClosedLoop::STAGE_OBRA_CREATED]['evidence'] = 'obra:'.$obraSnapshot['obra_id'];
        }

        // 6. forge_executed — heuristic: linked_fast_path_run_id present.
        if (self::stringOrNull($proposal['linked_fast_path_run_id'] ?? null) !== null) {
            $stages[ClosedLoop::STAGE_FORGE_EXECUTED]['status'] = 'done';
            $stages[ClosedLoop::STAGE_FORGE_EXECUTED]['tone'] = 'moss';
            $stages[ClosedLoop::STAGE_FORGE_EXECUTED]['evidence'] = 'fast_path_run:'.$proposal['linked_fast_path_run_id'];
        }

        // 7. evidence_collected — workIntake AND/OR evidence_refs.
        $evidenceCount = count((array) ($proposal['evidence_refs'] ?? []));
        if ($workIntake !== null || $evidenceCount > 0) {
            $stages[ClosedLoop::STAGE_EVIDENCE_COLLECTED]['status'] = 'done';
            $stages[ClosedLoop::STAGE_EVIDENCE_COLLECTED]['tone'] = 'moss';
            $stages[ClosedLoop::STAGE_EVIDENCE_COLLECTED]['evidence'] = 'evidence_refs_count:'.$evidenceCount;
        }

        // 8. human_reviewed — heuristic: linked_completion_claim_id present OR
        //    review_outcome in resultEntry.
        $reviewOutcome = $resultEntry !== null
            ? self::stringOrNull($resultEntry['human_review_outcome'] ?? null)
            : null;
        $completionClaimId = self::stringOrNull($proposal['linked_completion_claim_id'] ?? null);
        if ($reviewOutcome !== null || $completionClaimId !== null) {
            $stages[ClosedLoop::STAGE_HUMAN_REVIEWED]['status'] = 'done';
            $stages[ClosedLoop::STAGE_HUMAN_REVIEWED]['tone'] = 'moss';
            $stages[ClosedLoop::STAGE_HUMAN_REVIEWED]['evidence'] = $completionClaimId !== null
                ? 'completion_claim:'.$completionClaimId
                : 'review_outcome:'.$reviewOutcome;
        }

        // 9. delta_measured.
        if ($resultEntry !== null) {
            $grade = (string) ($resultEntry['delta_grade'] ?? 'unknown');
            $stages[ClosedLoop::STAGE_DELTA_MEASURED]['status'] = 'done';
            $stages[ClosedLoop::STAGE_DELTA_MEASURED]['tone'] = match ($grade) {
                'major_improvement', 'improved' => 'moss',
                'neutral' => 'bronze',
                'regressed', 'invalid' => 'rec-red',
                default => 'ink',
            };
            $stages[ClosedLoop::STAGE_DELTA_MEASURED]['evidence'] = 'result_entry:'.($resultEntry['result_entry_id'] ?? '');
            $stages[ClosedLoop::STAGE_DELTA_MEASURED]['completed_at'] = self::stringOrNull($resultEntry['recorded_at'] ?? null);
        }

        // 10. trust_updated.
        if ($resultEntry !== null && self::stringOrNull($resultEntry['trust_outcome_recorded'] ?? null) !== null) {
            $stages[ClosedLoop::STAGE_TRUST_UPDATED]['status'] = 'done';
            $stages[ClosedLoop::STAGE_TRUST_UPDATED]['tone'] = 'moss';
            $stages[ClosedLoop::STAGE_TRUST_UPDATED]['evidence'] = 'trust_outcome:'.$resultEntry['trust_outcome_recorded'];
        }

        // 11. learning_recorded.
        if (is_array($resultEntry['learning_packet'] ?? null)) {
            $stages[ClosedLoop::STAGE_LEARNING_RECORDED]['status'] = 'done';
            $stages[ClosedLoop::STAGE_LEARNING_RECORDED]['tone'] = 'moss';
            $stages[ClosedLoop::STAGE_LEARNING_RECORDED]['evidence'] = 'learning_packet:'.(data_get($resultEntry, 'learning_packet.what_changed') ?? 'unspecified');
        }

        // 12. next_cycle_recommended.
        if (self::stringOrNull($nextRecommendation['recommendation'] ?? null) !== null) {
            $stages[ClosedLoop::STAGE_NEXT_CYCLE_RECOMMENDED]['status'] = 'done';
            $stages[ClosedLoop::STAGE_NEXT_CYCLE_RECOMMENDED]['tone'] = 'moss';
            $stages[ClosedLoop::STAGE_NEXT_CYCLE_RECOMMENDED]['evidence'] = 'recommendation:'.$nextRecommendation['recommendation'];
        }

        return $stages;
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     */
    public static function resolveCurrentStage(array $stages): string
    {
        $current = ClosedLoop::STAGE_PROPOSAL_CAPTURED;
        foreach (ClosedLoop::STAGES as $stage) {
            if (($stages[$stage]['status'] ?? 'todo') === 'done') {
                $current = $stage;
            }
        }

        return $current;
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @param  array<string,mixed>|null  $latestEntry
     * @param  list<string>  $blockers
     */
    public static function resolveLoopHealth(array $stages, ?array $latestEntry, array $blockers): string
    {
        if ($blockers !== []) {
            return 'blocked';
        }
        foreach ($stages as $stage) {
            if (($stage['status'] ?? 'todo') === 'blocked') {
                return 'blocked';
            }
        }
        if ($latestEntry !== null && in_array((string) ($latestEntry['delta_grade'] ?? ''), ['regressed', 'invalid'], true)) {
            return 'regressed';
        }
        if (($stages[ClosedLoop::STAGE_NEXT_CYCLE_RECOMMENDED]['status'] ?? 'todo') === 'done') {
            return 'closed_loop_complete';
        }
        if (($stages[ClosedLoop::STAGE_DELTA_MEASURED]['status'] ?? 'todo') === 'done') {
            return 'measured';
        }
        if (($stages[ClosedLoop::STAGE_OBRA_CREATED]['status'] ?? 'todo') === 'done') {
            return 'in_flight';
        }
        if (($stages[ClosedLoop::STAGE_HUMAN_APPROVED]['status'] ?? 'todo') === 'done') {
            return 'approved_pending_activation';
        }
        if (($stages[ClosedLoop::STAGE_POWER_GATE_EVALUATED]['status'] ?? 'todo') === 'done') {
            return 'evaluating';
        }

        return 'draft';
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $latestEntry
     */
    public static function humanDecisionRequired(array $stages, array $proposal, ?array $latestEntry): bool
    {
        $status = (string) $proposal['status'];
        if ($status === Backlog::STATUS_PENDING_HUMAN_REVIEW) {
            return true;
        }
        if ($status === Backlog::STATUS_APPROVED_FOR_ACTIVATION) {
            return true;
        }
        if (($stages[ClosedLoop::STAGE_OBRA_CREATED]['status'] ?? 'todo') === 'done'
            && ($stages[ClosedLoop::STAGE_DELTA_MEASURED]['status'] ?? 'todo') !== 'done') {
            return true;
        }
        if ($latestEntry !== null && in_array((string) ($latestEntry['delta_grade'] ?? ''), ['regressed', 'invalid'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $activation
     */
    public static function canActivate(array $proposal, ?array $activation): bool
    {
        $status = (string) $proposal['status'];
        if (in_array($status, [
            Backlog::STATUS_REJECTED,
            Backlog::STATUS_ARCHIVED,
        ], true)) {
            return false;
        }
        if ($activation !== null && self::stringOrNull($activation['created_obra_id'] ?? null) !== null) {
            return false;
        }
        if (! in_array($status, [
            Backlog::STATUS_APPROVED_FOR_ACTIVATION,
            Backlog::STATUS_PENDING_HUMAN_REVIEW,
        ], true)) {
            return false;
        }

        return ((array) ($proposal['blockers'] ?? [])) === [];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $latestEntry
     */
    public static function canMeasureDelta(array $proposal, ?string $obraId, ?array $latestEntry): bool
    {
        if ($obraId === null) {
            return false;
        }
        if ($latestEntry !== null) {
            return false;
        }
        if (((array) ($proposal['blockers'] ?? [])) !== []) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function emptyStageMap(): array
    {
        $map = [];
        foreach (ClosedLoop::STAGES as $stage) {
            $map[$stage] = [
                'stage' => $stage,
                'status' => 'todo',
                'label' => self::stageLabel($stage),
                'tone' => 'ink',
                'evidence' => null,
                'completed_at' => null,
            ];
        }

        return $map;
    }

    public static function stageLabel(string $stage): string
    {
        return match ($stage) {
            ClosedLoop::STAGE_PROPOSAL_CAPTURED => 'Proposta capturada',
            ClosedLoop::STAGE_POWER_GATE_EVALUATED => 'Power Gate avaliado',
            ClosedLoop::STAGE_HUMAN_APPROVED => 'Humano aprovou',
            ClosedLoop::STAGE_ACTIVATION_CREATED => 'Activation criada',
            ClosedLoop::STAGE_OBRA_CREATED => 'Obra materializada',
            ClosedLoop::STAGE_FORGE_EXECUTED => 'Forge executou',
            ClosedLoop::STAGE_EVIDENCE_COLLECTED => 'Evidências coletadas',
            ClosedLoop::STAGE_HUMAN_REVIEWED => 'Humano revisou',
            ClosedLoop::STAGE_DELTA_MEASURED => 'Delta medido',
            ClosedLoop::STAGE_TRUST_UPDATED => 'Trust ledger atualizado',
            ClosedLoop::STAGE_LEARNING_RECORDED => 'Aprendizado registrado',
            ClosedLoop::STAGE_NEXT_CYCLE_RECOMMENDED => 'Próximo ciclo recomendado',
            default => $stage,
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
