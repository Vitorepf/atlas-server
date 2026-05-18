<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Canonical `atlas.dev_to_forge.escalation_packet.v1` contract.
 *
 * Atlas AI has two programming cores:
 *
 *   1. Atlas Dev   · light/medium daily flow.
 *   2. Atlas Forge · heavy Obra factory.
 *
 * When Dev decides a task crossed the Obra threshold (scope expansion,
 * SDD-required, multi-agent, high risk, time-budget exceeded, evidence
 * insufficient) it MUST emit ONE honest, auditable handoff packet so Forge
 * picks the work up without losing context.
 *
 * This contract is the canonical packet declared in
 * `docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md`
 * (§Dev -> Forge Escalation Packet, lines 270-301). It is intentionally NOT
 * a partial implementation of an Obra — it is the handoff envelope: intent,
 * normalized interpretation, why escalated, Dev findings/actions, suggested
 * work packets, Definition of Done, required + collected evidence,
 * constraints/non-goals and recommended Forge mode.
 *
 * The previous local schema `atlas.dev.forge_promotion_preview.v1` (in
 * `ForgePromotionPreviewBuilder`) is NOT canonical and is being superseded
 * by this packet. The audit
 * (`atlas-dev-forge-relationship-critical-audit.md`) lists 4 incompatible
 * Dev→Forge mechanisms — this contract is the first step toward consolidating
 * them. Consolidation of the 4 mechanisms is a SEPARATE follow-up.
 */
final class EscalationPacket implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev_to_forge.escalation_packet.v1';

    public const SOURCE_CORE = 'atlas_dev';

    public const TARGET_CORE = 'atlas_forge';

    public const RECOMMENDED_FORGE_MODE_SDD_INTAKE = 'sdd_intake';

    public const RECOMMENDED_FORGE_MODE_OBRA_INTAKE = 'obra_intake';

    public const RECOMMENDED_FORGE_MODE_ARCHITECTURE_REVIEW = 'architecture_review';

    public const RECOMMENDED_FORGE_MODE_LONG_RUN = 'long_run';

    public const ALLOWED_RECOMMENDED_FORGE_MODES = [
        self::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
        self::RECOMMENDED_FORGE_MODE_OBRA_INTAKE,
        self::RECOMMENDED_FORGE_MODE_ARCHITECTURE_REVIEW,
        self::RECOMMENDED_FORGE_MODE_LONG_RUN,
    ];

    /**
     * Canonical promotion triggers (open-ended list — these are the
     * documented ones; callers may pass others when justified).
     */
    public const TRIGGER_SCOPE_TOO_LARGE = 'scope_too_large';

    public const TRIGGER_SDD_REQUIRED = 'sdd_required';

    public const TRIGGER_MULTIAGENT_REQUIRED = 'multiagent_required';

    public const TRIGGER_EVIDENCE_INSUFFICIENT = 'evidence_insufficient';

    public const TRIGGER_HIGH_RISK = 'high_risk';

    public const TRIGGER_TIME_BUDGET_EXCEEDED = 'time_budget_exceeded';

    public const TRIGGER_OPERATOR_REQUESTED = 'operator_requested';

    private const HASH_FIELD = 'packet_hash';

    /**
     * Canonical 6-slot evidence map declared by the dual-core doc.
     *
     * @var list<string>
     */
    public const EVIDENCE_REF_SLOTS = [
        'plan',
        'senior_loop_audit',
        'senior_loop_execution',
        'verification_receipt',
        'error_ledger',
        'failure_capsules',
    ];

    /**
     * @param  list<string>  $promotionTriggers
     * @param  list<string>  $currentDevFindings
     * @param  list<string>  $completedDevActions
     * @param  list<string>  $incompleteDevActions
     * @param  list<array<string,mixed>>  $suggestedWorkPackets
     * @param  list<string>  $definitionOfDone
     * @param  list<string>  $requiredEvidence
     * @param  array<string,mixed>  $evidenceRefs
     * @param  list<string>  $contextRefs
     * @param  list<string>  $constraints
     * @param  list<string>  $nonGoals
     */
    public function __construct(
        public readonly string $packetId,
        public readonly string $originalUserIntent,
        public readonly string $normalizedIntent,
        public readonly string $promotionReason,
        public readonly array $promotionTriggers,
        public readonly string $scopeAssessment,
        public readonly string $riskAssessment,
        public readonly string $ambiguityAssessment,
        public readonly array $currentDevFindings,
        public readonly array $completedDevActions,
        public readonly array $incompleteDevActions,
        public readonly string $recommendedForgeMode,
        public readonly array $suggestedWorkPackets,
        public readonly array $definitionOfDone,
        public readonly array $requiredEvidence,
        public readonly array $evidenceRefs,
        public readonly array $contextRefs,
        public readonly ?string $contextPackHash,
        public readonly array $constraints,
        public readonly array $nonGoals,
        public readonly string $createdAt,
        public readonly string $packetHash,
    ) {
        if ($this->packetId === '') {
            throw new InvalidArgumentException('EscalationPacket.packet_id must not be empty.');
        }
        if (trim($this->originalUserIntent) === '') {
            throw new InvalidArgumentException('EscalationPacket invariant: original_user_intent must not be empty (handoff must preserve the human request verbatim).');
        }
        if (trim($this->normalizedIntent) === '') {
            throw new InvalidArgumentException('EscalationPacket.normalized_intent must not be empty.');
        }
        if (trim($this->promotionReason) === '') {
            throw new InvalidArgumentException('EscalationPacket invariant: promotion_reason must not be empty (Forge cannot accept a silent handoff).');
        }
        if ($this->promotionTriggers === []) {
            throw new InvalidArgumentException('EscalationPacket invariant: promotion_triggers must contain at least one canonical trigger.');
        }
        foreach ($this->promotionTriggers as $i => $trigger) {
            if (! is_string($trigger) || trim($trigger) === '') {
                throw new InvalidArgumentException("EscalationPacket.promotion_triggers[{$i}] must be a non-empty string.");
            }
        }
        if (! in_array($this->recommendedForgeMode, self::ALLOWED_RECOMMENDED_FORGE_MODES, true)) {
            throw new InvalidArgumentException(
                'EscalationPacket.recommended_forge_mode must be one of ['.implode(',', self::ALLOWED_RECOMMENDED_FORGE_MODES)."], got '{$this->recommendedForgeMode}'."
            );
        }
        if ($this->suggestedWorkPackets === []) {
            throw new InvalidArgumentException('EscalationPacket invariant: suggested_work_packets must contain at least one work packet hint so Forge intake has a starting decomposition.');
        }
        foreach ($this->suggestedWorkPackets as $i => $packet) {
            if (! is_array($packet)) {
                throw new InvalidArgumentException("EscalationPacket.suggested_work_packets[{$i}] must be an array.");
            }
            foreach (['id', 'title'] as $required) {
                if (! array_key_exists($required, $packet) || ! is_string($packet[$required]) || trim((string) $packet[$required]) === '') {
                    throw new InvalidArgumentException("EscalationPacket.suggested_work_packets[{$i}].{$required} must be a non-empty string.");
                }
            }
        }
        foreach ($this->evidenceRefs as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('EscalationPacket.evidence_refs keys must be non-empty strings.');
            }
            if ($key === 'failure_capsules') {
                if (! is_array($value)) {
                    throw new InvalidArgumentException('EscalationPacket.evidence_refs.failure_capsules must be an array.');
                }

                continue;
            }
            if ($value !== null && ! is_string($value)) {
                throw new InvalidArgumentException("EscalationPacket.evidence_refs[{$key}] must be a string or null.");
            }
        }
        if ($this->createdAt === '') {
            throw new InvalidArgumentException('EscalationPacket.created_at must not be empty.');
        }
        if ($this->packetHash === '') {
            throw new InvalidArgumentException('EscalationPacket.packet_hash must not be empty.');
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * @return array<string,mixed>
     */
    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'ambiguity_assessment' => $this->ambiguityAssessment,
            'completed_dev_actions' => array_values($this->completedDevActions),
            'constraints' => array_values($this->constraints),
            'context_pack_hash' => $this->contextPackHash,
            'context_refs' => array_values($this->contextRefs),
            'created_at' => $this->createdAt,
            'current_dev_findings' => array_values($this->currentDevFindings),
            'definition_of_done' => array_values($this->definitionOfDone),
            'evidence_refs' => $this->normaliseEvidenceRefs($this->evidenceRefs),
            'incomplete_dev_actions' => array_values($this->incompleteDevActions),
            'non_goals' => array_values($this->nonGoals),
            'normalized_intent' => $this->normalizedIntent,
            'original_user_intent' => $this->originalUserIntent,
            'packet_hash' => $this->packetHash,
            'packet_id' => $this->packetId,
            'promotion_reason' => $this->promotionReason,
            'promotion_triggers' => array_values($this->promotionTriggers),
            'provider_safe' => true,
            'recommended_forge_mode' => $this->recommendedForgeMode,
            'required_evidence' => array_values($this->requiredEvidence),
            'risk_assessment' => $this->riskAssessment,
            'schema_version' => self::SCHEMA_VERSION,
            'scope_assessment' => $this->scopeAssessment,
            'source_core' => self::SOURCE_CORE,
            'suggested_work_packets' => array_values($this->suggestedWorkPackets),
            'target_core' => self::TARGET_CORE,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    /**
     * Hash the canonical payload excluding the `packet_hash` field itself,
     * so the hash is reproducible across machines for the same content.
     */
    public function hash(): string
    {
        $payload = $this->toCanonicalArray();
        unset($payload[self::HASH_FIELD]);

        return CanonicalHasher::hash($payload);
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    /**
     * Static factory that builds a packet with a deterministic hash. Mirrors
     * the `EscalationDecision::issue` pattern — instantiates with a `pending`
     * hash placeholder, computes the canonical hash, then reconstructs.
     *
     * @param  list<string>  $promotionTriggers
     * @param  list<string>  $currentDevFindings
     * @param  list<string>  $completedDevActions
     * @param  list<string>  $incompleteDevActions
     * @param  list<array<string,mixed>>  $suggestedWorkPackets
     * @param  list<string>  $definitionOfDone
     * @param  list<string>  $requiredEvidence
     * @param  array<string,mixed>  $evidenceRefs
     * @param  list<string>  $contextRefs
     * @param  list<string>  $constraints
     * @param  list<string>  $nonGoals
     */
    public static function issue(
        string $packetId,
        string $originalUserIntent,
        string $normalizedIntent,
        string $promotionReason,
        array $promotionTriggers,
        string $scopeAssessment,
        string $riskAssessment,
        string $ambiguityAssessment,
        array $currentDevFindings,
        array $completedDevActions,
        array $incompleteDevActions,
        string $recommendedForgeMode,
        array $suggestedWorkPackets,
        array $definitionOfDone,
        array $requiredEvidence,
        array $evidenceRefs,
        array $contextRefs,
        ?string $contextPackHash,
        array $constraints,
        array $nonGoals,
        string $createdAt,
    ): self {
        $skeleton = new self(
            packetId: $packetId,
            originalUserIntent: $originalUserIntent,
            normalizedIntent: $normalizedIntent,
            promotionReason: $promotionReason,
            promotionTriggers: $promotionTriggers,
            scopeAssessment: $scopeAssessment,
            riskAssessment: $riskAssessment,
            ambiguityAssessment: $ambiguityAssessment,
            currentDevFindings: $currentDevFindings,
            completedDevActions: $completedDevActions,
            incompleteDevActions: $incompleteDevActions,
            recommendedForgeMode: $recommendedForgeMode,
            suggestedWorkPackets: $suggestedWorkPackets,
            definitionOfDone: $definitionOfDone,
            requiredEvidence: $requiredEvidence,
            evidenceRefs: $evidenceRefs,
            contextRefs: $contextRefs,
            contextPackHash: $contextPackHash,
            constraints: $constraints,
            nonGoals: $nonGoals,
            createdAt: $createdAt,
            packetHash: 'pending',
        );
        $hash = $skeleton->hash();

        return new self(
            packetId: $packetId,
            originalUserIntent: $originalUserIntent,
            normalizedIntent: $normalizedIntent,
            promotionReason: $promotionReason,
            promotionTriggers: $promotionTriggers,
            scopeAssessment: $scopeAssessment,
            riskAssessment: $riskAssessment,
            ambiguityAssessment: $ambiguityAssessment,
            currentDevFindings: $currentDevFindings,
            completedDevActions: $completedDevActions,
            incompleteDevActions: $incompleteDevActions,
            recommendedForgeMode: $recommendedForgeMode,
            suggestedWorkPackets: $suggestedWorkPackets,
            definitionOfDone: $definitionOfDone,
            requiredEvidence: $requiredEvidence,
            evidenceRefs: $evidenceRefs,
            contextRefs: $contextRefs,
            contextPackHash: $contextPackHash,
            constraints: $constraints,
            nonGoals: $nonGoals,
            createdAt: $createdAt,
            packetHash: $hash,
        );
    }

    /**
     * Build a fully-zeroed canonical evidence_refs map. Useful for callers
     * that have not yet collected receipts — the canonical 6 slots are
     * always present even when empty, so consumers can rely on the shape.
     *
     * @return array<string,mixed>
     */
    public static function emptyEvidenceRefs(): array
    {
        return [
            'plan' => null,
            'senior_loop_audit' => null,
            'senior_loop_execution' => null,
            'verification_receipt' => null,
            'error_ledger' => null,
            'failure_capsules' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        // Validate source_core / target_core when present (Forge intake will
        // also re-validate, but we surface mismatches at deserialisation).
        $source = AtlasDevSchemaArray::nullableString($payload, 'source_core');
        if ($source !== null && $source !== self::SOURCE_CORE) {
            throw new InvalidArgumentException(
                "EscalationPacket.source_core must be '".self::SOURCE_CORE."', got '{$source}'."
            );
        }
        $target = AtlasDevSchemaArray::nullableString($payload, 'target_core');
        if ($target !== null && $target !== self::TARGET_CORE) {
            throw new InvalidArgumentException(
                "EscalationPacket.target_core must be '".self::TARGET_CORE."', got '{$target}'."
            );
        }

        $rawWorkPackets = $payload['suggested_work_packets'] ?? [];
        if (! is_array($rawWorkPackets)) {
            throw new InvalidArgumentException('EscalationPacket.suggested_work_packets must be a list.');
        }
        $packets = [];
        foreach ($rawWorkPackets as $i => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException("EscalationPacket.suggested_work_packets[{$i}] must be an array.");
            }
            $packets[] = $item;
        }

        $evidenceRefs = $payload['evidence_refs'] ?? self::emptyEvidenceRefs();
        if (! is_array($evidenceRefs)) {
            throw new InvalidArgumentException('EscalationPacket.evidence_refs must be an array.');
        }

        return new self(
            packetId: AtlasDevSchemaArray::string($payload, 'packet_id'),
            originalUserIntent: AtlasDevSchemaArray::string($payload, 'original_user_intent'),
            normalizedIntent: AtlasDevSchemaArray::string($payload, 'normalized_intent'),
            promotionReason: AtlasDevSchemaArray::string($payload, 'promotion_reason'),
            promotionTriggers: AtlasDevSchemaArray::stringList($payload, 'promotion_triggers'),
            scopeAssessment: AtlasDevSchemaArray::string($payload, 'scope_assessment'),
            riskAssessment: AtlasDevSchemaArray::string($payload, 'risk_assessment'),
            ambiguityAssessment: AtlasDevSchemaArray::string($payload, 'ambiguity_assessment'),
            currentDevFindings: AtlasDevSchemaArray::stringList($payload, 'current_dev_findings'),
            completedDevActions: AtlasDevSchemaArray::stringList($payload, 'completed_dev_actions'),
            incompleteDevActions: AtlasDevSchemaArray::stringList($payload, 'incomplete_dev_actions'),
            recommendedForgeMode: AtlasDevSchemaArray::string($payload, 'recommended_forge_mode'),
            suggestedWorkPackets: $packets,
            definitionOfDone: AtlasDevSchemaArray::stringList($payload, 'definition_of_done'),
            requiredEvidence: AtlasDevSchemaArray::stringList($payload, 'required_evidence'),
            evidenceRefs: $evidenceRefs,
            contextRefs: AtlasDevSchemaArray::stringList($payload, 'context_refs'),
            contextPackHash: AtlasDevSchemaArray::nullableString($payload, 'context_pack_hash'),
            constraints: AtlasDevSchemaArray::stringList($payload, 'constraints'),
            nonGoals: AtlasDevSchemaArray::stringList($payload, 'non_goals'),
            createdAt: AtlasDevSchemaArray::string($payload, 'created_at'),
            packetHash: AtlasDevSchemaArray::string($payload, 'packet_hash'),
        );
    }

    /**
     * Normalise evidence_refs so the canonical 6 slots are always present
     * (extra caller-supplied slots are preserved verbatim).
     *
     * @param  array<string,mixed>  $supplied
     * @return array<string,mixed>
     */
    private function normaliseEvidenceRefs(array $supplied): array
    {
        $normalised = self::emptyEvidenceRefs();
        foreach ($supplied as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }
}
