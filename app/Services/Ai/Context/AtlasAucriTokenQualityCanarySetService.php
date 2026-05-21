<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasAucriTokenQualityCanarySetService
{
    public const SCHEMA_VERSION = 'atlas.aucri.token_quality_canary_set.v1';

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $cases = $this->cases();

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total_cases' => count($cases),
                'domains' => array_values(array_unique(array_column($cases, 'domain'))),
                'quality_floor' => [
                    'must_keep_coverage' => 1.0,
                    'evidence_coverage_regression_allowed' => false,
                    'sufficiency_regression_allowed' => false,
                    'privacy_regression_allowed' => false,
                ],
            ],
            'cases' => $cases,
            'claims' => [
                'providers_invoked' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'scope' => 'static_canary_contract',
            ],
            'writes' => false,
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['canary_set_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function cases(): array
    {
        return [
            $this->case(
                'dev_patch_context_delta',
                'programming',
                'Patch pequeno em arquivo ja tocado deve enviar apenas delta, mas preservar teste, decisao e constraints.',
                ['decision', 'blocker', 'dod', 'touched_file', 'test_ref'],
                ['input_tokens_after <= 0.45 * input_tokens_before', 'must_keep_coverage = 1.0'],
            ),
            $this->case(
                'forge_obra_long_horizon_resume',
                'forge',
                'Retomar Obra longa deve reduzir briefing repetido sem perder SDD, milestone, risk e work packet ativo.',
                ['sdd_ref', 'milestone', 'active_work_packet', 'risk', 'operator_decision'],
                ['input_tokens_after <= 0.60 * input_tokens_before', 'evidence_coverage_regression_allowed = false'],
            ),
            $this->case(
                'research_source_grounding',
                'research',
                'Pesquisa deve comprimir notas, mas manter fontes, incertezas, datas e claims relevantes.',
                ['source_ref', 'claim', 'uncertainty', 'date_or_freshness', 'counterevidence'],
                ['groundedness_score_after >= groundedness_score_before', 'source_refs_preserved = true'],
            ),
            $this->case(
                'finance_privacy_budget',
                'finance',
                'Analise financeira deve reduzir contexto sem vazar dado sensivel nem perder premissas e riscos.',
                ['privacy_policy', 'assumption', 'risk', 'source_ref', 'calculation_ref'],
                ['privacy_regression_allowed = false', 'must_keep_coverage = 1.0'],
            ),
            $this->case(
                'strategy_reality_graph_decision',
                'strategy',
                'Decisao estrategica deve usar contexto compacto sem perder entidades, tradeoffs, restricoes e next action.',
                ['entity_ref', 'tradeoff', 'constraint', 'decision_option', 'next_action'],
                ['decision_quality_regression_allowed = false', 'context_roi_after >= context_roi_before'],
            ),
        ];
    }

    /**
     * @param  array<int,string>  $mustKeep
     * @param  array<int,string>  $acceptance
     * @return array<string,mixed>
     */
    private function case(string $id, string $domain, string $objective, array $mustKeep, array $acceptance): array
    {
        return [
            'case_id' => $id,
            'domain' => $domain,
            'objective' => $objective,
            'risk_level' => in_array($domain, ['finance', 'forge', 'strategy'], true) ? 'high' : 'medium',
            'must_keep_kinds' => $mustKeep,
            'token_quality_acceptance' => $acceptance,
            'required_gates' => [
                'must_keep_coverage',
                'evidence_coverage',
                'sufficiency',
                'privacy',
                'rollback_ref',
            ],
        ];
    }
}
