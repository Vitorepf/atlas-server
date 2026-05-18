<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingRun;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MarketingRuntimeService
{
    public function __construct(
        private readonly ICPPositioningService $icp,
        private readonly CampaignPlanService $campaign,
        private readonly CopyBriefService $copy,
        private readonly CreativeBriefService $creative,
        private readonly FunnelPlanService $funnel,
        private readonly MarketingAnalyticsPlanService $analytics,
        private readonly GrowthExperimentPlanService $experiments,
        private readonly MarketingApprovalGateService $gates,
    ) {}

    /**
     * Open a new marketing run with product/objective.
     *
     * @param  array<string,mixed>  $context
     */
    public function open(string $product, string $objective, array $context = []): AiMarketingRun
    {
        return AiMarketingRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $context['mission_id'] ?? null,
            'work_order_id' => $context['work_order_id'] ?? null,
            'product' => $product,
            'objective' => $objective,
            'status' => MarketingDomainCanon::STATUS_DRAFTING,
        ]);
    }

    /**
     * Final certification of a marketing run. Certification passes when all
     * REQUIRED_ARTIFACT_TYPES are present AND any campaign / paid_media
     * artifact has an open or approved gate (never auto-published). Fails
     * otherwise and never marks the run completed.
     */
    public function certify(AiMarketingRun $run): AiMarketingRun
    {
        $artifacts = $run->artifacts()->get();
        $byType = $artifacts->groupBy('artifact_type');
        $missing = [];

        foreach (MarketingDomainCanon::REQUIRED_ARTIFACT_TYPES as $required) {
            if (! isset($byType[$required]) || $byType[$required]->isEmpty()) {
                $missing[] = "missing_artifact:{$required}";
            }
        }

        $campaigns = $byType[MarketingDomainCanon::ARTIFACT_CAMPAIGN] ?? collect();
        foreach ($campaigns as $campaign) {
            $hasGate = $run->approvalGates()
                ->where('artifact_id', $campaign->id)
                ->whereIn('gate_type', [MarketingDomainCanon::GATE_PAID_MEDIA, MarketingDomainCanon::GATE_PUBLISH])
                ->exists();
            if (! $hasGate) {
                $missing[] = "campaign_missing_approval_gate:{$campaign->uuid}";
            }
        }

        $experiments = $run->experiments()->get();
        foreach ($experiments as $experiment) {
            $hasGate = $run->approvalGates()
                ->where('experiment_id', $experiment->id)
                ->where('gate_type', MarketingDomainCanon::GATE_LAUNCH_EXPERIMENT)
                ->exists();
            if (! $hasGate) {
                $missing[] = "experiment_missing_launch_gate:{$experiment->uuid}";
            }
        }

        $unapprovedSensitive = $run->approvalGates()
            ->where('status', MarketingDomainCanon::GATE_PENDING)
            ->whereIn('gate_type', [MarketingDomainCanon::GATE_PUBLISH, MarketingDomainCanon::GATE_PAID_MEDIA])
            ->count();

        $status = $missing === []
            ? MarketingDomainCanon::CERT_PASSED
            : MarketingDomainCanon::CERT_FAILED;

        $evidencePack = $this->buildEvidencePack($run);

        $run->fill([
            'status' => $status === MarketingDomainCanon::CERT_PASSED
                ? MarketingDomainCanon::STATUS_COMPLETED
                : MarketingDomainCanon::STATUS_FAILED,
            'certification_status' => $status,
            'missing_requirements' => $missing === [] ? null : $missing,
            'certification_hash' => MissionCanonicalHash::sha256([
                'run_uuid' => $run->uuid,
                'missing' => $missing,
                'status' => $status,
                'unapproved_sensitive_gates' => $unapprovedSensitive,
            ]),
            'evidence_pack_hash' => MissionCanonicalHash::sha256($evidencePack),
            'completed_at' => $status === MarketingDomainCanon::CERT_PASSED
                ? Carbon::now()
                : null,
        ]);
        $run->save();

        return $run->refresh();
    }

    /**
     * Deterministic end-to-end smoke flow: produces ICP, positioning,
     * campaign, copy, creative, funnel, analytics, experiment AND opens
     * the corresponding approval gates. Demonstrates the no-auto-publish
     * / no-auto-spend invariants.
     *
     * @return array{run: AiMarketingRun}
     */
    public function smokeRun(): array
    {
        $run = $this->open(
            'Atlas Vault',
            'Drive 100 qualified weekly trials from researchers and operators',
            ['source' => 'cli-smoke'],
        );

        $icp = $this->icp->defineICP($run, [
            'segment' => 'Senior IC and operator personas',
            'pains' => ['context drift', 'untrusted memory', 'manual research synthesis'],
            'jobs_to_be_done' => ['canonical knowledge surface', 'memory under audit'],
            'gains' => ['hours back per week', 'auditable decisions'],
            'channels' => ['founder-led content', 'developer newsletters'],
        ]);
        $this->icp->definePositioning($run, [
            'promise' => 'Atlas keeps your second brain auditable and provider-safe.',
            'differentiation' => 'Receipts for every claim, source quality scoring, contradiction check.',
            'proof_points' => ['source plan + citations', 'docs-health gate', 'evidence ledger'],
        ]);
        $campaign = $this->campaign->plan($run, [
            'name' => 'Q3 Founder-Led Launch',
            'objective' => 'Drive qualified trial signups from research-heavy ICs',
            'channels' => ['Founder Twitter', 'Operator Newsletter', 'Podcast'],
            'kpis' => [
                ['name' => 'qualified_trials', 'target' => 100],
                ['name' => 'cac_payback_days', 'target' => 30],
            ],
            'budget_proposed' => 5000.00,
            'currency' => 'USD',
        ]);
        $this->copy->draft($run, [
            'headline' => 'Your second brain, with receipts.',
            'audience' => 'Operators tired of hallucinated memory.',
            'channel' => 'newsletter',
            'message' => 'Atlas turns scattered notes into auditable knowledge.',
            'call_to_action' => 'Start trial',
        ]);
        $this->creative->draft($run, [
            'concept' => 'Atlas Vault flyover',
            'format' => 'short-form video',
            'audience' => 'operators / ICs',
            'visual_direction' => 'cool-cream UI flow + audit trail overlays',
        ]);
        $this->funnel->plan($run, [
            'title' => 'Awareness to Activation funnel',
            'stages' => [
                ['name' => 'awareness', 'metric' => 'visitors', 'target_conversion' => 1.0],
                ['name' => 'interest', 'metric' => 'newsletter_signup_rate', 'target_conversion' => 0.06],
                ['name' => 'trial', 'metric' => 'trial_conversion_rate', 'target_conversion' => 0.18],
                ['name' => 'activation', 'metric' => 'activation_rate', 'target_conversion' => 0.35],
            ],
        ]);
        $this->analytics->plan($run, [
            'title' => 'Vault analytics plan',
            'events' => [
                ['name' => 'trial_started', 'properties' => ['plan']],
                ['name' => 'vault_synced', 'properties' => ['note_count']],
            ],
            'kpis' => ['qualified_trials', 'activation_rate'],
        ]);

        $experiment = $this->experiments->plan($run, [
            'name' => 'Founder newsletter framing',
            'hypothesis' => 'Receipts framing > productivity framing on conversion',
            'primary_metric' => 'newsletter_signup_rate',
            'success_criterion' => '>=15% relative lift after 2 weeks',
            'decision_rule' => 'ship-winner-if-significant-no-guardrail-breach',
            'variants' => [
                ['name' => 'control_productivity_framing'],
                ['name' => 'variant_receipts_framing'],
            ],
            'guardrails' => ['unsubscribe_rate_breach' => 0.5],
        ]);

        $this->gates->request($run, [
            'gate_type' => MarketingDomainCanon::GATE_PAID_MEDIA,
            'artifact_id' => $campaign->id,
            'requested_action' => 'paid_media_spend',
            'proposed_budget' => 5000.0,
            'currency' => 'USD',
        ]);
        $this->gates->request($run, [
            'gate_type' => MarketingDomainCanon::GATE_PUBLISH,
            'artifact_id' => $campaign->id,
            'requested_action' => 'publish_campaign',
        ]);
        $this->gates->request($run, [
            'gate_type' => MarketingDomainCanon::GATE_LAUNCH_EXPERIMENT,
            'experiment_id' => $experiment->id,
            'requested_action' => 'launch_experiment',
        ]);

        $run = $this->certify($run);

        return ['run' => $run];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildEvidencePack(AiMarketingRun $run): array
    {
        return [
            'run_uuid' => $run->uuid,
            'artifacts' => $run->artifacts()->orderBy('artifact_type')->get()->map(static fn ($a): array => [
                'artifact_type' => $a->artifact_type,
                'artifact_hash' => $a->artifact_hash,
                'status' => $a->status,
            ])->all(),
            'experiments' => $run->experiments()->get()->map(static fn ($e): array => [
                'experiment_hash' => $e->experiment_hash,
                'status' => $e->status,
                'primary_metric' => $e->primary_metric,
            ])->all(),
            'approval_gates' => $run->approvalGates()->get()->map(static fn ($g): array => [
                'gate_type' => $g->gate_type,
                'status' => $g->status,
                'receipt_hash' => $g->receipt_hash,
            ])->all(),
        ];
    }
}
