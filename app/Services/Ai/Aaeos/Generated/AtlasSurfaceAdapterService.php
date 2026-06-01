<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Surface Adapter — pure, deterministic input/output normalization step.
 *
 * The Surface Adapter sits between `surface-plane` (which admits a raw surface
 * event) and `atlas-input` (which interprets the request). Its only job is to
 * translate the many surface formats — UI textarea, CLI command, mobile, API,
 * MCP, code paste, file upload, voice — into ONE common normalized payload, so
 * the Kernel never needs to know which channel the request came from.
 *
 * This service turns the doc's "Contratos", "Fluxo", "Regras para IA" and
 * "Escopo de Implementacao" into a deterministic decision: given a raw surface
 * event, emit the normalized payload that flows to `atlas-input`, while refusing
 * to do anything the doc forbids the adapter from doing.
 *
 * Contract (from "Contratos"):
 *   Entrada: evento de surface (raw surface event from surface-plane).
 *   Saida:   payload normalizado com origem, formato, anexos e metadados.
 *   Invariante: "sem execucao, sem provider e sem policy".
 *
 * Documented rules this code enforces (each is load-bearing and tested):
 *   - "Invariante: sem execucao, sem provider e sem policy." (Contratos)
 *     + forbidden_changes "Executar ferramentas ou escolher modelo no adapter."
 *     + "Proibido: inferir plano ou executar ferramenta." (Escopo)
 *       => any execution / provider / model / policy / plan field present on the
 *          raw event is STRIPPED from the normalized payload and recorded as a
 *          boundary violation. The adapter never forwards a decision field.
 *   - "preserva contexto de origem" (Fluxo) + Riscos "Perder origem do input e
 *      quebrar auditoria."
 *       => origin is mandatory. A raw event with no origin is REJECTED (it would
 *          break the audit trail), never normalized with a guessed origin.
 *   - "Encaminha o pacote para `atlas-input`." (Fluxo + flows_to)
 *       => a normalized payload always declares flows_to = atlas-input, never a
 *          provider, kernel decision stage or policy engine.
 *   - "IA deve adicionar adapters como tradutores deterministas. Se houver
 *      ambiguidade semantica, ela deve ir para etapas posteriores." (Regras p/ IA)
 *       => normalization is a pure translation: format, attachments and metadata
 *          only. The adapter NEVER resolves semantic ambiguity (intent/plan); it
 *          records ambiguity untouched for later stages.
 *   - "Permitido: normalizacao de tipos, anexos e metadados." (Escopo)
 *       => attachments are classified into a closed, deterministic set of kinds
 *          (text, image, audio, file) by source key alone — no inference.
 *   - Riscos "Adapter enriquecer contexto demais e criar decisao invisivel."
 *       => any key the adapter would ADD beyond the normalized schema that looks
 *          like a decision is rejected as over-enrichment; the normalized payload
 *          carries only origin/format/attachments/metadata/content.
 *
 * Non-goals honoured (read-only translation boundary):
 *   - It does NOT execute a tool, does NOT pick a provider/model, does NOT choose
 *     policy, does NOT infer a plan/intent. It only normalizes and forwards.
 *
 * @see docs/engineering-knowledge-base/system-graph/surface-adapter.md
 */
final class AtlasSurfaceAdapterService
{
    /** Stable evidence schema id this adapter emits. */
    public const SCHEMA = 'atlas.surface_adapter.normalized.v1';

    /** The single downstream the adapter may flow to (doc: flows_to / Fluxo). */
    public const FLOWS_TO = 'atlas-input';

    /** Normalization outcomes (closed set). */
    public const OUTCOME_NORMALIZED = 'normalized';
    public const OUTCOME_REJECTED = 'rejected';

    /** Documented invariant ids each finding maps to. */
    public const INV_NO_EXECUTION_PROVIDER_POLICY = 'adapter_no_execution_provider_policy';
    public const INV_NO_PLAN_INFERENCE = 'adapter_no_plan_inference';
    public const INV_ORIGIN_REQUIRED_FOR_AUDIT = 'adapter_origin_required_for_audit';
    public const INV_DETERMINISTIC_TRANSLATION = 'adapter_deterministic_translation';

    /** Reject reasons (closed set). */
    public const REJECT_MISSING_ORIGIN = 'missing_origin';

    /**
     * Closed, deterministic set of attachment kinds the adapter may classify
     * into (doc Escopo: "normalizacao de tipos, anexos e metadados"). The source
     * key on the raw event maps to exactly one kind — no content inference.
     *
     * @var array<string,string>
     */
    private const ATTACHMENT_KIND_BY_SOURCE = [
        'images' => 'image',
        'image_attachments' => 'image',
        'audio' => 'audio',
        'audio_attachments' => 'audio',
        'voice_audio' => 'audio',
        'files' => 'file',
        'file_attachments' => 'file',
        'attachments' => 'attachment',
    ];

    /**
     * Fields the adapter is FORBIDDEN to carry forward — they would mean the
     * adapter executed, chose a provider/model, or applied policy. Stripped from
     * the normalized payload and recorded as violations. Exactly the things the
     * Contratos invariant + forbidden_changes name: execution, provider, policy
     * (+ obvious synonyms so a renamed field cannot smuggle a decision through).
     *
     * @var array<int,string>
     */
    private const FORBIDDEN_FIELDS = [
        'execute',
        'execution',
        'tool_call',
        'tool_calls',
        'provider',
        'model',
        'policy',
        'policy_override',
    ];

    /**
     * Plan / intent-decision fields the adapter must NOT resolve. They are
     * "ambiguidade semantica" that the doc says belongs to later stages. The
     * adapter neither strips nor acts on them inside content; if such a key is
     * presented as an ADAPTER-LEVEL decision it is rejected as over-enrichment.
     *
     * @var array<int,string>
     */
    private const PLAN_DECISION_FIELDS = [
        'plan',
        'resolved_intent',
        'final_intent',
        'route',
        'autonomy',
        'autonomy_level',
        'budget',
    ];

    /**
     * Normalize a single raw surface event into the common payload that flows to
     * `atlas-input`.
     *
     * @param array<string,mixed> $event
     * @return array{
     *     schema:string,
     *     outcome:string,
     *     flows_to:string,
     *     normalized:array<string,mixed>|null,
     *     stripped_fields:array<int,string>,
     *     violations:array<int,array{invariant:string,detail:string}>,
     *     reject_reason:string|null
     * }
     */
    public function normalize(array $event): array
    {
        $violations = [];

        // Risk antidote: "Perder origem do input e quebrar auditoria."
        // Origin is mandatory; without it the audit trail is broken => reject.
        $origin = $this->stringField($event, 'origin');
        if ($origin === null) {
            $violations[] = [
                'invariant' => self::INV_ORIGIN_REQUIRED_FOR_AUDIT,
                'detail' => 'Raw surface event has no origin; normalizing it would '
                    . 'break the audit trail. The adapter refuses to guess origin.',
            ];

            return [
                'schema' => self::SCHEMA,
                'outcome' => self::OUTCOME_REJECTED,
                'flows_to' => self::FLOWS_TO,
                'normalized' => null,
                'stripped_fields' => [],
                'violations' => $violations,
                'reject_reason' => self::REJECT_MISSING_ORIGIN,
            ];
        }

        // Invariant: "sem execucao, sem provider e sem policy" — detect + strip.
        $stripped = $this->detectForbiddenFields($event);
        if ($stripped !== []) {
            $violations[] = [
                'invariant' => self::INV_NO_EXECUTION_PROVIDER_POLICY,
                'detail' => 'Adapter is forbidden to carry execution/provider/policy. '
                    . 'Stripped: ' . implode(', ', $stripped)
                    . '. These belong to the Kernel, not the adapter.',
            ];
        }

        // Regras para IA: the adapter is a deterministic translator and must not
        // resolve a plan/intent. If a plan-decision field is presented at the
        // adapter level it is over-enrichment that creates an invisible decision.
        $planFields = $this->detectPlanDecisionFields($event);
        if ($planFields !== []) {
            $violations[] = [
                'invariant' => self::INV_NO_PLAN_INFERENCE,
                'detail' => 'Adapter must not infer a plan/intent ('
                    . implode(', ', $planFields)
                    . '). Semantic ambiguity is deferred to atlas-input. Dropped.',
            ];
        }

        $surface = $this->stringField($event, 'surface') ?? 'unknown';

        $normalized = [
            // Fluxo: origin context is preserved verbatim for audit.
            'origin' => $origin,
            'surface' => $surface,
            // Escopo: normalize the format (text / attachment / mixed).
            'format' => $this->resolveFormat($event),
            // Content carried verbatim; the adapter does not interpret it.
            'content' => $this->content($event),
            // Escopo: deterministic classification of attachments by source key.
            'attachments' => $this->normalizeAttachments($event),
            // Escopo: metadata pass-through (already free of forbidden fields).
            'metadata' => $this->metadata($event),
            // Regras para IA: any semantic ambiguity is recorded, never resolved.
            'unresolved' => array_values(array_unique([...$planFields])),
            // Fluxo / flows_to: the normalized payload only ever goes to the input.
            'flows_to' => self::FLOWS_TO,
            // The adapter never executes, routes a provider, or applies policy.
            'carries_decision' => false,
        ];

        return [
            'schema' => self::SCHEMA,
            'outcome' => self::OUTCOME_NORMALIZED,
            'flows_to' => self::FLOWS_TO,
            'normalized' => $normalized,
            'stripped_fields' => $stripped,
            'violations' => $violations,
            'reject_reason' => null,
        ];
    }

    /**
     * Pure predicate of the core invariant: is this raw event a clean translation
     * input, i.e. does it avoid carrying execution/provider/policy and avoid
     * presenting an adapter-level plan decision?
     *
     * @param array<string,mixed> $event
     * @return array{respects_invariant:bool,offending_fields:array<int,string>}
     */
    public function respectsBoundary(array $event): array
    {
        $offending = array_values(array_unique([
            ...$this->detectForbiddenFields($event),
            ...$this->detectPlanDecisionFields($event),
        ]));
        sort($offending);

        return [
            'respects_invariant' => $offending === [],
            'offending_fields' => $offending,
        ];
    }

    /**
     * The canonical contract of the adapter: the normalized schema it emits, the
     * single downstream it forwards to, and the closed sets it enforces.
     *
     * @return array{
     *     schema:string,
     *     flows_to:string,
     *     forbidden_fields:array<int,string>,
     *     plan_decision_fields:array<int,string>,
     *     attachment_kinds:array<int,string>
     * }
     */
    public function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'flows_to' => self::FLOWS_TO,
            'forbidden_fields' => self::FORBIDDEN_FIELDS,
            'plan_decision_fields' => self::PLAN_DECISION_FIELDS,
            'attachment_kinds' => array_values(array_unique(array_values(self::ATTACHMENT_KIND_BY_SOURCE))),
        ];
    }

    /**
     * Resolve the normalized format deterministically: text-only, attachment-only
     * or mixed. Pure function of which fields are present — no inference.
     *
     * @param array<string,mixed> $event
     */
    private function resolveFormat(array $event): string
    {
        $hasText = $this->content($event) !== '';
        $hasAttachments = $this->normalizeAttachments($event) !== [];

        if ($hasText && $hasAttachments) {
            return 'mixed';
        }

        if ($hasAttachments) {
            return 'attachment';
        }

        return 'text';
    }

    /**
     * Carry the request content verbatim. The adapter reads from a stable set of
     * content keys but never rewrites or interprets the value.
     *
     * @param array<string,mixed> $event
     */
    private function content(array $event): string
    {
        foreach (['content', 'text', 'primary_text', 'prompt', 'message', 'query', 'input'] as $key) {
            $value = $this->stringField($event, $key);
            if ($value !== null) {
                return $value;
            }
        }

        // The payload from surface-plane may hold the text one level down.
        if (isset($event['payload']) && is_array($event['payload'])) {
            foreach (['content', 'text', 'prompt', 'message', 'query', 'input'] as $key) {
                $value = $this->stringField($event['payload'], $key);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Classify attachments into the closed kind set by their source key alone.
     * Each attachment is tagged with its kind and source; content is preserved.
     *
     * @param array<string,mixed> $event
     * @return array<int,array{kind:string,source:string,attachment:array<string,mixed>}>
     */
    private function normalizeAttachments(array $event): array
    {
        $out = [];

        foreach (self::ATTACHMENT_KIND_BY_SOURCE as $sourceKey => $kind) {
            $list = $this->attachmentList($event, $sourceKey);

            foreach ($list as $attachment) {
                $out[] = [
                    'kind' => $kind,
                    'source' => $sourceKey,
                    'attachment' => $attachment,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $event
     * @return array<int,array<string,mixed>>
     */
    private function attachmentList(array $event, string $key): array
    {
        $raw = $event[$key] ?? null;

        if (! is_array($raw) && isset($event['payload']) && is_array($event['payload'])) {
            $raw = $event['payload'][$key] ?? null;
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Metadata pass-through. Any forbidden/plan keys that leaked into the metadata
     * bag are removed here too, so the normalized metadata can never become an
     * invisible decision (Riscos: "enriquecer contexto demais").
     *
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function metadata(array $event): array
    {
        $metadata = isset($event['metadata']) && is_array($event['metadata'])
            ? $event['metadata']
            : [];

        foreach ([...self::FORBIDDEN_FIELDS, ...self::PLAN_DECISION_FIELDS] as $key) {
            unset($metadata[$key]);
        }

        // Channel and affordances are origin context — keep them visible.
        foreach (['channel', 'affordances', 'intent'] as $key) {
            if (array_key_exists($key, $event) && ! array_key_exists($key, $metadata)) {
                $metadata[$key] = $event[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param array<string,mixed> $event
     * @return array<int,string> sorted, de-duplicated offending keys
     */
    private function detectForbiddenFields(array $event): array
    {
        return $this->detectFields($event, self::FORBIDDEN_FIELDS);
    }

    /**
     * @param array<string,mixed> $event
     * @return array<int,string> sorted, de-duplicated offending keys
     */
    private function detectPlanDecisionFields(array $event): array
    {
        return $this->detectFields($event, self::PLAN_DECISION_FIELDS);
    }

    /**
     * Detect any of $keys at the top level OR inside payload/metadata bags, with a
     * meaningful (non-empty) value.
     *
     * @param array<string,mixed> $event
     * @param array<int,string> $keys
     * @return array<int,string>
     */
    private function detectFields(array $event, array $keys): array
    {
        $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
        $metadata = isset($event['metadata']) && is_array($event['metadata']) ? $event['metadata'] : [];

        $found = [];
        foreach ($keys as $key) {
            if ($this->hasMeaningfulValue($event, $key)
                || $this->hasMeaningfulValue($payload, $key)
                || $this->hasMeaningfulValue($metadata, $key)
            ) {
                $found[$key] = true;
            }
        }

        $list = array_keys($found);
        sort($list);

        return $list;
    }

    /**
     * A value counts as a real field only if present and non-empty (so an explicit
     * null / empty string is not treated as a smuggled decision).
     *
     * @param array<string,mixed> $bag
     */
    private function hasMeaningfulValue(array $bag, string $key): bool
    {
        if (! array_key_exists($key, $bag)) {
            return false;
        }

        $value = $bag[$key];

        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * @param array<string,mixed> $bag
     */
    private function stringField(array $bag, string $key): ?string
    {
        if (! isset($bag[$key]) || ! is_string($bag[$key])) {
            return null;
        }

        $value = trim($bag[$key]);

        return $value === '' ? null : $value;
    }
}
