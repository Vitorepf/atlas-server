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
        $richInputPayload = $this->normalizeRichInputPayload($options['rich_input_payload'] ?? $options['rich_input'] ?? null);
        $contextRefs = array_values(array_unique(array_merge(
            $this->arrayOrNull($options['context_refs'] ?? null) ?? [],
            $this->deriveRichInputContextRefs($richInputPayload),
        )));

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
            'context_refs' => $contextRefs !== [] ? $contextRefs : null,
            'context_pack_hash' => $this->stringOrNull($options['context_pack_hash'] ?? null),
            'rich_input_payload' => $richInputPayload !== [] ? $richInputPayload : null,
            'rich_input_schema_version' => $richInputPayload['schema_version'] ?? null,
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

    /**
     * @return array<string,mixed>
     */
    private function normalizeRichInputPayload(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $uploadedImages = array_values(array_filter(
            (array) ($value['uploaded_images'] ?? $value['uploaded_image_ids'] ?? []),
            static fn ($id): bool => is_string($id) && $id !== '',
        ));
        $uploadedDocuments = array_values(array_filter(
            (array) ($value['uploaded_documents'] ?? $value['uploaded_document_ids'] ?? []),
            static fn ($id): bool => is_string($id) && $id !== '',
        ));

        $urlAttachments = [];
        foreach ((array) ($value['url_attachments'] ?? []) as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['url'] ?? null) || $attachment['url'] === '') {
                continue;
            }

            $urlAttachments[] = array_filter([
                'url' => $attachment['url'],
                'kind' => is_string($attachment['kind'] ?? null) ? $attachment['kind'] : 'url',
                'title' => is_string($attachment['title'] ?? null) ? $attachment['title'] : null,
                'author' => is_string($attachment['author'] ?? null) ? $attachment['author'] : null,
                'duration_sec' => is_numeric($attachment['duration_sec'] ?? null) ? (int) $attachment['duration_sec'] : null,
                'thumbnail_url' => is_string($attachment['thumbnail_url'] ?? null) ? $attachment['thumbnail_url'] : null,
                'ref_id' => is_string($attachment['ref_id'] ?? null) ? $attachment['ref_id'] : null,
                'content_hash' => hash('sha256', $attachment['url']),
            ], static fn ($field): bool => $field !== null && $field !== '');
        }

        $textBlocks = [];
        foreach ((array) ($value['text_blocks'] ?? []) as $block) {
            if (! is_array($block) || ! is_string($block['content'] ?? null) || $block['content'] === '') {
                continue;
            }

            $textBlocks[] = array_filter([
                'file_name' => is_string($block['file_name'] ?? null) ? $block['file_name'] : null,
                'mime_type' => is_string($block['mime_type'] ?? null) ? $block['mime_type'] : null,
                'language' => is_string($block['language'] ?? null) ? $block['language'] : null,
                'page_count' => is_numeric($block['page_count'] ?? null) ? (int) $block['page_count'] : null,
                'content_hash' => hash('sha256', $block['content']),
            ], static fn ($field): bool => $field !== null && $field !== '');
        }

        $sourceManifest = [];
        foreach ((array) ($value['source_manifest'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $kind = is_string($entry['kind'] ?? null) ? $entry['kind'] : null;
            $fileName = is_string($entry['file_name'] ?? null) ? $entry['file_name'] : null;
            if ($kind === null && $fileName === null) {
                continue;
            }

            $sourceManifest[] = array_filter([
                'id' => is_string($entry['id'] ?? null) ? $entry['id'] : null,
                'kind' => $kind,
                'file_name' => $fileName,
                'mime_type' => is_string($entry['mime_type'] ?? null) ? $entry['mime_type'] : null,
                'size' => is_numeric($entry['size'] ?? null) ? (int) $entry['size'] : null,
                'uploaded_id' => is_string($entry['uploaded_id'] ?? null) ? $entry['uploaded_id'] : null,
                'source_hash' => is_string($entry['source_hash'] ?? null) ? $entry['source_hash'] : null,
                'source' => is_string($entry['source'] ?? null) ? $entry['source'] : null,
                'manifest_hash' => hash('sha256', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            ], static fn ($field): bool => $field !== null && $field !== '');
        }

        return array_filter([
            'schema_version' => is_string($value['schema_version'] ?? null)
                ? $value['schema_version']
                : 'atlas.rich_input.payload.v1',
            'uploaded_image_ids' => $uploadedImages !== [] ? $uploadedImages : null,
            'uploaded_document_ids' => $uploadedDocuments !== [] ? $uploadedDocuments : null,
            'url_attachments' => $urlAttachments !== [] ? $urlAttachments : null,
            'text_blocks' => $textBlocks !== [] ? $textBlocks : null,
            'source_manifest' => $sourceManifest !== [] ? $sourceManifest : null,
        ], static fn ($field): bool => $field !== null);
    }

    /**
     * @param  array<string,mixed>  $richInputPayload
     * @return list<string>
     */
    private function deriveRichInputContextRefs(array $richInputPayload): array
    {
        $refs = [];

        foreach ((array) ($richInputPayload['uploaded_image_ids'] ?? []) as $id) {
            if (is_string($id) && $id !== '') {
                $refs[] = 'image_asset:'.$id;
            }
        }

        foreach ((array) ($richInputPayload['uploaded_document_ids'] ?? []) as $id) {
            if (is_string($id) && $id !== '') {
                $refs[] = 'document_asset:'.$id;
            }
        }

        foreach ((array) ($richInputPayload['url_attachments'] ?? []) as $attachment) {
            if (is_array($attachment) && is_string($attachment['content_hash'] ?? null)) {
                $refs[] = 'url_attachment:'.$attachment['content_hash'];
            }
        }

        foreach ((array) ($richInputPayload['text_blocks'] ?? []) as $block) {
            if (is_array($block) && is_string($block['content_hash'] ?? null)) {
                $refs[] = 'text_block:'.$block['content_hash'];
            }
        }

        foreach ((array) ($richInputPayload['source_manifest'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $hash = $entry['source_hash'] ?? $entry['manifest_hash'] ?? null;
            $kind = is_string($entry['kind'] ?? null) && $entry['kind'] !== '' ? $entry['kind'] : 'source';
            if (is_string($hash) && $hash !== '') {
                $refs[] = 'source_manifest:'.$kind.':'.$hash;
            }
        }

        return array_values(array_unique($refs));
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
