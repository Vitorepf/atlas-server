<?php

namespace App\Services\Ai\Programming\Kernel;

class ProgrammingDomainKernelCanon
{
    public const DOMAIN_ID = 'programming';

    public const DOMAIN_NAME = 'Atlas Programming Domain';

    public const SCHEMA_RUNTIME = 'atlas.ai.programming.runtime.v1';

    public const SCHEMA_CONTROL_PLANE = 'atlas.ai.programming.control_plane.v1';

    public const SCHEMA_READINESS = 'atlas.ai.programming.readiness.v1';

    public const CAPABILITIES = [
        'programming.dev',
        'programming.repair',
        'programming.review',
        'programming.refactor',
        'programming.qa',
        'programming.security',
        'programming.database',
        'programming.visual',
        'programming.forge',
    ];

    public const ESCALATION_REASONS = [
        'scope_too_large',
        'sdd_required',
        'multiagent',
        'risk_high',
        'evidence_insufficient',
        'time_budget_exceeded',
    ];

    public const FORGE_KEYWORDS = [
        'obra', 'epico', 'epic ',
        'migrar monolito', 'rewrite', 'rewrite total',
        'multi-agent', 'multiagent', 'multi agent',
        'sdd', 'spec-driven', 'spec driven',
        'multiplos modulos', 'multi-modulo', 'multi modulo', 'cross-module', 'cross module',
        'arquitetura nova', 'nova arquitetura',
        'compliance', 'auditoria regulatoria',
    ];

    public const HIGH_RISK_KEYWORDS = [
        'deploy', 'producao', 'prod ', 'release',
        'billing', 'pagamento', 'cobranca', 'stripe',
        'auth', 'autentica', 'oauth', 'sso',
        'security', 'seguranca',
        'compliance', 'pii', 'gdpr', 'lgpd',
        'migration', 'migracao', 'drop table', 'truncate',
    ];

    /**
     * Keyword → capability map. Order matters: more specific / higher-risk
     * intents are checked before generic ones (e.g. "security review" must
     * win on "security" before falling into the "review" bucket).
     */
    public const DEV_INTENT_TO_CAPABILITY = [
        'security' => 'programming.security',
        'seguranca' => 'programming.security',
        'database' => 'programming.database',
        'migration' => 'programming.database',
        'banco' => 'programming.database',
        'screenshot' => 'programming.visual',
        'visual' => 'programming.visual',
        'responsiv' => 'programming.visual',
        'refactor' => 'programming.refactor',
        'refatorar' => 'programming.refactor',
        'debug' => 'programming.repair',
        'repair' => 'programming.repair',
        'corrigir' => 'programming.repair',
        'fix' => 'programming.repair',
        'review' => 'programming.review',
        'revisar' => 'programming.review',
        'test' => 'programming.qa',
        'teste' => 'programming.qa',
        'qa' => 'programming.qa',
        'plan' => 'programming.dev',
        'planejar' => 'programming.dev',
        'design' => 'programming.dev',
        'ui ' => 'programming.visual',
    ];

    public const DEFAULT_QUALITY_GATES = [
        'placement_verified',
        'spec_present',
        'tests_green',
        'review_signed',
        'evidence_attached',
    ];

    public const EVIDENCE_SCHEMA = [
        'test',
        'diff',
        'doc',
        'command',
        'receipt',
        'source',
        'artifact',
        'blocker',
        'certification',
    ];

    public const TOOLS_ALLOWED = [
        'filesystem.read',
        'command.local_readonly',
        'docs.search',
        'github.readonly',
        'artifact.write_local',
        'test.local_command',
        'evidence.attach',
        'policy.evaluate',
    ];

    public const HANDOFF_RULES = [
        'allowed' => ['programming', 'research', 'cyber', 'operations'],
        'forbidden' => [],
    ];

    public const DELIVERY_TYPES = ['patch_set', 'spec_pack', 'review_report', 'incident_report'];

    public const METRICS = [
        'delivery_lead_time',
        'review_pass_rate',
        'evidence_completeness',
        'dev_to_forge_escalations',
        'policy_block_count',
    ];

    public const FORBIDDEN_ACTIONS = [
        'silent merge',
        'unreviewed deploy',
        'production deploy without approval',
        'destructive migration without rollback',
    ];

    public static function classifyDevCapability(string $rawPrompt): string
    {
        $normalized = mb_strtolower($rawPrompt);
        foreach (self::DEV_INTENT_TO_CAPABILITY as $keyword => $capability) {
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return $capability;
            }
        }

        return 'programming.dev';
    }

    public static function shouldEscalateToForge(string $rawPrompt, ?string $missionType = null): ?string
    {
        $normalized = mb_strtolower($rawPrompt);

        if ($missionType === 'obra') {
            return 'scope_too_large';
        }

        foreach (self::FORGE_KEYWORDS as $keyword) {
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return match (true) {
                    str_contains($keyword, 'sdd') || str_contains($keyword, 'spec') => 'sdd_required',
                    str_contains($keyword, 'multi') => 'multiagent',
                    default => 'scope_too_large',
                };
            }
        }

        $words = preg_split('/\s+/', trim($normalized)) ?: [];
        if (count($words) >= 60) {
            return 'scope_too_large';
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    public static function detectHighRiskActions(string $rawPrompt): array
    {
        $normalized = mb_strtolower($rawPrompt);
        $hits = [];
        foreach (self::HIGH_RISK_KEYWORDS as $keyword) {
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                $hits[] = $keyword;
            }
        }

        return array_values(array_unique($hits));
    }
}
