<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use Illuminate\Support\Carbon;

/**
 * Atlas Self-Improvement Next Cycle Recommendation v1.
 *
 * Deterministic recommender that proposes the next safe move after a
 * result entry has been recorded. NEVER creates a proposal automatically;
 * always returns a payload draft that the operator must explicitly accept
 * by calling `AtlasSelfImprovementProposalBacklogService::createProposal`.
 *
 * Hard rules:
 *   - `human_approval_required` = true ALWAYS.
 *   - `auto_activation_allowed` = false ALWAYS.
 *   - NEVER calls a provider.
 *   - NEVER promotes completion claim.
 *   - NEVER reuses a proposal_id automatically (suggests `from_proposal_id`
 *     so the human can spec a follow-up).
 *
 * Schema: atlas.self_improvement.next_cycle_recommendation.v1
 */
class AtlasSelfImprovementNextCycleRecommendationService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.next_cycle_recommendation.v1';

    public const REC_CONTINUE_SAME_CAPABILITY = 'continue_same_capability';
    public const REC_BROADEN_SCOPE = 'broaden_scope';
    public const REC_REPAIR_REGRESSION = 'repair_regression';
    public const REC_GATHER_MORE_EVIDENCE = 'gather_more_evidence';
    public const REC_ARCHIVE_LOW_VALUE = 'archive_low_value';
    public const REC_PROMOTE_RULE_CANDIDATE = 'promote_rule_candidate';
    public const REC_CREATE_FOLLOWUP_PROPOSAL = 'create_followup_proposal';

    public function __construct(
        private readonly AtlasSelfImprovementStrategyPortfolioService $strategyPortfolio,
    ) {}

    /**
     * Build a recommendation from the latest result entry + portfolio snap.
     *
     * @param  array<string,mixed>|null  $resultEntry  Output of
     *                                                 ResultLedgerService::record
     * @param  array<string,mixed>|null  $proposalItem Output of
     *                                                 ProposalBacklogService::getProposal
     * @return array<string,mixed>
     */
    public function recommend(?array $resultEntry, ?array $proposalItem): array
    {
        if ($resultEntry === null || ($resultEntry['delta_grade'] ?? null) === null) {
            return $this->emptyRecommendation('no_result_entry_available');
        }

        $grade = (string) $resultEntry['delta_grade'];
        $portfolio = $this->strategyPortfolio->snapshot([]);
        $balance = (string) ($portfolio['balance_health'] ?? 'unknown');
        $nextBucket = $portfolio['recommended_next_bucket'] ?? null;

        $recommendation = $this->resolveRecommendation($grade, $resultEntry);
        $rationale = $this->resolveRationale($recommendation, $grade, $balance, $nextBucket);
        $confidence = $this->resolveConfidence($grade, $resultEntry);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'recommendation' => $recommendation,
            'rationale' => $rationale,
            'confidence' => $confidence,
            'linked_result_entry_id' => $this->stringOrNull($resultEntry['result_entry_id'] ?? null),
            'linked_proposal_id' => $this->stringOrNull($resultEntry['proposal_id'] ?? null),
            'linked_obra_id' => $this->stringOrNull($resultEntry['obra_id'] ?? null),
            'portfolio_balance_health' => $balance,
            'portfolio_recommended_next_bucket' => $nextBucket,
            'proposed_next_proposal_payload' => $this->buildPayloadDraft($recommendation, $resultEntry, $proposalItem),
            'human_approval_required' => true,
            'auto_activation_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $resultEntry
     */
    private function resolveRecommendation(string $grade, array $resultEntry): string
    {
        $shouldBecomeRule = (bool) ($resultEntry['should_become_rule'] ?? false);
        if ($shouldBecomeRule) {
            return self::REC_PROMOTE_RULE_CANDIDATE;
        }

        return match ($grade) {
            AtlasSelfImprovementResultLedgerService::GRADE_MAJOR_IMPROVEMENT => self::REC_BROADEN_SCOPE,
            AtlasSelfImprovementResultLedgerService::GRADE_IMPROVED => self::REC_CONTINUE_SAME_CAPABILITY,
            AtlasSelfImprovementResultLedgerService::GRADE_NEUTRAL => self::REC_GATHER_MORE_EVIDENCE,
            AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED => self::REC_REPAIR_REGRESSION,
            AtlasSelfImprovementResultLedgerService::GRADE_INVALID => self::REC_GATHER_MORE_EVIDENCE,
            default => self::REC_ARCHIVE_LOW_VALUE,
        };
    }

    private function resolveRationale(string $recommendation, string $grade, string $balance, ?string $nextBucket): string
    {
        $bucketHint = is_string($nextBucket) && $nextBucket !== '' ? ' (portfolio underweight em '.$nextBucket.')' : '';

        return match ($recommendation) {
            self::REC_PROMOTE_RULE_CANDIDATE => 'Resultado promoveu candidato a regra com confiança alta — humano deve revisar antes de publicar.',
            self::REC_BROADEN_SCOPE => 'Major improvement validado — ampliar escopo dentro da mesma capacidade'.$bucketHint.'.',
            self::REC_CONTINUE_SAME_CAPABILITY => 'Improvement positivo — repetir o ciclo com afinação incremental.',
            self::REC_GATHER_MORE_EVIDENCE => 'Resultado neutro ou evidência insuficiente — coletar mais antes de iterar.',
            self::REC_REPAIR_REGRESSION => 'Regressão detectada (grade='.$grade.') — reparar antes de qualquer próximo ciclo.',
            self::REC_ARCHIVE_LOW_VALUE => 'Sem sinal positivo recorrente — arquivar para evitar dispersão; portfolio '.$balance.'.',
            self::REC_CREATE_FOLLOWUP_PROPOSAL => 'Sugestão de proposta de follow-up baseada no aprendizado registrado.',
            default => 'Inspecionar manualmente.',
        };
    }

    /**
     * @param  array<string,mixed>  $resultEntry
     */
    private function resolveConfidence(string $grade, array $resultEntry): float
    {
        $base = match ($grade) {
            AtlasSelfImprovementResultLedgerService::GRADE_MAJOR_IMPROVEMENT => 0.9,
            AtlasSelfImprovementResultLedgerService::GRADE_IMPROVED => 0.7,
            AtlasSelfImprovementResultLedgerService::GRADE_NEUTRAL => 0.4,
            AtlasSelfImprovementResultLedgerService::GRADE_REGRESSED => 0.6,
            AtlasSelfImprovementResultLedgerService::GRADE_INVALID => 0.2,
            default => 0.3,
        };
        $packetConfidence = (float) (data_get($resultEntry, 'learning_packet.confidence') ?? $base);

        return round(min(1.0, max(0.0, ($base + $packetConfidence) / 2.0)), 2);
    }

    /**
     * Build a draft payload the operator can refine and submit explicitly.
     * Never persists. Never reuses the parent proposal_id.
     *
     * @param  array<string,mixed>  $resultEntry
     * @param  array<string,mixed>|null  $proposalItem
     * @return array<string,mixed>|null
     */
    private function buildPayloadDraft(string $recommendation, array $resultEntry, ?array $proposalItem): ?array
    {
        if (in_array($recommendation, [self::REC_ARCHIVE_LOW_VALUE], true)) {
            return null;
        }
        $parentTitle = $this->stringOrNull(data_get($proposalItem, 'title')) ?? 'untitled proposal';
        $parentCapability = $this->stringOrNull(data_get($proposalItem, 'target_capability'));
        $titleSuffix = match ($recommendation) {
            self::REC_BROADEN_SCOPE => '— follow-up: broaden scope',
            self::REC_CONTINUE_SAME_CAPABILITY => '— follow-up: refine',
            self::REC_REPAIR_REGRESSION => '— repair regression',
            self::REC_GATHER_MORE_EVIDENCE => '— evidence gathering',
            self::REC_PROMOTE_RULE_CANDIDATE => '— promote rule candidate',
            self::REC_CREATE_FOLLOWUP_PROPOSAL => '— follow-up proposal',
            default => '— follow-up',
        };

        $blockers = (array) data_get($resultEntry, 'regressions_detected', []);
        $rollback = $this->stringOrNull(data_get($resultEntry, 'learning_packet.rollback_recommendation'));

        return [
            'source' => 'operator',
            'from_proposal_id' => $this->stringOrNull($resultEntry['proposal_id'] ?? null),
            'from_result_entry_id' => $this->stringOrNull($resultEntry['result_entry_id'] ?? null),
            'title' => $parentTitle.' '.$titleSuffix,
            'problem_statement' => (string) (data_get($proposalItem, 'summary') ?? ''),
            'business_rule' => (string) (data_get($proposalItem, 'business_rule') ?? ''),
            'target_capability' => $parentCapability,
            'expected_power_gain' => $this->stringOrNull(data_get($proposalItem, 'expected_power_gain')),
            'risk_level' => $recommendation === self::REC_REPAIR_REGRESSION ? 'high' : 'medium',
            'canonical_docs' => array_values((array) data_get($proposalItem, 'canonical_docs', [])),
            'acceptance_gates' => array_values((array) data_get($proposalItem, 'acceptance_criteria', [])),
            'blockers_to_address' => array_values($blockers),
            'rollback_strategy' => $rollback,
            'human_review_required' => true,
            'autopromotion_requested' => false,
        ];
    }

    private function emptyRecommendation(string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'recommendation' => null,
            'rationale' => 'Sem resultado medido para recomendar — '.$reason,
            'confidence' => 0.0,
            'linked_result_entry_id' => null,
            'linked_proposal_id' => null,
            'linked_obra_id' => null,
            'portfolio_balance_health' => null,
            'portfolio_recommended_next_bucket' => null,
            'proposed_next_proposal_payload' => null,
            'human_approval_required' => true,
            'auto_activation_allowed' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
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
