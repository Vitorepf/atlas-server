<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Armor;

use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\Foundry\Frontier\Ports\FrontierJudgePort;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Foundry · Frontier armor stage I3 — Judge Panel (AP-C).
 *
 * A HARD adversarial gate. A generated evolution proposal is adjudicated by a
 * panel of judge seats that are DIFFERENT from the generator. The panel is
 * default-refute: any seat that does not explicitly accept counts as a refute.
 * A proposal survives ONLY on a strict majority of accepts (ceil(N/2)+1). Any
 * other outcome drops the proposal with a machine-readable reason.
 *
 * Drop reasons:
 *  - majority_refute              : accepts < strict majority.
 *  - default_refute               : every seat defaulted to refute (no accept).
 *  - judge_equals_generator_blocked : a seat's RESOLVED provider AND model equal
 *                                     the generator's; the panel cannot be a
 *                                     valid adversary, so it refuses.
 *
 * This gate is read-only/decision-only: it never canonizes, never writes to
 * canon/docs/code, never merges, never executes. It emits a
 * `atlas.foundry.proposal_verdict.v1` envelope per proposal.
 */
final class FrontierJudgePanelGate
{
    public const STAGE = 'I3';

    public const DEFAULT_JUDGE_COUNT = 3;

    public const DROP_MAJORITY_REFUTE = 'majority_refute';

    public const DROP_DEFAULT_REFUTE = 'default_refute';

    public const DROP_JUDGE_EQUALS_GENERATOR = 'judge_equals_generator_blocked';

    /**
     * @param  list<FrontierJudgePort>  $judges  one port per seat (default 3 seats)
     */
    public function __construct(
        private readonly array $judges,
    ) {}

    /**
     * Adjudicate one proposal through the full panel.
     *
     * $context MUST carry the generator's resolved identity:
     *   - generator_provider_resolved: string
     *   - generator_model_resolved:    string
     *
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>  $context
     * @return array{
     *     survived:bool,
     *     drop_reason:string|null,
     *     verdict:array<string,mixed>
     * }
     */
    public function adjudicate(array $proposal, array $context): array
    {
        $proposalId = trim((string) ($proposal['proposal_id'] ?? ''));
        $generatorProvider = trim((string) ($context['generator_provider_resolved'] ?? ''));
        $generatorModel = trim((string) ($context['generator_model_resolved'] ?? ''));

        $judgeCount = count($this->judges);
        // Strict majority: ceil(N/2)+1.
        $majorityThreshold = (int) ceil($judgeCount / 2) + 1;

        $lenses = [];
        $acceptCount = 0;
        $refuteCount = 0;
        $judgeEqualsGenerator = false;
        $sawExplicitDecision = false;

        $seat = 0;
        foreach ($this->judges as $judge) {
            $seat++;
            $raw = $judge->adjudicate($proposal, $context);

            $decisionRaw = (string) ($raw['decision'] ?? '');
            $reason = trim((string) ($raw['reason'] ?? '')) ?: 'default_refute';

            $seatProvider = (string) ($raw['judge_provider_resolved'] ?? '');
            $seatModel = (string) ($raw['judge_model_resolved'] ?? '');

            // Defense-in-depth: a seat whose RESOLVED identity exactly matches the
            // generator's is not a valid adversary. Force it to refute regardless
            // of its returned decision and flag the collapse. Only an exact,
            // non-empty provider+model match triggers this (empty/unresolved
            // identities never force the refute).
            if (
                trim($seatProvider) !== ''
                && $seatProvider === $generatorProvider
                && $seatModel === $generatorModel
            ) {
                $decisionRaw = 'refute';
                $reason = self::DROP_JUDGE_EQUALS_GENERATOR;
                $judgeEqualsGenerator = true;
            }

            // Default-refute: any non-accept counts as a refute.
            $decision = $decisionRaw === 'accept' ? 'accept' : 'refute';
            if ($decisionRaw === 'accept') {
                $acceptCount++;
                $sawExplicitDecision = true;
            } else {
                $refuteCount++;
                if ($reason !== 'default_refute' && $reason !== 'judge_provider_real_execution_bridge_missing') {
                    $sawExplicitDecision = true;
                }
            }

            if ($reason === self::DROP_JUDGE_EQUALS_GENERATOR) {
                $judgeEqualsGenerator = true;
            }

            $lenses[] = [
                'lens_id' => 'i3_seat_'.$seat,
                'judge_label' => (string) ($raw['judge_label'] ?? 'unknown'),
                'judge_provider' => (string) ($raw['judge_provider_resolved'] ?? ''),
                'judge_model' => (string) ($raw['judge_model_resolved'] ?? ''),
                'decision' => $decision,
                'reason' => $reason,
            ];
        }

        $majorityConfirmed = $acceptCount >= $majorityThreshold && ! $judgeEqualsGenerator;

        if ($judgeEqualsGenerator) {
            $dropReason = self::DROP_JUDGE_EQUALS_GENERATOR;
        } elseif ($majorityConfirmed) {
            $dropReason = null;
        } elseif ($acceptCount === 0 && ! $sawExplicitDecision) {
            // Every seat defaulted to refute with no explicit adjudication.
            $dropReason = self::DROP_DEFAULT_REFUTE;
        } else {
            $dropReason = self::DROP_MAJORITY_REFUTE;
        }

        $survived = $dropReason === null;

        $verdict = [
            'schema_version' => FoundrySchemas::PROPOSAL_VERDICT,
            'proposal_id' => $proposalId,
            'verdict' => $survived ? 'confirmed' : 'refuted',
            'lenses' => $lenses,
            'majority_confirmed' => $majorityConfirmed,
            'judge_count' => $judgeCount,
            'accept_count' => $acceptCount,
            'refute_count' => $refuteCount,
            'majority_threshold' => $majorityThreshold,
            'default_refute' => true,
            'generator_provider_resolved' => $generatorProvider,
            'generator_model_resolved' => $generatorModel,
            'judge_provider_resolved' => $this->firstSeatProvider($lenses),
            'judge_model_resolved' => $this->firstSeatModel($lenses),
            'drop_reason' => $dropReason,
            'claim_policy' => $this->claimPolicy(),
        ];

        $verdict['verdict_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($verdict));

        return [
            'survived' => $survived,
            'drop_reason' => $dropReason,
            'verdict' => $verdict,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $lenses
     */
    private function firstSeatProvider(array $lenses): string
    {
        return (string) ($lenses[0]['judge_provider'] ?? '');
    }

    /**
     * @param  list<array<string,mixed>>  $lenses
     */
    private function firstSeatModel(array $lenses): string
    {
        return (string) ($lenses[0]['judge_model'] ?? '');
    }

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'provider_invoked' => false,
            'mutates_repo' => false,
            'canonical_doc_write_allowed' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'executed' => false,
            'deterministic' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed>
     */
    private function identity(array $verdict): array
    {
        $copy = $verdict;
        unset($copy['verdict_hash']);

        return $copy;
    }
}
