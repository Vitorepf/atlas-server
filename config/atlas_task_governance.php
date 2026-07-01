<?php

declare(strict_types=1);

/**
 * v3 Policy Plane for the task lane: governance policy as inspectable DATA instead of scattered
 * env reads and hard-codes. Read by {@see \App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernancePolicyPlane}.
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
