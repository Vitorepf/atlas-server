<?php

declare(strict_types=1);
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernancePolicyPlane;

/**
 * v3 Policy Plane for the task lane: governance policy as inspectable DATA instead of scattered
 * env reads and hard-codes. Read by {@see AtlasTaskGovernancePolicyPlane}.
 *
 * Every value below reproduces TODAY's hard-coded behavior exactly — this file makes the policy
 * inspectable/editable without touching code, it does not change any default outcome by itself.
 * Emergency env overrides (ATLAS_MERGE_GOVERNANCE_MODE, ATLAS_MERGE_GOVERNANCE_RISK_WINDOW,
 * ATLAS_TASK_SERVING_VERIFY_BEFORE_COMMIT) still beat this config when set.
 */
return [
    // Default for AtlasTaskCommitVerificationGate::enabled() when no env override is set.
    'server_verifier_enabled_default' => true,

    // Default for AtlasTaskGovernancePolicyPlane::isolationContract().
    'isolation_contract' => 'shared_local_main_with_scope_lock',

    // Default for AtlasTaskGovernancePolicyPlane::canaryEnabled(). Gates AtlasTaskPostLandCanarySentinel
    // in the serving report commit path. OFF by default — flipping to true is an operator decision.
    'canary_enabled' => false,

    // Default for AtlasTaskGovernancePolicyPlane::autoRespecOnQuarantineEnabled(). When a give_back
    // report quarantines a packet (repeated give-backs), run the atlas:task:repair-blocked pass
    // automatically (fail-open, bounded) so a doomed spec becomes respec instead of burning muscle.
    // OFF by default — flipping to true is an operator decision.
    'auto_respec_on_quarantine' => false,

    // Default for AtlasTaskGovernancePolicyPlane::evidenceContractMode(). off|observe|enforce.
    // Gates AtlasVerificationCourtEvidenceContract on the serving report commit path. observe
    // records the verdict without blocking (bootstrap-safe default); enforce refuses a commit
    // whose evidence fails the receipt-chain contract, keeping the lease.
    'evidence_contract_mode' => 'observe',

    // Default for AtlasTaskGovernancePolicyPlane::refactorProofMode(). off|observe|enforce.
    // Gates AtlasRefactorProofGate on the serving report commit path for refactor/optimize
    // objectives: the delivery must PROVE a measurable delta (less code, complexity,
    // duplication, or shorter functions) — a green diff that improves nothing, a pure move,
    // or a wrapper-only "abstraction" is not a delivered refactor. observe records the
    // proof without blocking; enforce refuses the commit, keeping the lease so the worker
    // improves the delivery and re-reports.
    'refactor_proof_mode' => 'observe',

    // F0 da limpeza 05/07 — off|observe|enforce. Gates AtlasTaskDuplicateReuseGate no commit
    // path do serving report: entrega que DECLARA class/interface/trait/enum homônima de uma
    // já declarada por outro arquivo (app/ + tests/) re-implementa em vez de reusar e é
    // recusada, mantendo a lease. ENFORCE por default (diferente dos gates vizinhos) porque o
    // sinal é preciso — 14 basenames duplicados em 7.3k arquivos, e um deles quebrou o load da
    // suíte inteira em 05/07 ("Cannot redeclare class"). Blocos clonados de irmãos do mesmo
    // diretório são SEMPRE só observação no receipt, em qualquer modo.
    'dedup_reuse_mode' => 'enforce',

    // Obra #6 V0 — admission gate v2: off|observe|enforce. Gate os DOIS produtores de entropia que o
    // dedup_reuse_mode (nome de classe) não cobre: lógica quase-duplicada (AtlasTaskDuplicateReuseGate
    // ::evaluateLogicReuse, >= 30 linhas idênticas em outro arquivo de app/) e classe 0-ref sem tag
    // @unwired-until (AtlasTaskWiringAdmissionGate). observe grava o veredito no receipt sem bloquear;
    // enforce recusa o commit mantendo a lease (worker reusa/liga/tagueia e re-reporta).
    // ENFORCE desde 05/07/2026 (Obra #7 W1, autorizado pelo goal do operador) com prova publicada:
    // auditoria da janela observe = 174 receipts, 114 blockers (2 logic + 112 wiring) TODOS
    // re-confirmados true-positive pelo gate pós-fix e8f3a0e999 + spot-check por rg — ZERO falso-
    // positivo. A regra viaja ao worker no envelope do next ('delivery_rules'), então cumprir é
    // possível: reusar/extrair em vez de copiar, e wire real ou @unwired-until datado.
    'admission_v2_mode' => 'enforce',

    // When true, the packet builder BLOCKS a heavy refactor (refactor-shaped objective over
    // 3+ allowed_files) that arrives without a complete refactor_design_spec (problem, real
    // callers, proposed abstraction, rejected alternative, risk, expected measurable delta).
    // False (default) records the same fact as a packet warning — visible, not blocking.
    'refactor_design_spec_required' => false,

    // Semantic ARCHITECTURE JUDGE over refactor deliveries (advisory|off). Local hermes
    // one-shot (S53 arbiter pattern — worker-side, zero cloud spend) reads the seam diff
    // + design spec and judges clarity/coupling/safety — what shrink metrics cannot see.
    // Never blocks; the verdict rides the refactor_delta_proof receipt. Operator directive
    // 03/07: provider verboo, strongest qwen.
    'refactor_semantic_judge' => env('ATLAS_REFACTOR_SEMANTIC_JUDGE', 'advisory'),
    'refactor_judge_binary' => 'hermes',
    'refactor_judge_model' => 'verboo/qwen3.6-35b',
    'refactor_judge_timeout_seconds' => 120,

    // Per risk-level governance policy. required_checks is a subset of:
    //   syntax, boot, task_tests, required_test
    'risk_levels' => [
        'low' => [
            'mode' => 'observe',
            'required_checks' => ['syntax', 'boot'],
            'in_release_window' => true,
        ],
        'medium' => [
            'mode' => 'observe',
            'required_checks' => ['syntax', 'boot', 'task_tests'],
            'in_release_window' => true,
        ],
        'high' => [
            'mode' => 'observe',
            'required_checks' => ['syntax', 'boot', 'task_tests', 'required_test'],
            'in_release_window' => false,
        ],
        'critical' => [
            'mode' => 'observe',
            'required_checks' => ['syntax', 'boot', 'task_tests', 'required_test'],
            'in_release_window' => false,
        ],
    ],

    // Dev model-tier policy: (task_kind, risk_level, workcell_size_class) -> small|medium|frontier|split.
    // Read by AtlasTaskGovernancePolicyPlane::modelTierFor(); an undeclared combination safely falls back
    // to 'frontier' (today's behavior), never silently downgrading to a cheaper model.
    'dev_model_tier_policy' => [
        'read_only' => [
            'low' => [
                'small' => 'small',
                'medium' => 'medium',
            ],
        ],
        'write' => [
            'low' => [
                'small' => 'medium',
            ],
        ],
    ],
];
