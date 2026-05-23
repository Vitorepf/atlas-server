<?php

namespace App\Services\Ai\Programming\Forge;

use App\Models\AiForgeIntake;
use App\Services\Ai\ContextIntelligence\AtlasContextOperationsRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\PersistentContext\AtlasPersistentContextRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

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
        private readonly ?AtlasContextOperationsRuntimeService $contextOperations = null,
        private readonly ?AtlasPersistentContextRuntimeService $persistentContext = null,
        private readonly ?AtlasWorkspaceIntelligenceExecutionGateService $workspaceExecutionGate = null,
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
        $workspaceGate = $this->workspaceExecutionGateForIntake($workspaceSlug, $prompt);

        $blockerReason = $this->detectIntakeBlocker($prompt, $escalationPacket, $options, $workspaceGate);
        $status = $blockerReason === null
            ? ForgeIntakeCanon::STATUS_READY
            : ForgeIntakeCanon::STATUS_BLOCKED;

        $uuid = (string) Str::uuid();
        $contextOperations = $this->contextOperationsForIntake(
            uuid: $uuid,
            origin: $origin,
            prompt: $prompt,
            normalizedIntent: $normalizedIntent,
            recommendedMode: $recommendedMode,
            riskBand: $riskBand,
            options: $options,
            contextRefs: $contextRefs,
        );
        $persistentContext = $this->persistentContextForIntake(
            uuid: $uuid,
            origin: $origin,
            prompt: $prompt,
            normalizedIntent: $normalizedIntent,
            recommendedMode: $recommendedMode,
            riskBand: $riskBand,
            options: $options,
            contextRefs: $contextRefs,
            contextOperations: $contextOperations,
        );

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
            'context_operations_hash' => $contextOperations['operations_runtime_hash'] ?? null,
            'persistent_context_hash' => $persistentContext['persistent_context_hash'] ?? null,
            'workspace_execution_gate' => $workspaceGate,
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

        $createPayload = array_merge($hashPayload, [
            'schema_version' => ForgeIntakeCanon::INTAKE_SCHEMA_VERSION,
            'context_operations' => $contextOperations,
            'intake_hash' => $intakeHash,
        ]);
        if (Schema::hasColumn('ai_forge_intakes', 'persistent_context')) {
            $createPayload['persistent_context'] = $persistentContext;
        } else {
            unset($createPayload['persistent_context_hash']);
        }
        if (! Schema::hasColumn('ai_forge_intakes', 'workspace_execution_gate')) {
            unset($createPayload['workspace_execution_gate']);
        }

        return AiForgeIntake::query()->create($createPayload);
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  list<string>  $contextRefs
     * @return array<string,mixed>
     */
    private function contextOperationsForIntake(
        string $uuid,
        string $origin,
        string $prompt,
        ?string $normalizedIntent,
        string $recommendedMode,
        string $riskBand,
        array $options,
        array $contextRefs,
    ): array {
        $explicitEvidenceRefs = array_values(array_filter((array) ($options['evidence_refs'] ?? []), 'is_string'));
        $evidenceRefs = array_values(array_unique(array_merge($explicitEvidenceRefs, [
            'forge_intake:'.$uuid,
        ])));

        return ($this->contextOperations ?? app(AtlasContextOperationsRuntimeService::class))->evaluate([
            'prompt' => $prompt,
            'domain' => 'programming',
            'flow_id' => 'atlas_forge',
            'flow_profile' => 'programming.forge',
            'runtime_mode' => 'forge',
            'scope_id' => $uuid,
            'context_refs' => $contextRefs,
            'evidence_refs' => $evidenceRefs,
            'handoff_target' => [
                'kind' => 'atlas_forge',
                'reason' => $origin === ForgeIntakeCanon::ORIGIN_ESCALATION_PACKET
                    ? 'dev_to_forge_escalation_packet'
                    : 'direct_forge_intake',
            ],
            'policy_required' => true,
            'evidence_required' => true,
            'tool_plan_required' => true,
            'force_verified_compaction' => true,
            'must_keep_items' => [
                ['id' => 'forge_intake_uuid', 'kind' => 'decision', 'digest' => $uuid],
                ['id' => 'recommended_forge_mode', 'kind' => 'runtime_decision', 'digest' => $recommendedMode],
                ['id' => 'risk_band', 'kind' => 'risk', 'digest' => $riskBand],
                ['id' => 'normalized_intent', 'kind' => 'intent', 'digest' => $normalizedIntent ?? MissionCanonicalHash::sha256(['prompt' => $prompt])],
            ],
            'turns' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  list<string>  $contextRefs
     * @param  array<string,mixed>  $contextOperations
     * @return array<string,mixed>
     */
    private function persistentContextForIntake(
        string $uuid,
        string $origin,
        string $prompt,
        ?string $normalizedIntent,
        string $recommendedMode,
        string $riskBand,
        array $options,
        array $contextRefs,
        array $contextOperations,
    ): array {
        try {
            return ($this->persistentContext ?? app(AtlasPersistentContextRuntimeService::class))->build([
                'prompt' => $prompt,
                'workspace' => (string) ($options['workspace'] ?? $options['workspace_slug'] ?? base_path()),
                'surface_id' => 'atlas_forge_intake',
                'domain' => 'programming',
                'flow_id' => 'atlas_forge',
                'flow_profile' => 'programming.forge',
                'runtime_mode' => 'forge',
                'provider' => $this->stringOrNull($options['provider'] ?? null),
                'scope_type' => 'forge_intake',
                'scope_id' => $uuid,
                'payload' => [
                    'workspace' => $options['workspace'] ?? $options['workspace_slug'] ?? base_path(),
                    'context_refs' => $contextRefs,
                    'routing_domain' => 'programming',
                    'routing_task' => 'atlas_forge',
                    'origin' => $origin,
                    'recommended_forge_mode' => $recommendedMode,
                    'risk_band' => $riskBand,
                    'normalized_intent' => $normalizedIntent,
                    'context_operations_hash' => $contextOperations['operations_runtime_hash'] ?? null,
                    'rich_input_payload' => $options['rich_input_payload'] ?? $options['rich_input'] ?? null,
                ],
                'evidence_refs' => array_values(array_unique(array_merge(
                    array_values(array_filter((array) ($options['evidence_refs'] ?? []), 'is_string')),
                    ['forge_intake:'.$uuid],
                ))),
                'must_keep_items' => [
                    ['id' => 'forge_intake_uuid', 'kind' => 'decision', 'value' => $uuid],
                    ['id' => 'recommended_forge_mode', 'kind' => 'runtime_decision', 'value' => $recommendedMode],
                    ['id' => 'risk_band', 'kind' => 'risk', 'value' => $riskBand],
                    ['id' => 'normalized_intent', 'kind' => 'intent', 'value' => $normalizedIntent ?? MissionCanonicalHash::sha256(['prompt' => $prompt])],
                ],
            ]);
        } catch (Throwable $exception) {
            return [
                'schema_version' => AtlasPersistentContextRuntimeService::SCHEMA_VERSION,
                'status' => AtlasPersistentContextRuntimeService::STATUS_DEGRADED,
                'error' => 'persistent_context_threw',
                'exception_class' => $exception::class,
                'writes' => false,
            ];
        }
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
    private function detectIntakeBlocker(string $prompt, ?EscalationPacket $packet, array $options, ?array $workspaceGate): ?string
    {
        // Caller explicit blocker override (e.g. policy gate already failed).
        if (isset($options['blocker_reason']) && is_string($options['blocker_reason']) && trim($options['blocker_reason']) !== '') {
            return trim($options['blocker_reason']);
        }

        if ($workspaceGate === null) {
            return 'awis_workspace_required_for_forge_intake';
        }

        if (($workspaceGate['allowed'] ?? false) !== true) {
            $reasons = array_values(array_filter((array) ($workspaceGate['blockers'] ?? []), 'is_string'));
            $suffix = $reasons === [] ? 'unknown' : implode(',', $reasons);

            return 'awis_execution_gate_blocked:'.$suffix;
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

    /**
     * @return array<string,mixed>|null
     */
    private function workspaceExecutionGateForIntake(?string $workspaceSlug, string $prompt): ?array
    {
        if ($workspaceSlug === null) {
            return null;
        }

        return ($this->workspaceExecutionGate ?? app(AtlasWorkspaceIntelligenceExecutionGateService::class))->gate(
            workspace: $workspaceSlug,
            mode: 'forge',
            task: $prompt,
        );
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
