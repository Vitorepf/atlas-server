<?php

use App\Services\Ai\Domain\AtlasHealthOrchestrator;
use App\Services\Ai\Domain\AtlasLearningOrchestrator;
use App\Services\Ai\Domain\AtlasMarketingOrchestrator;
use App\Services\Ai\Domain\AtlasOperationsOrchestrator;
use App\Services\Ai\Domain\AtlasQaOrchestrator;
use App\Services\Ai\Domain\AtlasResearchOrchestrator;
use App\Services\Ai\Domain\AtlasSecurityOrchestrator;
use App\Services\Ai\Domain\AtlasWritingOrchestrator;
use App\Services\Ai\Domain\BackgroundSafetyOrchestrator;
use App\Services\Ai\Domain\StandardResponseOrchestrator;
use App\Services\Ai\Finance\AtlasFinanceOrchestrator;
use App\Services\Ai\PersonalDevelopment\AtlasPersonalDevelopmentOrchestrator;
use App\Services\Ai\Programming\AtlasProgrammingOrchestrator;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementOrchestrator;

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
        'atlas_vault' => [
            'label' => 'AtlasVault / Obsidian',
            'capabilities' => [
                'atlas.input.text',
                'atlas.input.file_attachment',
                'atlas.human_knowledge.vault',
                'atlas.human_knowledge.managed_note_projection',
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
            'required_surfaces' => ['atlas_cli', 'atlas_app', 'atlas_api', 'atlas_worker', 'atlas_vault'],
            'optional_surfaces' => [],
            'not_supported' => [
                [
                    'surface' => 'atlas_mcp_readonly',
                    'reason' => 'The read-only MCP surface exposes tools and context resources, not operator-authored text input.',
                ],
            ],
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
                [
                    'surface' => 'atlas_vault',
                    'reason' => 'AtlasVault stores and projects markdown notes, but it does not read live clipboard image paste as an operator input surface.',
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
            'required_surfaces' => ['atlas_cli', 'atlas_app', 'atlas_api', 'atlas_vault'],
            'optional_surfaces' => ['atlas_worker'],
            'not_supported' => [
                [
                    'surface' => 'atlas_mcp_readonly',
                    'reason' => 'The read-only MCP surface returns context resources and does not accept uploaded files.',
                ],
            ],
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
            'not_supported' => [
                [
                    'surface' => 'atlas_vault',
                    'reason' => 'AtlasVault is a human knowledge workspace; raw notes are promoted to memory through import, review, privacy, and provider-safety gates instead of direct memory recall.',
                ],
            ],
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
            'not_supported' => [
                [
                    'surface' => 'atlas_vault',
                    'reason' => 'AtlasVault can provide source material for reviewed ingestion, but it does not compose runtime provider context directly.',
                ],
            ],
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
                [
                    'surface' => 'atlas_vault',
                    'reason' => 'AtlasVault is a human workspace and managed-note projection surface, not a tool execution surface.',
                ],
            ],
            'test_suite' => [
                'tests/Feature/AtlasToolRuntimeCoreTest.php',
                'tests/Unit/AiToolRuntimeTest.php',
            ],
        ],
        'atlas.human_knowledge.vault' => [
            'schema_version' => 'atlas.capability.v1',
            'id' => 'atlas.human_knowledge.vault',
            'version' => '1.0.0',
            'title' => 'Human Knowledge Workspace',
            'owner' => 'atlas.human_knowledge',
            'description' => 'Expose AtlasVault/Obsidian as a governed human writing, reading, review, and research workspace without making raw notes an operational source of truth.',
            'required_surfaces' => ['atlas_vault'],
            'optional_surfaces' => [],
            'not_supported' => [
                [
                    'surface' => 'atlas_cli',
                    'reason' => 'CLI commands can trigger vault import/export, but the human knowledge workspace itself is AtlasVault/Obsidian.',
                ],
                [
                    'surface' => 'atlas_app',
                    'reason' => 'The app can inspect and manage vault workflows, but it is not the Obsidian workspace surface.',
                ],
                [
                    'surface' => 'atlas_api',
                    'reason' => 'The API exposes vault operations, but it is not the human knowledge workspace surface.',
                ],
                [
                    'surface' => 'atlas_worker',
                    'reason' => 'Workers consume normalized jobs and do not act as human note workspaces.',
                ],
                [
                    'surface' => 'atlas_mcp_readonly',
                    'reason' => 'Read-only MCP exposes provider-safe resources and does not host human note authoring.',
                ],
            ],
            'test_suite' => [
                'tests/Unit/Ai/Surface/SurfaceAdaptersTest.php',
                'tests/Feature/AtlasVaultCommandTest.php',
            ],
        ],
        'atlas.human_knowledge.managed_note_projection' => [
            'schema_version' => 'atlas.capability.v1',
            'id' => 'atlas.human_knowledge.managed_note_projection',
            'version' => '1.0.0',
            'title' => 'Managed Note Projection',
            'owner' => 'atlas.human_knowledge',
            'description' => 'Project reviewed Atlas memory, evidence, summaries, and proposals into managed AtlasVault notes with frontmatter, links, conflict checks, and human review boundaries.',
            'required_surfaces' => ['atlas_vault'],
            'optional_surfaces' => [],
            'not_supported' => [
                [
                    'surface' => 'atlas_cli',
                    'reason' => 'CLI can request projections, but the managed note projection target is AtlasVault.',
                ],
                [
                    'surface' => 'atlas_app',
                    'reason' => 'The app can display projection status, but managed markdown notes are written to AtlasVault.',
                ],
                [
                    'surface' => 'atlas_api',
                    'reason' => 'The API can request projection workflows, but managed notes are projected to AtlasVault.',
                ],
                [
                    'surface' => 'atlas_worker',
                    'reason' => 'Workers may execute projection jobs, but they are not the managed note projection surface.',
                ],
                [
                    'surface' => 'atlas_mcp_readonly',
                    'reason' => 'Read-only MCP does not write managed notes.',
                ],
            ],
            'test_suite' => [
                'tests/Unit/AtlasVaultManagedNoteServiceTest.php',
                'tests/Feature/AtlasVaultCommandTest.php',
            ],
        ],
    ],

    'domain_orchestrators' => [
        'StandardResponseOrchestrator' => [
            'class' => StandardResponseOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['general'],
            'flows' => ['general.answer'],
        ],
        'AtlasResearchOrchestrator' => [
            'class' => AtlasResearchOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['research'],
            'flows' => ['research.quick', 'research.super'],
        ],
        'AtlasProgrammingOrchestrator' => [
            'class' => AtlasProgrammingOrchestrator::class,
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
            'class' => AtlasFinanceOrchestrator::class,
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
            'class' => AtlasPersonalDevelopmentOrchestrator::class,
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
            'class' => AtlasHealthOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['health'],
            'flows' => ['health.review'],
        ],
        'AtlasLearningOrchestrator' => [
            'class' => AtlasLearningOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['learning'],
            'flows' => ['learning.plan'],
        ],
        'AtlasWritingOrchestrator' => [
            'class' => AtlasWritingOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['writing'],
            'flows' => ['writing.draft'],
        ],
        'AtlasQaOrchestrator' => [
            'class' => AtlasQaOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['qa'],
            'flows' => ['qa.regression_review'],
        ],
        'AtlasSecurityOrchestrator' => [
            'class' => AtlasSecurityOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['security'],
            'flows' => ['security.threat_review'],
        ],
        'AtlasOperationsOrchestrator' => [
            'class' => AtlasOperationsOrchestrator::class,
            'maturity' => 'scaffold',
            'domains' => ['operations'],
            'flows' => ['operations.diagnostic'],
        ],
        'AtlasMarketingOrchestrator' => [
            'class' => AtlasMarketingOrchestrator::class,
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
            'class' => AtlasSelfImprovementOrchestrator::class,
            'maturity' => 'implemented',
            'domains' => ['self_improvement'],
            'flows' => [
                'self_improvement.nightly_review',
                'self_improvement.weekly_architecture_audit',
                'self_improvement.capability_gap_scan',
                'self_improvement.benchmark_review',
                'self_improvement.memory_quality_review',
                'self_improvement.tool_runtime_review',
                'self_improvement.repair_loop_review',
                'self_improvement.kernel_pipeline_review',
                'self_improvement.domain_learning_review',
                'self_improvement.docs_drift_review',
                'self_improvement.provider_performance_review',
                'self_improvement.proposal_generation',
            ],
        ],
        'BackgroundSafetyOrchestrator' => [
            'class' => BackgroundSafetyOrchestrator::class,
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
            explode(',', (string) env('ATLAS_AI_SELF_IMPROVEMENT_FLOWS', 'nightly_review,weekly_architecture_audit,repair_loop_review,kernel_pipeline_review'))
        ))),
        'hours' => (int) env('ATLAS_AI_SELF_IMPROVEMENT_HOURS', 24),
        'limit' => (int) env('ATLAS_AI_SELF_IMPROVEMENT_LIMIT', 5),
        'emit' => (bool) env('ATLAS_AI_SELF_IMPROVEMENT_EMIT', false),
    ],

    'ledger_projection' => [
        'enabled' => (bool) env('ATLAS_AI_LEDGER_PROJECTION_ENABLED', true),
        'hours' => (int) env('ATLAS_AI_LEDGER_PROJECTION_HOURS', 24),
        'limit' => (int) env('ATLAS_AI_LEDGER_PROJECTION_LIMIT', 500),
        'max_lag_seconds' => (int) env('ATLAS_AI_LEDGER_PROJECTION_MAX_LAG_SECONDS', 900),
    ],
];
