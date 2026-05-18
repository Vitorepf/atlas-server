<?php

namespace App\Services\Ai\Programming\Forge;

use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;

/**
 * Canonical enums and defaults for the Atlas Forge Intake layer:
 *
 *  - `atlas.forge.intake.v1`       — the intake record itself
 *  - `atlas.forge.work_packet.v1`  — suggested work packets emitted at intake
 *  - `atlas.forge.milestone.v1`    — 5 canonical milestones every Obra carries
 *
 * Intake is the canonical entry point into Atlas Forge. It accepts EITHER a
 * direct operator prompt (`origin=direct`) OR an
 * `atlas.dev_to_forge.escalation_packet.v1` (`origin=escalation_packet`).
 *
 * Forge does NOT depend on Atlas Dev: the direct intake path produces a
 * complete Obra envelope (work packets + milestones + DoD + evidence
 * requirements) without ever touching any Dev runtime. The escalation path
 * consumes the canonical packet and reuses its hints when they are present.
 *
 * Long-horizon execution (multi-agent scheduler, packet execution loop,
 * provider invocation) is intentionally OUT OF SCOPE for this intake layer.
 * The intake exists so that whatever executor comes next can pick up a
 * canonical, auditable starting state instead of a free-form prompt.
 */
final class ForgeIntakeCanon
{
    public const INTAKE_SCHEMA_VERSION = 'atlas.forge.intake.v1';

    public const WORK_PACKET_SCHEMA_VERSION = 'atlas.forge.work_packet.v1';

    public const MILESTONE_SCHEMA_VERSION = 'atlas.forge.milestone.v1';

    public const SDD_SPEC_SCHEMA_VERSION = 'atlas.forge.sdd_spec.v1';

    public const QA_GATE_RUN_SCHEMA_VERSION = 'atlas.forge.qa_gate_run.v1';

    public const OBRA_CERTIFICATION_SCHEMA_VERSION = 'atlas.forge.obra_certification.v1';

    public const ORIGIN_DIRECT = 'direct';

    public const ORIGIN_ESCALATION_PACKET = 'escalation_packet';

    /** @var array<int,string> */
    public const ALLOWED_ORIGINS = [
        self::ORIGIN_DIRECT,
        self::ORIGIN_ESCALATION_PACKET,
    ];

    /**
     * Forge intake modes reuse the canonical 4-mode taxonomy declared in
     * `atlas.dev_to_forge.escalation_packet.v1` so the dual-core handoff is
     * symmetric whether or not Dev is the source.
     *
     * @var array<int,string>
     */
    public const ALLOWED_RECOMMENDED_FORGE_MODES = EscalationPacket::ALLOWED_RECOMMENDED_FORGE_MODES;

    public const RISK_BAND_LOW = 'low';

    public const RISK_BAND_MEDIUM = 'medium';

    public const RISK_BAND_HIGH = 'high';

    public const RISK_BAND_CRITICAL = 'critical';

    /** @var array<int,string> */
    public const RISK_BANDS = [
        self::RISK_BAND_LOW,
        self::RISK_BAND_MEDIUM,
        self::RISK_BAND_HIGH,
        self::RISK_BAND_CRITICAL,
    ];

    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_RECEIVED = 'received';

    /** @var array<int,string> */
    public const INTAKE_STATUSES = [
        self::STATUS_READY,
        self::STATUS_BLOCKED,
        self::STATUS_RECEIVED,
    ];

    public const PACKET_STATUS_PROPOSED = 'proposed';

    public const PACKET_STATUS_READY = 'ready';

    public const PACKET_STATUS_CLAIMED = 'claimed';

    public const PACKET_STATUS_DONE = 'done';

    public const PACKET_STATUS_BLOCKED = 'blocked';

    /** @var array<int,string> */
    public const PACKET_STATUSES = [
        self::PACKET_STATUS_PROPOSED,
        self::PACKET_STATUS_READY,
        self::PACKET_STATUS_CLAIMED,
        self::PACKET_STATUS_DONE,
        self::PACKET_STATUS_BLOCKED,
    ];

    public const MILESTONE_DESIGN_CONTEXT = 'design_context';

    public const MILESTONE_IMPLEMENTATION = 'implementation';

    public const MILESTONE_VERIFICATION = 'verification';

    public const MILESTONE_DOCS = 'docs';

    public const MILESTONE_CERTIFICATION = 'certification';

    /** @var array<int,string> */
    public const CANONICAL_MILESTONES = [
        self::MILESTONE_DESIGN_CONTEXT,
        self::MILESTONE_IMPLEMENTATION,
        self::MILESTONE_VERIFICATION,
        self::MILESTONE_DOCS,
        self::MILESTONE_CERTIFICATION,
    ];

    /**
     * Minimum DoD criteria every Forge intake carries when the caller does
     * not provide their own. Aligned with the dual-core escalation packet
     * canonical evidence slots so the two paths produce symmetric obras.
     *
     * @return array<int,string>
     */
    public static function defaultDefinitionOfDone(): array
    {
        return [
            'all_canonical_milestones_completed',
            'every_work_packet_has_acceptance_evidence',
            'verification_receipt_attached',
            'certification_passed',
        ];
    }

    /**
     * Minimum evidence requirements per intake. Mirrors the escalation packet
     * 6-slot evidence map plus Forge-specific gates.
     *
     * @return array<int,string>
     */
    public static function defaultRequiredEvidence(): array
    {
        return [
            'plan',
            'context_pack',
            'work_packet_receipts',
            'verification_receipt',
            'evidence_pack',
            'certification',
        ];
    }

    /**
     * Canonical sections every Atlas Forge SDD spec must declare for a heavy
     * Obra. The SDD spec is persisted on `ai_forge_intakes.sdd_spec` as the
     * `atlas.forge.sdd_spec.v1` payload. Order matches the contract doc.
     *
     * @var array<int,string>
     */
    public const SDD_REQUIRED_SECTIONS = [
        'problem_statement',
        'scope',
        'non_goals',
        'constraints',
        'architecture_notes',
        'acceptance_criteria',
        'verification_plan',
        'risks',
        'required_evidence',
    ];

    /** @var array<int,string> List-shaped SDD sections (each must be a non-empty list). */
    public const SDD_LIST_SECTIONS = [
        'non_goals',
        'constraints',
        'architecture_notes',
        'acceptance_criteria',
        'verification_plan',
        'risks',
        'required_evidence',
    ];

    /** @var array<int,string> String-shaped SDD sections (each must be a non-empty string). */
    public const SDD_STRING_SECTIONS = [
        'problem_statement',
        'scope',
    ];

    /**
     * Empty skeleton useful for callers that want to materialize a draft.
     *
     * @return array<string,mixed>
     */
    public static function emptySddSpec(): array
    {
        return [
            'schema_version' => self::SDD_SPEC_SCHEMA_VERSION,
            'problem_statement' => null,
            'scope' => null,
            'non_goals' => [],
            'constraints' => [],
            'architecture_notes' => [],
            'acceptance_criteria' => [],
            'verification_plan' => [],
            'risks' => [],
            'required_evidence' => [],
        ];
    }

    /**
     * Heavy Obras carry hard SDD/QA enforcement. The taxonomy is intentionally
     * narrow so the boundary between "light" and "heavy" stays auditable:
     *
     *  - any intake originated from a Dev → Forge escalation packet, OR
     *  - risk_band declared as high|critical, OR
     *  - recommended_forge_mode in [sdd_intake|architecture_review|long_run].
     */
    public static function isHeavyObra(string $origin, string $riskBand, string $recommendedForgeMode): bool
    {
        if ($origin === self::ORIGIN_ESCALATION_PACKET) {
            return true;
        }
        if (in_array($riskBand, [self::RISK_BAND_HIGH, self::RISK_BAND_CRITICAL], true)) {
            return true;
        }

        return in_array($recommendedForgeMode, [
            EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
            EscalationPacket::RECOMMENDED_FORGE_MODE_ARCHITECTURE_REVIEW,
            EscalationPacket::RECOMMENDED_FORGE_MODE_LONG_RUN,
        ], true);
    }
}
