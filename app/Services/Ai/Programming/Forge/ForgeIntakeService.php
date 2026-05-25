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
        $workspaceGate = $this->workspaceExecutionGateForIntake($workspaceSlug, $prompt, $options);
        $awisContextSelection = $this->awisContextSelection($workspaceGate, $this->expectedFilesFromOptions($options), $riskBand);
        $contextRefs = array_values(array_unique(array_merge(
            $this->arrayOrNull($options['context_refs'] ?? null) ?? [],
            $this->deriveRichInputContextRefs($richInputPayload),
            (array) ($awisContextSelection['context_refs'] ?? []),
        )));
        if (is_array($workspaceGate) && is_array($awisContextSelection['validation_depth_decision'] ?? null)) {
            data_set($workspaceGate, 'execution_context.validation_depth_decision', $awisContextSelection['validation_depth_decision']);
        }

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
            array_slice(array_values(array_diff(array_unique(array_merge(
                $this->scopeRouteSuggestedTests(
                    (array) data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan', []),
                    $this->expectedFilesFromOptions($options),
                ),
                $this->stringList(data_get($intake->workspace_execution_gate, 'execution_context.execution_priority.*.command')),
                $this->stringList(data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.area_ranked_commands')),
                $this->stringList(data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.outcome_ranked_commands')),
                $this->stringList(data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.command_hints')),
            )), array_unique(array_merge(
                $this->stringList(data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.avoid_commands')),
                $this->stringList(data_get($intake->workspace_execution_gate, 'execution_context.context_loading_plan.slow_commands')),
            )))), 0, 12),
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
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->stringOrNull($item),
            $value,
        ))));
    }

    /**
     * @return array<string,mixed>|null
     */
    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    private function workspaceExecutionGateForIntake(?string $workspaceSlug, string $prompt, array $options): ?array
    {
        $override = $options['workspace_execution_gate'] ?? null;
        if (is_array($override)) {
            return $override;
        }

        if ($workspaceSlug === null) {
            return null;
        }

        return ($this->workspaceExecutionGate ?? app(AtlasWorkspaceIntelligenceExecutionGateService::class))->gate(
            workspace: $workspaceSlug,
            mode: 'forge',
            task: $prompt,
        );
    }

    /**
     * @param  array<string,mixed>|null  $workspaceGate
     * @return array<string,mixed>
     */
    private function awisContextSelection(?array $workspaceGate, array $expectedFiles = [], ?string $riskBand = null): array
    {
        $contextLoadingPlan = (array) data_get($workspaceGate, 'execution_context.context_loading_plan', []);
        $refs = [];
        foreach ((array) data_get($workspaceGate, 'execution_context.focused_repositories', []) as $repository) {
            if (! is_array($repository)) {
                continue;
            }
            $repoKey = $this->stringOrNull($repository['repo_key'] ?? null);
            if ($repoKey !== null) {
                $refs[] = 'awis_repo:'.$repoKey;
            }
        }
        foreach ((array) ($contextLoadingPlan['focused_manifest_refs'] ?? []) as $manifestRef) {
            if (! is_array($manifestRef)) {
                continue;
            }
            $repoKey = $this->stringOrNull($manifestRef['repo_key'] ?? null);
            if ($repoKey === null) {
                continue;
            }
            foreach ($this->stringList($manifestRef['manifest_files'] ?? []) as $manifestFile) {
                $refs[] = 'awis_manifest:'.$repoKey.':'.$manifestFile;
            }
        }
        foreach ($this->stringList($contextLoadingPlan['stack_tags'] ?? []) as $stackTag) {
            $refs[] = 'awis_stack:'.$stackTag;
        }
        $inventoryHash = $this->stringOrNull($contextLoadingPlan['repository_inventory_hash'] ?? null);
        if ($inventoryHash !== null) {
            $refs[] = 'awis_cache:repository_inventory:'.$inventoryHash;
        }
        $workspaceWorkingSetHash = $this->stringOrNull($contextLoadingPlan['working_set_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.workspace_working_set_hash'));
        if ($workspaceWorkingSetHash !== null) {
            $refs[] = 'awis_cache:workspace_working_set:'.$workspaceWorkingSetHash;
        }
        $contextDeltaPlanHash = $this->stringOrNull($contextLoadingPlan['context_delta_plan_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.context_delta_plan_hash'));
        if ($contextDeltaPlanHash !== null) {
            $refs[] = 'awis_cache:context_delta_plan:'.$contextDeltaPlanHash;
        }
        $outcomeCommandMemoryHash = $this->stringOrNull($contextLoadingPlan['outcome_command_memory_hash'] ?? null);
        if ($outcomeCommandMemoryHash !== null) {
            $refs[] = 'awis_cache:outcome_command_memory:'.$outcomeCommandMemoryHash;
        }
        $performanceHistogramHash = $this->stringOrNull($contextLoadingPlan['command_performance_histogram_hash'] ?? null);
        if ($performanceHistogramHash !== null) {
            $refs[] = 'awis_cache:command_performance_histogram:'.$performanceHistogramHash;
        }
        $areaPerformanceIndexHash = $this->stringOrNull($contextLoadingPlan['area_performance_index_hash'] ?? null);
        if ($areaPerformanceIndexHash !== null) {
            $refs[] = 'awis_cache:area_performance_index:'.$areaPerformanceIndexHash;
        }
        $stackPerformanceIndexHash = $this->stringOrNull($contextLoadingPlan['stack_performance_index_hash'] ?? null);
        if ($stackPerformanceIndexHash !== null) {
            $refs[] = 'awis_cache:stack_performance_index:'.$stackPerformanceIndexHash;
        }
        $workspaceLearningSnapshotHash = $this->stringOrNull($contextLoadingPlan['learning_snapshot_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.workspace_learning_snapshot_hash'));
        if ($workspaceLearningSnapshotHash !== null) {
            $refs[] = 'awis_cache:workspace_learning_snapshot:'.$workspaceLearningSnapshotHash;
        }
        $executionOptimizationPolicyHash = $this->stringOrNull($contextLoadingPlan['execution_optimization_policy_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.execution_optimization_policy_hash'));
        if ($executionOptimizationPolicyHash !== null) {
            $refs[] = 'awis_cache:execution_optimization_policy:'.$executionOptimizationPolicyHash;
        }
        $executionPolicyEffectivenessIndexHash = $this->stringOrNull($contextLoadingPlan['execution_policy_effectiveness_index_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.execution_policy_effectiveness_index_hash'));
        if ($executionPolicyEffectivenessIndexHash !== null) {
            $refs[] = 'awis_cache:execution_policy_effectiveness:'.$executionPolicyEffectivenessIndexHash;
        }
        $executionRouteEffectivenessIndexHash = $this->stringOrNull($contextLoadingPlan['execution_route_effectiveness_index_hash'] ?? data_get($contextLoadingPlan, 'cache_keys.execution_route_effectiveness_index_hash'));
        if ($executionRouteEffectivenessIndexHash !== null) {
            $refs[] = 'awis_cache:execution_route_effectiveness:'.$executionRouteEffectivenessIndexHash;
        }
        $validationDepthDecision = $this->validationDepthDecision(
            $contextLoadingPlan,
            $expectedFiles,
            $riskBand ?? $this->stringOrNull(data_get($workspaceGate, 'risk_band')),
        );
        $refs = array_values(array_unique(array_merge($refs, $this->validationDepthContextRefs($validationDepthDecision))));

        return [
            'schema_version' => 'atlas.forge.awis_context_selection.v1',
            'source' => 'workspace_execution_gate.context_loading_plan',
            'context_refs' => array_slice(array_values(array_unique(array_merge(
                $refs,
                $this->scopeRouteSelectionRefs($contextLoadingPlan, $expectedFiles),
            ))), 0, 40),
            'suggested_tests' => array_slice(array_values(array_diff(array_unique(array_merge(
                $this->scopeRouteSuggestedTests($contextLoadingPlan, $expectedFiles),
                $this->stringList(data_get($contextLoadingPlan, 'execution_optimization_policy.preferred_commands', [])),
                $this->stringList(data_get($workspaceGate, 'execution_context.execution_priority.*.command')),
                $this->stringList(data_get($contextLoadingPlan, 'execution_optimization_policy.standard_commands', [])),
                $this->stringList($contextLoadingPlan['area_ranked_commands'] ?? []),
                $this->stringList($contextLoadingPlan['outcome_ranked_commands'] ?? []),
                $this->stringList($contextLoadingPlan['command_hints'] ?? []),
            )), array_unique(array_merge(
                $this->stringList($contextLoadingPlan['avoid_commands'] ?? []),
                $this->stringList($contextLoadingPlan['slow_commands'] ?? []),
                $this->stringList(data_get($contextLoadingPlan, 'execution_optimization_policy.blocked_commands', [])),
            )))), 0, 12),
            'repository_inventory_hash' => $inventoryHash,
            'workspace_working_set_hash' => $workspaceWorkingSetHash,
            'context_delta_plan_hash' => $contextDeltaPlanHash,
            'outcome_command_memory_hash' => $outcomeCommandMemoryHash,
            'command_performance_histogram_hash' => $performanceHistogramHash,
            'area_performance_index_hash' => $areaPerformanceIndexHash,
            'stack_performance_index_hash' => $stackPerformanceIndexHash,
            'workspace_learning_snapshot_hash' => $workspaceLearningSnapshotHash,
            'execution_optimization_policy_hash' => $executionOptimizationPolicyHash,
            'execution_policy_effectiveness_index_hash' => $executionPolicyEffectivenessIndexHash,
            'execution_route_effectiveness_index_hash' => $executionRouteEffectivenessIndexHash,
            'validation_depth_decision' => $validationDepthDecision,
            'provider_safe' => data_get($workspaceGate, 'execution_context.provider_safe') === true
                && data_get($workspaceGate, 'execution_context.context_loading_plan.provider_policy.raw_manifest_returned') === false
                && data_get($workspaceGate, 'execution_context.context_loading_plan.provider_policy.script_bodies_returned') === false
                && data_get($workspaceGate, 'execution_context.context_loading_plan.provider_policy.absolute_workspace_path_returned') === false,
            'raw_content_returned' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<int,string>
     */
    private function expectedFilesFromOptions(array $options): array
    {
        return array_values(array_unique(array_merge(
            $this->stringList($options['expected_files'] ?? []),
            $this->expectedFilesFromWorkPackets((array) ($options['work_packets'] ?? [])),
        )));
    }

    /**
     * @param  array<int,mixed>  $workPackets
     * @return array<int,string>
     */
    private function expectedFilesFromWorkPackets(array $workPackets): array
    {
        $files = [];
        foreach ($workPackets as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $files = array_merge($files, $this->stringList($packet['expected_files'] ?? []));
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  array<string,mixed>  $contextLoadingPlan
     * @param  array<int,string>  $expectedFiles
     * @return array<int,string>
     */
    private function scopeRouteSuggestedTests(array $contextLoadingPlan, array $expectedFiles): array
    {
        if ($expectedFiles === []) {
            return [];
        }

        $commands = [];
        foreach ((array) data_get($contextLoadingPlan, 'execution_optimization_policy.scope_routing.area_routes', []) as $route) {
            if (! is_array($route)) {
                continue;
            }
            $key = $this->stringOrNull($route['key'] ?? null);
            if ($key === null || ! $this->filesMatchScope($expectedFiles, $key)) {
                continue;
            }
            $commands = array_values(array_unique(array_merge($commands, $this->stringList($route['preferred_commands'] ?? []))));
        }
        if ($commands !== []) {
            return $commands;
        }

        $stacks = $this->stacksForExpectedFiles($contextLoadingPlan, $expectedFiles);
        foreach ((array) data_get($contextLoadingPlan, 'execution_optimization_policy.scope_routing.stack_routes', []) as $route) {
            if (! is_array($route)) {
                continue;
            }
            $key = $this->stringOrNull($route['key'] ?? null);
            if ($key === null || ! in_array($key, $stacks, true)) {
                continue;
            }
            $commands = array_values(array_unique(array_merge($commands, $this->stringList($route['preferred_commands'] ?? []))));
        }

        return $commands;
    }

    /**
     * @param  array<string,mixed>  $contextLoadingPlan
     * @param  array<int,string>  $expectedFiles
     * @return array<int,string>
     */
    private function scopeRouteSelectionRefs(array $contextLoadingPlan, array $expectedFiles): array
    {
        $selected = $this->matchingScopeRoute($contextLoadingPlan, $expectedFiles);
        if ($selected === null) {
            return [];
        }

        $refs = [];
        foreach ($this->stringList($selected['commands'] ?? []) as $command) {
            $refs[] = 'awis_execution_route_command:'.hash('sha256', $command).':'
                .$selected['kind'].':'.hash('sha256', (string) $selected['key']);
        }

        return $refs;
    }

    /**
     * @param  array<string,mixed>  $contextLoadingPlan
     * @param  array<int,string>  $expectedFiles
     * @return array<string,mixed>|null
     */
    private function matchingScopeRoute(array $contextLoadingPlan, array $expectedFiles): ?array
    {
        if ($expectedFiles === []) {
            return null;
        }

        foreach ((array) data_get($contextLoadingPlan, 'execution_optimization_policy.scope_routing.area_routes', []) as $route) {
            if (! is_array($route)) {
                continue;
            }
            $key = $this->stringOrNull($route['key'] ?? null);
            if ($key !== null && $this->filesMatchScope($expectedFiles, $key)) {
                return [
                    'kind' => 'area',
                    'key' => $key,
                    'route_ref' => $this->stringOrNull($route['route_ref'] ?? null) ?? 'area:'.hash('sha256', $key),
                    'commands' => $this->stringList($route['preferred_commands'] ?? []),
                    'recommended_validation_tier' => $this->stringOrNull($route['recommended_validation_tier'] ?? null),
                    'validation_reason' => $this->stringOrNull($route['validation_reason'] ?? null),
                    'route_mode' => $this->stringOrNull($route['route_mode'] ?? null),
                    'feedback_effectiveness' => $this->stringOrNull($route['feedback_effectiveness'] ?? null),
                ];
            }
        }

        $stacks = $this->stacksForExpectedFiles($contextLoadingPlan, $expectedFiles);
        foreach ((array) data_get($contextLoadingPlan, 'execution_optimization_policy.scope_routing.stack_routes', []) as $route) {
            if (! is_array($route)) {
                continue;
            }
            $key = $this->stringOrNull($route['key'] ?? null);
            if ($key !== null && in_array($key, $stacks, true)) {
                return [
                    'kind' => 'stack',
                    'key' => $key,
                    'route_ref' => $this->stringOrNull($route['route_ref'] ?? null) ?? 'stack:'.hash('sha256', $key),
                    'commands' => $this->stringList($route['preferred_commands'] ?? []),
                    'recommended_validation_tier' => $this->stringOrNull($route['recommended_validation_tier'] ?? null),
                    'validation_reason' => $this->stringOrNull($route['validation_reason'] ?? null),
                    'route_mode' => $this->stringOrNull($route['route_mode'] ?? null),
                    'feedback_effectiveness' => $this->stringOrNull($route['feedback_effectiveness'] ?? null),
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $contextLoadingPlan
     * @param  array<int,string>  $expectedFiles
     * @return array<string,mixed>
     */
    private function validationDepthDecision(array $contextLoadingPlan, array $expectedFiles, ?string $riskBand = null): array
    {
        $route = $this->matchingScopeRoute($contextLoadingPlan, $expectedFiles);
        $tier = $this->stringOrNull(data_get($route, 'recommended_validation_tier'))
            ?? $this->stringOrNull(data_get($contextLoadingPlan, 'execution_optimization_policy.validation_tier_routing.default_tier'))
            ?? 'standard';
        if (! in_array($tier, ['instant', 'standard', 'deep'], true)) {
            $tier = 'standard';
        }

        $reason = $this->stringOrNull(data_get($route, 'validation_reason')) ?? 'default_validation_depth';
        $risk = $riskBand !== null ? strtolower($riskBand) : 'unknown';
        if (in_array($risk, ['high', 'critical'], true) && $tier === 'instant') {
            $tier = 'standard';
            $reason = 'risk_band_upgraded_instant_to_standard';
        }

        return [
            'schema_version' => 'atlas.awis.validation_depth_decision.v1',
            'tier' => $tier,
            'reason' => $reason,
            'risk_band' => $risk,
            'route_kind' => $this->stringOrNull(data_get($route, 'kind')) ?? 'global',
            'route_ref' => $this->stringOrNull(data_get($route, 'route_ref')),
            'route_mode' => $this->stringOrNull(data_get($route, 'route_mode')) ?? 'global_default',
            'feedback_effectiveness' => $this->stringOrNull(data_get($route, 'feedback_effectiveness')) ?? 'unknown',
            'raw_logs_returned' => false,
            'raw_content_returned' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $decision
     * @return array<int,string>
     */
    private function validationDepthContextRefs(array $decision): array
    {
        $tier = $this->stringOrNull($decision['tier'] ?? null);
        if ($tier === null) {
            return [];
        }

        $refs = ['awis_validation_tier:'.$tier];
        $routeRef = $this->stringOrNull($decision['route_ref'] ?? null);
        if ($routeRef !== null) {
            $refs[] = 'awis_validation_route:'.$routeRef;
        }

        return $refs;
    }

    /**
     * @param  array<string,mixed>  $contextLoadingPlan
     * @param  array<int,string>  $expectedFiles
     * @return array<int,string>
     */
    private function stacksForExpectedFiles(array $contextLoadingPlan, array $expectedFiles): array
    {
        $manifestRefs = array_values(array_filter(
            (array) ($contextLoadingPlan['focused_manifest_refs'] ?? []),
            'is_array',
        ));
        $stacks = [];
        foreach ($manifestRefs as $manifestRef) {
            $repoKey = $this->stringOrNull($manifestRef['repo_key'] ?? null);
            if ($repoKey === null) {
                continue;
            }
            if (count($manifestRefs) === 1 || $this->filesMatchScope($expectedFiles, $repoKey)) {
                $stacks = array_values(array_unique(array_merge($stacks, $this->stringList($manifestRef['stack'] ?? []))));
            }
        }

        return array_values(array_unique(array_merge($stacks, $this->stringList($contextLoadingPlan['stack_tags'] ?? []))));
    }

    /**
     * @param  array<int,string>  $files
     */
    private function filesMatchScope(array $files, string $scope): bool
    {
        $scope = trim(str_replace('\\', '/', $scope), '/');
        if ($scope === '') {
            return false;
        }

        foreach ($files as $file) {
            $file = trim(str_replace('\\', '/', $file), '/');
            if ($file === $scope || str_starts_with($file, $scope.'/')) {
                return true;
            }
        }

        return false;
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
