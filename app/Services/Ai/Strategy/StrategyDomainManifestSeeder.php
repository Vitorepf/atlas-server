<?php

namespace App\Services\Ai\Strategy;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seeds the Strategy / Venture Studio domain manifest into the canonical
 * `ai_domain_manifests` table (Meta 2). Tolerant: if Meta 2 tables are not
 * present yet, the seeder returns a degraded payload instead of failing.
 */
class StrategyDomainManifestSeeder
{
    public const DOMAIN_ID = 'strategy';

    public const NAME = 'Corporate Strategy / Venture Studio';

    public const MATURITY_STAGE = 'specialist';

    public const STATUS = 'planned';

    /**
     * @return array<string,mixed>
     */
    public function seed(): array
    {
        if (! Schema::hasTable('ai_domain_manifests')) {
            return [
                'status' => 'missing',
                'detail' => 'ai_domain_manifests table not available (Meta 2 not bootstrapped).',
            ];
        }

        $manifestModel = '\\App\\Models\\AiDomainManifest';
        if (! class_exists($manifestModel)) {
            return [
                'status' => 'missing',
                'detail' => 'AiDomainManifest model class not available.',
            ];
        }

        $payload = $this->manifestPayload();
        $hash = StrategyCanonicalHash::sha256($payload);

        $existing = $manifestModel::query()->where('domain_id', self::DOMAIN_ID)->first();
        if ($existing !== null) {
            // Idempotent re-seed: only update if hash changes.
            if ($existing->manifest_hash !== $hash) {
                $existing->fill(array_merge($payload, ['manifest_hash' => $hash]));
                $existing->save();
            }

            return [
                'status' => 'updated',
                'manifest_id' => $existing->id,
                'domain_id' => $existing->domain_id,
                'manifest_hash' => $hash,
            ];
        }

        $created = $manifestModel::query()->create(array_merge($payload, [
            'uuid' => (string) Str::uuid(),
            'manifest_hash' => $hash,
        ]));

        return [
            'status' => 'created',
            'manifest_id' => $created->id,
            'domain_id' => $created->domain_id,
            'manifest_hash' => $hash,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function manifestPayload(): array
    {
        return [
            'domain_id' => self::DOMAIN_ID,
            'name' => self::NAME,
            'charter' => [
                'mission' => 'Identify, model, validate and decide on company/product ventures with measurable hypotheses, experiments and unit economics.',
                'frontier' => 'Strategy work that needs to become hypothesis, experiment and decision. Excludes raw execution (Software/Marketing) and live trading (Finance).',
                'users' => ['operator', 'venture_studio'],
                'outcomes' => [
                    'opportunity_radar',
                    'venture_blueprint',
                    'market_model',
                    'unit_economics',
                    'gtm_plan',
                    'experiment_plan',
                    'strategy_memo',
                ],
                'forbidden' => [
                    'spend_money_without_approval',
                    'publish_marketing_without_approval',
                    'declare_success_without_experiment',
                    'replace_finance_or_marketing_runtime',
                ],
            ],
            'ontology' => ['Opportunity', 'VentureBlueprint', 'MarketModel', 'UnitEconomics', 'ExperimentPlan', 'StrategyMemo'],
            'departments' => [
                'opportunity_radar',
                'venture_builder',
                'market_analysis',
                'unit_economics',
                'gtm_planning',
                'experimentation',
                'decision_memo',
            ],
            'capabilities' => [
                'strategy.opportunity.create',
                'strategy.venture_blueprint.create',
                'strategy.market_model.create',
                'strategy.unit_economics.create',
                'strategy.gtm_plan.create',
                'strategy.experiment_plan.create',
                'strategy.strategy_memo.create',
            ],
            'flow_profiles' => [
                'opportunity_to_decision' => [
                    'entry' => 'mission|objective',
                    'gates' => ['opportunity-complete', 'experiment-planned', 'evidence-attached'],
                    'exit' => 'strategy_memo|blocker',
                ],
            ],
            'tools_allowed' => ['research.web_search', 'research.contradiction_check', 'docs.read'],
            'policy_profile' => 'strategy.default',
            'memory_scope' => ['opportunity_records', 'memo_decisions'],
            'evidence_schema' => ['source_ref', 'experiment_plan', 'test_result', 'operator_decision', 'claim'],
            'quality_gates' => [
                'opportunity-complete',
                'venture-blueprint-complete',
                'experiment-planned',
                'evidence-attached',
                'memo-decided',
            ],
            'handoff_rules' => [
                'allowed_targets' => ['software', 'marketing', 'finance', 'research', 'operations'],
                'forbidden_targets' => [],
            ],
            'delivery_types' => ['strategy_memo', 'venture_blueprint', 'experiment_plan'],
            'metrics' => ['opportunity_count', 'experiment_decision_ratio', 'time_to_decision', 'memo_outcome'],
            'forbidden_actions' => ['live_trade_execution', 'paid_media_publish_without_approval'],
            'maturity_stage' => self::MATURITY_STAGE,
            'owner' => 'atlas-ai',
            'status' => self::STATUS,
            'schema_version' => 'atlas.ai.domain_manifest.v1',
        ];
    }
}
