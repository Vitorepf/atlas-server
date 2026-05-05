<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Atlas AI Kernel Registry
    |--------------------------------------------------------------------------
    |
    | These registries are intentionally small at first. They make horizontal
    | capabilities executable and testable before the full database-backed
    | kernel exists.
    |
    */

    'surfaces' => [
        'atlas_cli' => [
            'label' => 'Atlas CLI',
            'capabilities' => [
                'atlas.input.text',
                'atlas.input.image_paste',
                'atlas.input.file_attachment',
                'atlas.memory.recall',
                'atlas.context.compose',
                'atlas.tools.runtime',
            ],
        ],
        'atlas_app' => [
            'label' => 'Atlas App',
            'capabilities' => [
                'atlas.input.text',
                'atlas.input.image_paste',
                'atlas.input.file_attachment',
                'atlas.memory.recall',
                'atlas.context.compose',
            ],
        ],
        'atlas_api' => [
            'label' => 'Atlas API',
            'capabilities' => [
                'atlas.input.text',
                'atlas.input.image_paste',
                'atlas.input.file_attachment',
                'atlas.memory.recall',
                'atlas.context.compose',
                'atlas.tools.runtime',
            ],
        ],
        'atlas_worker' => [
            'label' => 'Atlas Worker',
            'capabilities' => [
                'atlas.input.text',
                'atlas.memory.recall',
                'atlas.context.compose',
                'atlas.tools.runtime',
            ],
        ],
        'atlas_mcp_readonly' => [
            'label' => 'Atlas MCP Read-Only',
            'capabilities' => [
                'atlas.memory.recall',
                'atlas.context.compose',
            ],
        ],
    ],

    'capabilities' => [
        'atlas.input.text' => [
            'schema_version' => 'atlas.capability.v1',
            'id' => 'atlas.input.text',
            'version' => '1.0.0',
            'title' => 'Text Input',
            'owner' => 'atlas.input',
            'description' => 'Accept text input from an operator or automation surface.',
            'required_surfaces' => ['atlas_cli', 'atlas_app', 'atlas_api', 'atlas_worker'],
            'optional_surfaces' => ['atlas_mcp_readonly'],
            'test_suite' => [
                'tests/Feature/AtlasCliDevCommandTest.php',
            ],
        ],
        'atlas.input.image_paste' => [
            'schema_version' => 'atlas.capability.v1',
            'id' => 'atlas.input.image_paste',
            'version' => '1.0.0',
            'title' => 'Image Paste',
            'owner' => 'atlas.input',
            'description' => 'Normalize clipboard or pasted images as first-class Atlas input attachments.',
            'required_surfaces' => ['atlas_cli', 'atlas_app', 'atlas_api'],
            'optional_surfaces' => [],
            'not_supported' => [
                [
                    'surface' => 'atlas_mcp_readonly',
                    'reason' => 'The current MCP surface is read-only and does not accept binary upload.',
                ],
                [
                    'surface' => 'atlas_worker',
                    'reason' => 'Workers receive normalized attachments from upstream surfaces instead of reading a clipboard.',
                ],
            ],
            'test_suite' => [
                'tests/Feature/AiChatCommandPasteImageTest.php',
                'tests/Unit/AtlasImageAttachmentServiceTest.php',
            ],
        ],
        'atlas.input.file_attachment' => [
            'schema_version' => 'atlas.capability.v1',
            'id' => 'atlas.input.file_attachment',
            'version' => '1.0.0',
            'title' => 'File Attachment',
            'owner' => 'atlas.input',
            'description' => 'Normalize local files and uploaded artifacts as Atlas input attachments.',
            'required_surfaces' => ['atlas_cli', 'atlas_app', 'atlas_api'],
            'optional_surfaces' => ['atlas_worker'],
            'test_suite' => [
                'tests/Feature/AiAttachmentContentTest.php',
                'tests/Unit/AtlasFileAttachmentServiceTest.php',
            ],
        ],
        'atlas.memory.recall' => [
            'schema_version' => 'atlas.capability.v1',
            'id' => 'atlas.memory.recall',
            'version' => '1.0.0',
            'title' => 'Memory Recall',
            'owner' => 'atlas.memory',
            'description' => 'Retrieve provider-safe memory, knowledge, and context references.',
            'required_surfaces' => ['atlas_cli', 'atlas_app', 'atlas_api', 'atlas_worker', 'atlas_mcp_readonly'],
            'optional_surfaces' => [],
            'test_suite' => [
                'tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php',
                'tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php',
            ],
        ],
        'atlas.context.compose' => [
            'schema_version' => 'atlas.capability.v1',
            'id' => 'atlas.context.compose',
            'version' => '1.0.0',
            'title' => 'Context Compose',
            'owner' => 'atlas.context',
            'description' => 'Build a deterministic context pack from memory, docs, code refs, conversation, and attachments.',
            'required_surfaces' => ['atlas_cli', 'atlas_app', 'atlas_api', 'atlas_worker', 'atlas_mcp_readonly'],
            'optional_surfaces' => [],
            'test_suite' => [
                'tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php',
            ],
        ],
        'atlas.tools.runtime' => [
            'schema_version' => 'atlas.capability.v1',
            'id' => 'atlas.tools.runtime',
            'version' => '1.0.0',
            'title' => 'Super Tool Runtime',
            'owner' => 'atlas.tools',
            'description' => 'Execute governed local tools through registry, policy, normalizers, evidence, and gates.',
            'required_surfaces' => ['atlas_cli', 'atlas_api', 'atlas_worker'],
            'optional_surfaces' => ['atlas_app'],
            'not_supported' => [
                [
                    'surface' => 'atlas_mcp_readonly',
                    'reason' => 'The read-only MCP surface can inspect memory/context but cannot execute local tools.',
                ],
            ],
            'test_suite' => [
                'tests/Feature/AtlasToolRuntimeCoreTest.php',
                'tests/Unit/AiToolRuntimeTest.php',
            ],
        ],
    ],

    'domain_orchestrators' => [
        'StandardResponseOrchestrator' => [
            'class' => App\Services\Ai\Domain\StandardResponseOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['general'],
            'flows' => ['general.answer'],
        ],
        'AtlasResearchOrchestrator' => [
            'class' => App\Services\Ai\Domain\AtlasResearchOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['research'],
            'flows' => ['research.quick', 'research.super'],
        ],
        'AtlasProgrammingOrchestrator' => [
            'class' => App\Services\Ai\Programming\AtlasProgrammingOrchestrator::class,
            'maturity' => 'implemented',
            'domains' => ['programming'],
            'flows' => [
                'programming.dev',
                'programming.repair',
                'programming.review',
                'programming.refactor',
                'programming.qa',
                'programming.security',
                'programming.database',
                'programming.visual',
                'programming.forge',
            ],
        ],
        'AtlasFinanceOrchestrator' => [
            'class' => App\Services\Ai\Finance\AtlasFinanceOrchestrator::class,
            'maturity' => 'implemented',
            'domains' => ['finance'],
            'flows' => [
                'finance.market_research',
                'finance.risk_review',
                'finance.portfolio_analysis',
                'finance.trade_thesis',
                'finance.macro_review',
                'finance.earnings_review',
                'finance.news_impact',
                'finance.compliance_review',
                'finance.backtest_plan',
                'finance.forge',
            ],
        ],
        'AtlasPersonalDevelopmentOrchestrator' => [
            'class' => App\Services\Ai\PersonalDevelopment\AtlasPersonalDevelopmentOrchestrator::class,
            'maturity' => 'implemented',
            'domains' => ['personal_development'],
            'flows' => [
                'personal_development.reflect',
                'personal_development.daily_review',
                'personal_development.weekly_review',
                'personal_development.habit_design',
                'personal_development.focus_plan',
                'personal_development.learning_plan',
                'personal_development.energy_review',
                'personal_development.goal_decomposition',
                'personal_development.recovery_plan',
                'personal_development.forge',
            ],
        ],
        'AtlasHealthOrchestrator' => [
            'class' => App\Services\Ai\Domain\AtlasHealthOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['health'],
            'flows' => ['health.review'],
        ],
        'AtlasLearningOrchestrator' => [
            'class' => App\Services\Ai\Domain\AtlasLearningOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['learning'],
            'flows' => ['learning.plan'],
        ],
        'AtlasWritingOrchestrator' => [
            'class' => App\Services\Ai\Domain\AtlasWritingOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['writing'],
            'flows' => ['writing.draft'],
        ],
        'AtlasQaOrchestrator' => [
            'class' => App\Services\Ai\Domain\AtlasQaOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['qa'],
            'flows' => ['qa.regression_review'],
        ],
        'AtlasSecurityOrchestrator' => [
            'class' => App\Services\Ai\Domain\AtlasSecurityOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['security'],
            'flows' => ['security.threat_review'],
        ],
        'AtlasOperationsOrchestrator' => [
            'class' => App\Services\Ai\Domain\AtlasOperationsOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['operations'],
            'flows' => ['operations.diagnostic'],
        ],
        'AtlasMarketingOrchestrator' => [
            'class' => App\Services\Ai\Domain\AtlasMarketingOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['marketing'],
            'flows' => [
                'marketing.strategy',
                'marketing.research',
                'marketing.positioning',
                'marketing.campaign',
                'marketing.creative',
                'marketing.copywriting',
                'marketing.media_plan',
                'marketing.landing_page',
                'marketing.email',
                'marketing.social',
                'marketing.video_script',
                'marketing.ab_test',
                'marketing.analytics',
                'marketing.brand_review',
                'marketing.forge',
            ],
        ],
        'AtlasSelfImprovementOrchestrator' => [
            'class' => App\Services\Ai\SelfImprovement\AtlasSelfImprovementOrchestrator::class,
            'maturity' => 'implemented',
            'domains' => ['self_improvement'],
            'flows' => [
                'self_improvement.nightly_review',
                'self_improvement.weekly_architecture_audit',
                'self_improvement.capability_gap_scan',
                'self_improvement.benchmark_review',
                'self_improvement.memory_quality_review',
                'self_improvement.tool_runtime_review',
                'self_improvement.domain_learning_review',
                'self_improvement.docs_drift_review',
                'self_improvement.provider_performance_review',
                'self_improvement.proposal_generation',
            ],
        ],
        'BackgroundSafetyOrchestrator' => [
            'class' => App\Services\Ai\Domain\BackgroundSafetyOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['background'],
            'flows' => ['background.safe'],
        ],
    ],

    'self_improvement' => [
        'enabled' => (bool) env('ATLAS_AI_SELF_IMPROVEMENT_ENABLED', false),
        'time' => env('ATLAS_AI_SELF_IMPROVEMENT_TIME', '02:00'),
        'flows' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ATLAS_AI_SELF_IMPROVEMENT_FLOWS', 'nightly_review'))
        ))),
        'hours' => (int) env('ATLAS_AI_SELF_IMPROVEMENT_HOURS', 24),
        'limit' => (int) env('ATLAS_AI_SELF_IMPROVEMENT_LIMIT', 5),
        'emit' => (bool) env('ATLAS_AI_SELF_IMPROVEMENT_EMIT', false),
    ],
];
