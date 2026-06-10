<?php

namespace App\Services\Ai\Strategy;

use App\Models\AiExperimentPlan;
use App\Models\AiOpportunity;
use App\Models\AiStrategyMemo;
use App\Models\AiStrategyRun;
use App\Models\AiVentureBlueprint;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates the Strategy / Venture Studio flow:
 *
 *   opportunity (problem/ICP/pain/market/competitors/risks)
 *      -> venture blueprint (product/GTM/unit economics/hiring/ops)
 *      -> experiment plan (hypothesis/metric/design + decision)
 *      -> strategy memo (decision + next actions)
 *
 * Optionally integrates with the Evidence Runtime (Meta 4) to attach claim,
 * receipt and certification records. Tolerant: if Evidence Runtime is not
 * present in the workspace, strategy artifacts still persist; evidence
 * attachment is skipped and reported as `evidence_attached=false`.
 */
class StrategyRuntimeService
{
    public const RUN_KIND_OPPORTUNITY_TO_DECISION = 'opportunity_to_decision';

    public const STATUS_OPEN = 'open';

    public const STATUS_DECIDED = 'decided';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly OpportunityRadarService $opportunities,
        private readonly VentureBlueprintService $blueprints,
        private readonly ExperimentPlanService $experiments,
        private readonly StrategyMemoService $memos,
    ) {}

    /**
     * Start a strategy run. Persists an `ai_strategy_runs` record with a
     * deterministic receipt hash. Subsequent artifacts can attach to it via
     * `strategy_run_id`.
     *
     * @param  array<string,mixed>  $args
     */
    public function startRun(array $args = []): AiStrategyRun
    {
        $runKind = (string) ($args['run_kind'] ?? self::RUN_KIND_OPPORTUNITY_TO_DECISION);
        $summary = $args['summary'] ?? null;

        $hashInput = [
            'run_kind' => $runKind,
            'mission_id' => $args['mission_id'] ?? null,
            'work_order_id' => $args['work_order_id'] ?? null,
            'inputs' => $args['inputs'] ?? [],
            'started_at_token' => $args['hash_seed'] ?? (string) Str::uuid(),
        ];

        return AiStrategyRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $args['mission_id'] ?? null,
            'work_order_id' => $args['work_order_id'] ?? null,
            'run_kind' => $runKind,
            'status' => self::STATUS_OPEN,
            'summary' => $summary,
            'inputs' => $args['inputs'] ?? null,
            'outputs' => null,
            'blockers' => null,
            'next_action' => 'create_opportunity',
            'receipt_hash' => StrategyCanonicalHash::sha256($hashInput),
        ]);
    }

    public function closeRun(AiStrategyRun $run, string $status, ?string $nextAction = null, ?array $outputs = null, ?array $blockers = null): AiStrategyRun
    {
        $run->status = $status;
        if ($nextAction !== null) {
            $run->next_action = $nextAction;
        }
        if ($outputs !== null) {
            $run->outputs = $outputs;
        }
        if ($blockers !== null) {
            $run->blockers = $blockers;
        }
        if ($status === self::STATUS_DECIDED) {
            $run->completed_at = Carbon::now();
        }
        $run->save();

        return $run;
    }

    /**
     * Drive an opportunity through the full chain:
     *  1. Persist opportunity.
     *  2. Persist venture blueprint.
     *  3. Persist experiment plan (hypothesis -> metric -> design).
     *  4. Record experiment result + decision.
     *  5. Persist strategy memo with decision + next actions.
     *  6. Optionally attach Evidence Runtime claim + certification.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function driveOpportunityToDecision(array $payload): array
    {
        $run = $this->startRun([
            'run_kind' => self::RUN_KIND_OPPORTUNITY_TO_DECISION,
            'mission_id' => $payload['mission_id'] ?? null,
            'work_order_id' => $payload['work_order_id'] ?? null,
            'inputs' => $payload['inputs'] ?? null,
            'hash_seed' => $payload['hash_seed'] ?? null,
        ]);

        $opportunity = $this->opportunities->create(array_merge(
            $payload['opportunity'] ?? [],
            ['strategy_run_id' => $run->id, 'mission_id' => $run->mission_id],
        ));

        $blueprint = $this->blueprints->create(array_merge(
            $payload['venture_blueprint'] ?? [],
            [
                'opportunity_id' => $opportunity->id,
                'strategy_run_id' => $run->id,
            ],
        ));

        $experiment = $this->experiments->create(array_merge(
            $payload['experiment_plan'] ?? [],
            [
                'opportunity_id' => $opportunity->id,
                'venture_blueprint_id' => $blueprint->id,
                'strategy_run_id' => $run->id,
            ],
        ));

        $result = (array) ($payload['experiment_result'] ?? []);
        $decision = (array) ($payload['experiment_decision'] ?? []);
        if ($result !== [] && $decision !== []) {
            $this->experiments->recordResult($experiment, $result, $decision);
        }

        $memo = $this->memos->create(array_merge(
            $payload['strategy_memo'] ?? [],
            [
                'opportunity_id' => $opportunity->id,
                'venture_blueprint_id' => $blueprint->id,
                'strategy_run_id' => $run->id,
                'experiment_refs' => array_merge(
                    (array) ($payload['strategy_memo']['experiment_refs'] ?? []),
                    [['kind' => 'experiment_plan', 'id' => $experiment->id, 'experiment_id' => $experiment->experiment_id]],
                ),
            ],
        ));

        $evidence = $this->attachEvidence($run, $opportunity, $blueprint, $experiment, $memo);

        $this->closeRun(
            $run,
            self::STATUS_DECIDED,
            'finalize_strategy_memo',
            [
                'opportunity_id' => $opportunity->id,
                'venture_blueprint_id' => $blueprint->id,
                'experiment_plan_id' => $experiment->id,
                'strategy_memo_id' => $memo->id,
            ],
        );

        return [
            'run' => $run->refresh(),
            'opportunity' => $opportunity,
            'venture_blueprint' => $blueprint,
            'experiment_plan' => $experiment->refresh(),
            'strategy_memo' => $memo,
            'evidence' => $evidence,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function attachEvidence(
        AiStrategyRun $run,
        AiOpportunity $opportunity,
        AiVentureBlueprint $blueprint,
        AiExperimentPlan $experiment,
        AiStrategyMemo $memo,
    ): array {
        $packService = '\\App\\Services\\Ai\\Evidence\\EvidencePackService';
        $claimService = '\\App\\Services\\Ai\\Evidence\\ClaimVerificationService';
        $certService = '\\App\\Services\\Ai\\Evidence\\CertificationRuntimeService';
        $receiptService = '\\App\\Services\\Ai\\Evidence\\ReceiptService';

        $tablesPresent = DatabaseTableAvailability::all([
            'ai_evidence_packs',
            'ai_certifications',
            'ai_audit_events',
        ]);

        if (! class_exists($packService) || ! class_exists($certService) || ! $tablesPresent) {
            return [
                'attached' => false,
                'detail' => 'Evidence Runtime not available (services or tables missing).',
            ];
        }

        try {
            $packs = app($packService);
            $claims = class_exists($claimService) ? app($claimService) : null;
            $certs = app($certService);
            $receipts = class_exists($receiptService) ? app($receiptService) : null;

            $receipt = null;
            if ($receipts !== null) {
                $receipt = $receipts->emit([
                    'receipt_type' => 'domain_step',
                    'action' => 'strategy.opportunity_to_decision',
                    'target_type' => 'domain_delivery',
                    'target_id' => (string) $run->id,
                    'status' => 'ok',
                ]);
            }

            $pack = $packs->build([
                'target_type' => 'domain_delivery',
                'target_id' => (string) $run->id,
                'mission_id' => $run->mission_id,
                'domain_id' => StrategyDomainManifestSeeder::DOMAIN_ID,
                'artifact_refs' => [
                    ['kind' => 'opportunity', 'id' => $opportunity->id, 'hash' => $opportunity->opportunity_hash],
                    ['kind' => 'venture_blueprint', 'id' => $blueprint->id, 'hash' => $blueprint->blueprint_hash],
                ],
                'test_refs' => [
                    ['kind' => 'experiment_plan', 'id' => $experiment->id, 'hash' => $experiment->experiment_hash],
                ],
                'receipt_refs' => $receipt !== null
                    ? [['kind' => 'receipt', 'id' => $receipt->id, 'hash' => $receipt->receipt_hash]]
                    : [],
                'command_refs' => [
                    ['kind' => 'strategy_memo', 'id' => $memo->id, 'hash' => $memo->memo_hash],
                ],
            ]);

            $claim = null;
            if ($claims !== null) {
                $claim = $claims->register([
                    'claim_text' => "Strategy run {$run->uuid} decided opportunity {$opportunity->opportunity_id} via experiment {$experiment->experiment_id}.",
                    'claim_type' => 'supported',
                    'evidence_refs' => [
                        ['kind' => 'experiment_plan', 'id' => $experiment->id],
                        ['kind' => 'evidence_pack', 'id' => $pack->id],
                    ],
                ]);
            }

            $certification = $certs->certify([
                'target_type' => 'domain_delivery',
                'target_id' => (string) $run->id,
                'mission_id' => $run->mission_id,
                'evidence_pack_id' => $pack->id,
                'required_requirements' => [
                    'opportunity_created',
                    'venture_blueprint_created',
                    'experiment_planned',
                    'experiment_decided',
                    'strategy_memo_decided',
                ],
                'provided_requirements' => array_filter([
                    'opportunity_created',
                    'venture_blueprint_created',
                    'experiment_planned',
                    $experiment->refresh()->status === ExperimentPlanService::STATUS_DECIDED ? 'experiment_decided' : null,
                    $memo->status === StrategyMemoService::STATUS_DECIDED ? 'strategy_memo_decided' : null,
                ]),
            ]);

            return [
                'attached' => true,
                'evidence_pack_id' => $pack->id,
                'evidence_hash' => $pack->evidence_hash,
                'claim_id' => $claim?->id,
                'certification_id' => $certification->id,
                'certification_status' => $certification->status,
            ];
        } catch (Throwable $e) {
            return [
                'attached' => false,
                'detail' => 'evidence attach failed: '.$e->getMessage(),
            ];
        }
    }
}
