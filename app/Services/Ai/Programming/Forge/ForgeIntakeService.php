<?php

namespace App\Services\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use Illuminate\Support\Str;

/**
 * Canonical entry point into Atlas Forge.
 *
 * Two entry methods, one contract:
 *
 *  - `intakeFromPrompt(prompt, options)` — operator hands a direct prompt
 *    straight to Forge (no Dev involved). Useful for Obras that nasce Obra.
 *
 *  - `intakeFromEscalationPacket(EscalationPacket, options)` — Dev escalates
 *    via the canonical `atlas.dev_to_forge.escalation_packet.v1` and Forge
 *    materializes its side of the handoff.
 *
 * Both produce a fully-formed `AiForgeIntake` row with:
 *  - 5 canonical milestones (design_context, implementation, verification,
 *    docs, certification) — see {@see ForgeMilestonePlanner}.
 *  - N >= 1 suggested work packets (from supplied list, from escalation
 *    packet hints, or heuristic fallback) — see {@see ForgeWorkPacketComposer}.
 *  - Definition of Done + required evidence baseline (canonical defaults
 *    when caller omits them).
 *  - Deterministic intake_hash for replay/audit.
 *
 * Architectural invariants enforced here:
 *  - Forge does NOT depend on Dev runtime; this service imports the
 *    escalation packet SCHEMA from `AtlasDev/Schemas` (a pure DTO) but no
 *    Dev runtime, orchestrator or pipeline class.
 *  - Long-horizon execution (multi-agent scheduler, provider invocation,
 *    packet completion loop) is OUT OF SCOPE here. Intake produces a
 *    starting state; later layers execute it.
 *
 * Insufficient input lands as a `status=blocked` intake row carrying an
 * explicit `blocker_reason` instead of throwing — auditability beats
 * silence. Genuinely invalid input (empty prompt + no escalation packet)
 * throws {@see ForgeIntakeException::emptyPrompt}.
 */
class ForgeIntakeService
{
    public function __construct(
        private readonly ForgeMilestonePlanner $milestones,
        private readonly ForgeWorkPacketComposer $workPackets,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function intakeFromPrompt(string $prompt, array $options = []): AiForgeIntake
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw ForgeIntakeException::emptyPrompt();
        }

        $intake = $this->persistIntake(
            origin: ForgeIntakeCanon::ORIGIN_DIRECT,
            prompt: $prompt,
            escalationPacket: null,
            options: $options,
        );

        $this->materializeChildren($intake, null, $prompt, $options);

        return $intake;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function intakeFromEscalationPacket(EscalationPacket $packet, array $options = []): AiForgeIntake
    {
        $prompt = trim($packet->originalUserIntent);
        if ($prompt === '') {
            throw ForgeIntakeException::emptyPrompt();
        }

        // Allow callers to inject mission_id / workspace_slug / actor_type
        // while the packet contributes the canonical assessments.
        $options = array_merge([
            'recommended_forge_mode' => $packet->recommendedForgeMode,
            'scope_assessment' => $packet->scopeAssessment,
            'risk_assessment' => $packet->riskAssessment,
            'ambiguity_assessment' => $packet->ambiguityAssessment,
            'definition_of_done' => $packet->definitionOfDone,
            'required_evidence' => $packet->requiredEvidence,
            'evidence_refs' => $packet->evidenceRefs,
            'context_refs' => $packet->contextRefs,
            'context_pack_hash' => $packet->contextPackHash,
            'constraints' => $packet->constraints,
            'non_goals' => $packet->nonGoals,
            'current_dev_findings' => $packet->currentDevFindings,
            'completed_dev_actions' => $packet->completedDevActions,
            'incomplete_dev_actions' => $packet->incompleteDevActions,
            'promotion_reason' => $packet->promotionReason,
            'promotion_triggers' => $packet->promotionTriggers,
            'escalation_packet_id' => $packet->packetId,
            'escalation_packet_hash' => $packet->packetHash,
            'normalized_intent' => $packet->normalizedIntent,
            'actor_type' => 'dev_to_forge_handoff',
        ], $options);

        $intake = $this->persistIntake(
            origin: ForgeIntakeCanon::ORIGIN_ESCALATION_PACKET,
            prompt: $prompt,
            escalationPacket: $packet,
            options: $options,
        );

        $this->materializeChildren($intake, $packet, $prompt, $options);

        return $intake;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function persistIntake(
        string $origin,
        string $prompt,
        ?EscalationPacket $escalationPacket,
        array $options,
    ): AiForgeIntake {
        $recommendedMode = $this->guardRecommendedForgeMode(
            (string) ($options['recommended_forge_mode'] ?? EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE),
        );
        $riskBand = $this->guardRiskBand(
            (string) ($options['risk_band'] ?? $this->inferRiskBand($options)),
        );

        $obraTitle = (string) ($options['obra_title']
            ?? Str::limit($prompt, 180, ''));
        $workspaceSlug = $this->stringOrNull($options['workspace_slug'] ?? null);
        $normalizedIntent = $this->stringOrNull($options['normalized_intent'] ?? null)
            ?? $this->normalizeIntent($prompt);

        $definitionOfDone = $this->ensureList(
            $options['definition_of_done'] ?? null,
            ForgeIntakeCanon::defaultDefinitionOfDone(),
        );
        $requiredEvidence = $this->ensureList(
            $options['required_evidence'] ?? null,
            ForgeIntakeCanon::defaultRequiredEvidence(),
        );

        $blockerReason = $this->detectIntakeBlocker($prompt, $escalationPacket, $options);
        $status = $blockerReason === null
            ? ForgeIntakeCanon::STATUS_READY
            : ForgeIntakeCanon::STATUS_BLOCKED;

        $uuid = (string) Str::uuid();

        $hashPayload = [
            'schema' => ForgeIntakeCanon::INTAKE_SCHEMA_VERSION,
            'uuid' => $uuid,
            'origin' => $origin,
            'recommended_forge_mode' => $recommendedMode,
            'obra_title' => $obraTitle,
            'workspace_slug' => $workspaceSlug,
            'original_user_intent' => $prompt,
            'normalized_intent' => $normalizedIntent,
            'scope_assessment' => $this->stringOrNull($options['scope_assessment'] ?? null),
            'risk_assessment' => $this->stringOrNull($options['risk_assessment'] ?? null),
            'ambiguity_assessment' => $this->stringOrNull($options['ambiguity_assessment'] ?? null),
            'risk_band' => $riskBand,
            'mission_id' => $this->stringOrNull($options['mission_id'] ?? null),
            'escalation_packet_id' => $this->stringOrNull($options['escalation_packet_id'] ?? null),
            'escalation_packet_hash' => $this->stringOrNull($options['escalation_packet_hash'] ?? null),
            'promotion_reason' => $this->stringOrNull($options['promotion_reason'] ?? null),
            'promotion_triggers' => $this->arrayOrNull($options['promotion_triggers'] ?? null),
            'definition_of_done' => $definitionOfDone,
            'required_evidence' => $requiredEvidence,
            'evidence_refs' => $this->arrayOrNull($options['evidence_refs'] ?? null),
            'context_refs' => $this->arrayOrNull($options['context_refs'] ?? null),
            'context_pack_hash' => $this->stringOrNull($options['context_pack_hash'] ?? null),
            'constraints' => $this->arrayOrNull($options['constraints'] ?? null),
            'non_goals' => $this->arrayOrNull($options['non_goals'] ?? null),
            'sdd_spec' => $this->normalizeSddSpec($options['sdd_spec'] ?? null),
            'current_dev_findings' => $this->arrayOrNull($options['current_dev_findings'] ?? null),
            'completed_dev_actions' => $this->arrayOrNull($options['completed_dev_actions'] ?? null),
            'incomplete_dev_actions' => $this->arrayOrNull($options['incomplete_dev_actions'] ?? null),
            'status' => $status,
            'blocker_reason' => $blockerReason,
            'actor_type' => (string) ($options['actor_type'] ?? 'operator'),
        ];
        $intakeHash = MissionCanonicalHash::sha256($hashPayload);

        return AiForgeIntake::query()->create(array_merge($hashPayload, [
            'schema_version' => ForgeIntakeCanon::INTAKE_SCHEMA_VERSION,
            'intake_hash' => $intakeHash,
        ]));
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function materializeChildren(
        AiForgeIntake $intake,
        ?EscalationPacket $escalationPacket,
        string $promptForFallback,
        array $options,
    ): void {
        // Always plan canonical milestones — even for blocked intakes — so
        // the audit trail shows the 5 expected gates and downstream services
        // can surface "milestone X blocked".
        $this->milestones->planForIntake($intake);

        if ($intake->status === ForgeIntakeCanon::STATUS_BLOCKED) {
            // Blocked intake intentionally produces ZERO work packets so the
            // executor cannot pick up the Obra by accident. Audit clean.
            return;
        }

        $supplied = (array) ($options['work_packets'] ?? []);
        $this->workPackets->composeForIntake(
            $intake,
            $escalationPacket,
            $supplied,
            $promptForFallback,
            $intake->risk_band,
        );
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function detectIntakeBlocker(string $prompt, ?EscalationPacket $packet, array $options): ?string
    {
        // Caller explicit blocker override (e.g. policy gate already failed).
        if (isset($options['blocker_reason']) && is_string($options['blocker_reason']) && trim($options['blocker_reason']) !== '') {
            return trim($options['blocker_reason']);
        }

        // Direct intake: minimum prompt signal threshold.
        if ($packet === null && mb_strlen($prompt) < 12) {
            return 'insufficient_prompt_signal:prompt_too_short';
        }

        // Direct intake with no real verbs/structure -> insufficient.
        if ($packet === null) {
            $hasAction = preg_match('/\b(implementar|implement|criar|create|refator|refactor|build|migrar|migrate|reescrever|rewrite|adicionar|add|gerar|generate|integrar|integrate|fix|corrigir|deploy|publicar|publish)\b/iu', $prompt);
            $hasObjectMarker = preg_match('/\b(obra|epic|epico|system|sistema|module|modulo|service|serviço|servico|migration|pipeline|architecture|arquitetura)\b/iu', $prompt);
            if (! $hasAction && ! $hasObjectMarker) {
                return 'insufficient_prompt_signal:no_action_or_object_markers';
            }
        }

        // Escalation packet path: rely on the packet's invariants; only block
        // when caller explicitly forces it via options.
        return null;
    }

    private function inferRiskBand(array $options): string
    {
        // Heuristic: caller-supplied risk_assessment containing high/critical
        // keywords bumps the band; otherwise medium.
        $riskAssessment = strtolower((string) ($options['risk_assessment'] ?? ''));
        if (str_contains($riskAssessment, 'critical') || str_contains($riskAssessment, 'critico')) {
            return ForgeIntakeCanon::RISK_BAND_CRITICAL;
        }
        if (str_contains($riskAssessment, 'high') || str_contains($riskAssessment, 'alto')) {
            return ForgeIntakeCanon::RISK_BAND_HIGH;
        }
        if (str_contains($riskAssessment, 'low') || str_contains($riskAssessment, 'baixo')) {
            return ForgeIntakeCanon::RISK_BAND_LOW;
        }

        return ForgeIntakeCanon::RISK_BAND_MEDIUM;
    }

    private function guardRecommendedForgeMode(string $mode): string
    {
        if (! in_array($mode, ForgeIntakeCanon::ALLOWED_RECOMMENDED_FORGE_MODES, true)) {
            throw ForgeIntakeException::invalidRecommendedForgeMode($mode);
        }

        return $mode;
    }

    private function guardRiskBand(string $band): string
    {
        if (! in_array($band, ForgeIntakeCanon::RISK_BANDS, true)) {
            throw ForgeIntakeException::invalidRiskBand($band);
        }

        return $band;
    }

    /**
     * @param  mixed  $value
     * @param  array<int,mixed>  $default
     * @return array<int,mixed>
     */
    private function ensureList($value, array $default): array
    {
        if (! is_array($value) || $value === []) {
            return array_values($default);
        }

        return array_values($value);
    }

    /**
     * @return array<mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return (array) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Persist the SDD spec as a canonical map: untyped scalars become null,
     * lists are coerced through `array_values` for deterministic hashing, and
     * the schema_version tag is enforced when the caller forgot it. Callers
     * that omit `sdd_spec` end up with null on the row — the spec gate marks
     * a missing spec explicitly instead of papering over it.
     *
     * @return array<string,mixed>|null
     */
    private function normalizeSddSpec(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $normalized = ['schema_version' => ForgeIntakeCanon::SDD_SPEC_SCHEMA_VERSION];
        foreach (ForgeIntakeCanon::SDD_REQUIRED_SECTIONS as $section) {
            if (! array_key_exists($section, $value)) {
                $normalized[$section] = in_array($section, ForgeIntakeCanon::SDD_LIST_SECTIONS, true)
                    ? []
                    : null;

                continue;
            }
            $raw = $value[$section];
            if (in_array($section, ForgeIntakeCanon::SDD_LIST_SECTIONS, true)) {
                $normalized[$section] = is_array($raw) ? array_values($raw) : [];

                continue;
            }
            $normalized[$section] = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
        }

        return $normalized;
    }

    private function normalizeIntent(string $prompt): string
    {
        $lower = mb_strtolower(trim($prompt));
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if ($normalized === false) {
            $normalized = $lower;
        }

        return preg_replace('/\s+/u', ' ', $normalized) ?? $lower;
    }
}
