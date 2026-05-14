<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

/**
 * Rivals One-Shot Enterprise Evaluation Rubric v1.
 *
 * Declarative rubric that defines the canonical scoring contract for an
 * Atlas Code one-shot enterprise delivery. The primary objective is
 * one-shot enterprise quality (not raw speed). Time enters only as a
 * secondary/observed metric — quality can compensate time, time can
 * never compensate quality.
 *
 * Weights sum to exactly 100. Each dimension is auditable and carries
 * scoring guidance + hard-fail conditions so the evaluator can be honest
 * about partial credit.
 *
 * Schema: atlas.programming.rivals_one_shot_enterprise_rubric.v1
 */
class AtlasRivalsOneShotEnterpriseRubricService
{
    public const SCHEMA_VERSION = 'atlas.programming.rivals_one_shot_enterprise_rubric.v1';

    public const RUBRIC_ID = 'atlas-rivals-one-shot-enterprise-rubric-v1';

    public const PRIMARY_OBJECTIVE = 'one_shot_enterprise_quality';

    /** @var list<string> Hard-fail conditions that invalidate any final claim regardless of score. */
    public const GLOBAL_HARD_FAIL_CONDITIONS = [
        'atlas_arm_not_forge',
        'missing_replay_manifest',
        'missing_acceptance_gates',
        'missing_business_rule',
        'missing_canonical_docs',
        'missing_tests_or_test_evidence',
        'auto_completion_without_review',
        'fake_evidence',
        'provider_call_without_approval',
        'dirty_workspace_for_claim',
        'synthetic_score_used_as_real_claim',
    ];

    /**
     * @return array<string,mixed>
     */
    public function rubric(): array
    {
        $dimensions = $this->dimensions();
        $weightTotal = array_sum(array_column($dimensions, 'weight'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'rubric_id' => self::RUBRIC_ID,
            'goal' => 'Score whether an Atlas Code delivery is one-shot enterprise-grade: correct business rule, faithful to canonical docs, complete in one shot, governed by Forge, well tested, low review cost and low human intervention. Time is observed but cannot dominate the score.',
            'primary_objective' => self::PRIMARY_OBJECTIVE,
            'speed_is_secondary' => true,
            'quality_can_compensate_time' => true,
            'time_cannot_compensate_quality' => true,
            'synthetic_scores_allowed' => false,
            'external_provider_call' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'global_hard_fail_conditions' => self::GLOBAL_HARD_FAIL_CONDITIONS,
            'scoring_dimensions' => $dimensions,
            'score_dimensions_count' => count($dimensions),
            'score_weights_total' => $weightTotal,
            'grade_bands' => [
                'enterprise_ready' => ['min_score' => 90, 'requires_no_hard_fail' => true],
                'review_required' => ['min_score' => 75, 'max_score' => 89, 'requires_no_hard_fail' => true],
                'not_enterprise_ready' => ['max_score' => 74, 'requires_no_hard_fail' => true],
                'invalid' => ['triggered_by' => 'any_hard_fail'],
            ],
            'verdict_rules' => [
                'quality_dominates_speed' => true,
                'high_quality_with_high_time_still_eligible_for_enterprise_ready' => true,
                'low_quality_with_low_time_is_never_enterprise_ready' => true,
                'invalid_blocks_any_claim_regardless_of_dimension_scores' => true,
                'evaluation_is_diagnostic_unless_real_provider_battery_validates_it' => true,
            ],
            'note' => 'Rubric is read-only. It is consumed by AtlasRivalsOneShotEnterpriseEvaluationService. It never executes providers and never promotes Rivals claim by itself.',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function dimensions(): array
    {
        return [
            [
                'id' => 'business_rule_alignment',
                'label' => 'Regra de negocio / intencao correta',
                'weight' => 15,
                'description' => 'A entrega cumpre a regra de negocio declarada (objetivo do case) sem reinterpretar o pedido. Sem escopo escondido, sem deriva.',
                'evidence_inputs' => [
                    'replay_manifest.case_id',
                    'case_manifest.objective',
                    'evidence_pack.business_rule_check',
                    'review_packet.business_rule_review',
                ],
                'hard_fail_conditions' => ['missing_business_rule'],
                'scoring_guidance' => 'Full credit quando objetivo e evidencia batem. Reduz se escopo expandiu sem aprovacao. Zero se objetivo nao pode ser identificado.',
                'examples' => [
                    'pass' => 'Case define objetivo X; replay manifest e diff comprovam X sem extras.',
                    'partial' => 'Objetivo cumprido mas com escopo extra nao aprovado.',
                    'fail' => 'Implementou Y quando case pedia X.',
                ],
            ],
            [
                'id' => 'canonical_documentation_adherence',
                'label' => 'Aderencia a documentacao canonica',
                'weight' => 12,
                'description' => 'Implementacao respeita docs canonicos (`docs/engineering-knowledge-base/**`), evitando padroes que o repo ja declarou proibidos.',
                'evidence_inputs' => [
                    'evidence_pack.canonical_docs_consulted',
                    'review_packet.canonical_alignment',
                    'preflight.canonical_docs',
                ],
                'hard_fail_conditions' => ['missing_canonical_docs'],
                'scoring_guidance' => 'Full credit quando referencia explicita aos docs canonicos esta na evidencia. Reduz se contraria padroes documentados. Zero se docs canonicos nao foram consultados.',
                'examples' => [
                    'pass' => 'Patch cita `atlas-forge-native-rivals-protocol-v1.md` e segue o contrato.',
                    'partial' => 'Patch correto mas sem referencia explicita aos docs.',
                    'fail' => 'Patch contradiz padrao canonico (ex.: bypass de Forge).',
                ],
            ],
            [
                'id' => 'one_shot_completeness',
                'label' => 'Completude one-shot',
                'weight' => 12,
                'description' => 'A entrega resolve o caso por inteiro em uma rodada — sem TODOs, sem partes faltando, sem follow-ups obrigatorios.',
                'evidence_inputs' => [
                    'replay_manifest.acceptance_gates',
                    'evidence_pack.todo_count',
                    'evidence_pack.partial_implementation_flags',
                    'review_packet.completion_review',
                ],
                'hard_fail_conditions' => [],
                'scoring_guidance' => 'Full credit se entrega fecha todos os gates declarados. Reduz por cada TODO restante. Zero se ficou pela metade.',
                'examples' => [
                    'pass' => 'Service + CLI + audit + tests + docs entregues juntos.',
                    'partial' => 'Service entregue mas tests pendentes.',
                    'fail' => 'TODO comments em codigo de producao.',
                ],
            ],
            [
                'id' => 'functional_correctness',
                'label' => 'Correcao funcional',
                'weight' => 12,
                'description' => 'O codigo realmente funciona — testes passam, comandos retornam exit code certo, contratos honram schema.',
                'evidence_inputs' => [
                    'evidence_pack.test_run_log',
                    'evidence_pack.command_exit_codes',
                    'replay_manifest.acceptance_gates',
                ],
                'hard_fail_conditions' => ['missing_tests_or_test_evidence'],
                'scoring_guidance' => 'Full credit quando todos os testes/gates passam. Reduz proporcional a falhas. Zero se feature simplesmente nao roda.',
                'examples' => [
                    'pass' => 'Toda a suite filtrada passa e command --strict retorna exit 0 quando esperado.',
                    'partial' => 'Suite verde mas 1 gate degradado.',
                    'fail' => 'Tests falham em fixture obvio.',
                ],
            ],
            [
                'id' => 'real_tests_and_risk_coverage',
                'label' => 'Testes reais e cobertura de risco',
                'weight' => 10,
                'description' => 'Tests cobrem os caminhos de risco — fail-closed, dirty workspace, missing approval — nao apenas happy path.',
                'evidence_inputs' => [
                    'evidence_pack.test_files_changed',
                    'evidence_pack.assertion_count',
                    'review_packet.risk_coverage',
                ],
                'hard_fail_conditions' => ['missing_tests_or_test_evidence'],
                'scoring_guidance' => 'Full credit com tests para hard-fails declarados. Reduz se cobertura e so happy path. Zero se nao ha tests.',
                'examples' => [
                    'pass' => 'Tests cobrem dirty workspace, missing approval, atlas_arm_not_forge.',
                    'partial' => 'Tests apenas happy path.',
                    'fail' => 'Tests inexistentes para a feature nova.',
                ],
            ],
            [
                'id' => 'enterprise_architecture',
                'label' => 'Arquitetura enterprise',
                'weight' => 10,
                'description' => 'Layering correto: service + controller + command separados, sem god-class, com dependencias injetadas e contratos por schema.',
                'evidence_inputs' => [
                    'evidence_pack.file_count_by_layer',
                    'review_packet.architecture_review',
                ],
                'hard_fail_conditions' => [],
                'scoring_guidance' => 'Full credit com separacao clara service/command/controller. Reduz se logica grudou em command. Zero se feature nasceu so como script.',
                'examples' => [
                    'pass' => 'Service injetado em command via DI.',
                    'partial' => 'Service existe mas logica de negocio vazou no command.',
                    'fail' => 'Tudo em um command monolitico.',
                ],
            ],
            [
                'id' => 'forge_governance',
                'label' => 'Governanca Forge',
                'weight' => 8,
                'description' => 'Atlas arm rodou pelo Forge runtime, review gate respeitado, completion audit informado, nao houve auto-merge sem human review.',
                'evidence_inputs' => [
                    'replay_manifest.atlas_arm.runtime',
                    'review_packet.review_status',
                    'completion_claim.human_approved',
                ],
                'hard_fail_conditions' => ['atlas_arm_not_forge', 'auto_completion_without_review'],
                'scoring_guidance' => 'Full credit com Forge + review packet. Reduz se review existe mas nao foi consumido. Zero se Atlas arm foi non-forge.',
                'examples' => [
                    'pass' => 'Forge fast-path executou; review packet aprovado por humano.',
                    'partial' => 'Forge usado mas review skipped.',
                    'fail' => 'Atlas arm rodou em runtime ad-hoc.',
                ],
            ],
            [
                'id' => 'operational_safety',
                'label' => 'Seguranca operacional',
                'weight' => 6,
                'description' => 'Sem chamada provider sem aprovacao, sem dirty workspace, sem score sintetico, sem bypass de gate.',
                'evidence_inputs' => [
                    'preflight.safety',
                    'evidence_pack.provider_calls',
                    'replay_manifest.invalid_if',
                ],
                'hard_fail_conditions' => ['provider_call_without_approval', 'dirty_workspace_for_claim', 'synthetic_score_used_as_real_claim'],
                'scoring_guidance' => 'Full credit quando preflight passou clean. Reduz para cada warning ignorado. Zero para violacao hard-fail.',
                'examples' => [
                    'pass' => 'Preflight ready; nenhum provider chamado.',
                    'partial' => 'Preflight com 1 warning de doc ausente.',
                    'fail' => 'Provider chamado sem aprovacao operadora.',
                ],
            ],
            [
                'id' => 'implementation_quality',
                'label' => 'Qualidade de implementacao',
                'weight' => 5,
                'description' => 'Codigo legivel, com tipos, sem duplicacao desnecessaria, sem complexidade gratuita.',
                'evidence_inputs' => [
                    'evidence_pack.quality_scan_log',
                    'review_packet.code_quality',
                ],
                'hard_fail_conditions' => [],
                'scoring_guidance' => 'Full credit com quality scan changed-only verde. Reduz por dead code novo. Zero se quality scan falha.',
                'examples' => [
                    'pass' => 'Pint changed-only verde, sem warnings.',
                    'partial' => 'Warnings pequenos.',
                    'fail' => 'Quality scan falha.',
                ],
            ],
            [
                'id' => 'operator_experience',
                'label' => 'UX operacional',
                'weight' => 4,
                'description' => 'Comandos com signature clara, --json estavel, mensagens humanas legiveis, exit codes coerentes.',
                'evidence_inputs' => [
                    'evidence_pack.command_signature',
                    'evidence_pack.command_help_output',
                ],
                'hard_fail_conditions' => [],
                'scoring_guidance' => 'Full credit com --json + human render + --strict. Reduz se faltar uma das tres. Zero se feature so funciona via codigo PHP.',
                'examples' => [
                    'pass' => '`--json`, `--strict`, ASCII detail e proper exit codes.',
                    'partial' => '`--json` existe mas nao tem `--strict`.',
                    'fail' => 'Feature so disponivel via REPL.',
                ],
            ],
            [
                'id' => 'observability_and_evidence',
                'label' => 'Observabilidade / evidencia / replay',
                'weight' => 3,
                'description' => 'Output tem schema versionado, generated_at, hashes, evidence paths, e replay manifest.',
                'evidence_inputs' => [
                    'replay_manifest',
                    'evidence_pack.evidence_paths',
                ],
                'hard_fail_conditions' => ['missing_replay_manifest', 'fake_evidence'],
                'scoring_guidance' => 'Full credit quando schema/replay manifest sao planejados/gerados. Reduz se evidencia e parcial. Zero se evidencia e fake.',
                'examples' => [
                    'pass' => 'Replay manifest valid, evidence paths reais.',
                    'partial' => 'Replay parcial, sem hashes.',
                    'fail' => 'Evidence path aponta para arquivo inexistente.',
                ],
            ],
            [
                'id' => 'autonomy_and_intervention_load',
                'label' => 'Autonomia / intervencao humana',
                'weight' => 2,
                'description' => 'Quao pouco intervencao humana foi necessaria para fechar one-shot, exceto o review final exigido por governanca.',
                'evidence_inputs' => [
                    'evidence_pack.human_intervention_log',
                    'review_packet.intervention_count',
                ],
                'hard_fail_conditions' => [],
                'scoring_guidance' => 'Full credit quando intervencao = somente review final. Reduz por cada intervencao extra. Zero se humano teve que recomecar.',
                'examples' => [
                    'pass' => '0 intervencoes alem do review final.',
                    'partial' => '1-2 nudges pequenos.',
                    'fail' => 'Humano teve que reescrever a entrega.',
                ],
            ],
            [
                'id' => 'time_and_cost_efficiency',
                'label' => 'Tempo/custo operacional (secundario)',
                'weight' => 1,
                'description' => 'Metrica secundaria. Tempo bruto / tokens / runs. Nunca pode dominar o score. Serve como desempate quando outras dimensoes empatam.',
                'evidence_inputs' => [
                    'evidence_pack.wall_clock_seconds',
                    'evidence_pack.token_count_estimate',
                ],
                'hard_fail_conditions' => [],
                'scoring_guidance' => 'Full credit quando tempo e proporcional ao escopo. Reduz por overhead obvio. Nunca usar para superar baixa qualidade nas outras dimensoes.',
                'examples' => [
                    'pass' => 'Entrega proporcional ao escopo.',
                    'partial' => 'Tempo alto mas qualidade compensa (mantém credit).',
                    'fail' => 'Tempo absurdo sem justificativa nem qualidade.',
                ],
            ],
        ];
    }
}
