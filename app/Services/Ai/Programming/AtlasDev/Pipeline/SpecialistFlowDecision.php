<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

/**
 * Auditable specialist flow decision emitted by {@see SpecialistFlowRouter}.
 *
 * Canon schema: `atlas.dev.specialist_flow_decision.v1`. The router classifies
 * an Atlas Dev run into one of the 9 canonical specialist flows and chooses
 * a path (fast / deep / ask_clarification / escalate). The decision carries
 * its own deterministic hash so it can be persisted as a receipt next to the
 * Mandatory RAG Gate result and the routing_decision.json artifact.
 */
final class SpecialistFlowDecision
{
    public const SCHEMA_VERSION = 'atlas.dev.specialist_flow_decision.v1';

    // Specialist flows — superset of Atlas AI Router flow_ids the operator
    // can land on after Atlas Dev intake. Names match the canonical
    // taxonomy in atlas-canonical-glossary-and-naming.md.
    public const FLOW_PLAN = 'plan';

    public const FLOW_CODE = 'code';

    public const FLOW_DEBUG = 'debug';

    public const FLOW_REVIEW = 'review';

    public const FLOW_RESEARCH = 'research';

    public const FLOW_EXPLAIN = 'explain';

    public const FLOW_TEST = 'test';

    public const FLOW_REFACTOR = 'refactor';

    public const FLOW_FORGE_ESCALATION = 'forge_escalation';

    public const FLOWS = [
        self::FLOW_PLAN,
        self::FLOW_CODE,
        self::FLOW_DEBUG,
        self::FLOW_REVIEW,
        self::FLOW_RESEARCH,
        self::FLOW_EXPLAIN,
        self::FLOW_TEST,
        self::FLOW_REFACTOR,
        self::FLOW_FORGE_ESCALATION,
    ];

    // Paths within the chosen flow.
    public const PATH_FAST = 'fast';

    public const PATH_DEEP = 'deep';

    public const PATH_ASK_CLARIFICATION = 'ask_clarification';

    public const PATH_ESCALATE = 'escalate';

    public const PATHS = [
        self::PATH_FAST,
        self::PATH_DEEP,
        self::PATH_ASK_CLARIFICATION,
        self::PATH_ESCALATE,
    ];

    /**
     * Map each specialist flow to the canonical Atlas AI flow_id consumed by
     * the Atlas AI Router runtime (atlas-ai-router-runtime-enterprise-upgrade).
     */
    public const FLOW_TO_ATLAS_AI_FLOW_ID = [
        self::FLOW_PLAN => 'atlas_plan',
        self::FLOW_CODE => 'atlas_dev',
        self::FLOW_DEBUG => 'atlas_debug',
        self::FLOW_REVIEW => 'atlas_review',
        self::FLOW_RESEARCH => 'atlas_research',
        self::FLOW_EXPLAIN => 'atlas_explain',
        self::FLOW_TEST => 'atlas_dev',
        self::FLOW_REFACTOR => 'atlas_dev',
        self::FLOW_FORGE_ESCALATION => 'atlas_forge',
    ];

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $matchedSignals
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $specialistFlow,
        public readonly string $atlasAiFlowId,
        public readonly string $path,
        public readonly bool $escalateToForge,
        public readonly bool $ambiguous,
        public readonly array $reasons,
        public readonly array $matchedSignals,
        public readonly string $taskKind,
        public readonly string $riskLevel,
        public readonly string $decisionHash,
    ) {}

    public function isAmbiguous(): bool
    {
        return $this->ambiguous;
    }

    public function isEscalation(): bool
    {
        return $this->escalateToForge;
    }

    public function isFastPath(): bool
    {
        return $this->path === self::PATH_FAST;
    }

    public function isDeepPath(): bool
    {
        return $this->path === self::PATH_DEEP;
    }

    /**
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        $payload = [
            'ambiguous' => $this->ambiguous,
            'atlas_ai_flow_id' => $this->atlasAiFlowId,
            'escalate_to_forge' => $this->escalateToForge,
            'matched_signals' => array_values($this->matchedSignals),
            'path' => $this->path,
            'reasons' => array_values($this->reasons),
            'risk_level' => $this->riskLevel,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'specialist_flow' => $this->specialistFlow,
            'task_kind' => $this->taskKind,
        ];
        $payload['decision_hash'] = CanonicalHasher::hashWithout($payload, 'decision_hash');

        return CanonicalJson::canonicalize($payload);
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }
}
