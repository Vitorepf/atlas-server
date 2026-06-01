<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Input — pure, deterministic canonical-input formation step.
 *
 * Atlas Input is the system-graph node BETWEEN `surface-adapter` (which hands it
 * a normalized payload) and `operation-envelope` (which it feeds). Its only job
 * is to take the normalized payload and PRESERVE it as a canonical input —
 * content, type, origin, attachments and limits — with enough traceability for
 * decision and evidence, WITHOUT ever deciding the final objective. It is the
 * primary source of the request, not a plan.
 *
 * This service turns the doc's "Contratos", "Fluxo", "Regras para IA",
 * "Escopo de Implementacao", "Riscos" and "Proximas Acoes" into a deterministic
 * decision: given a normalized payload, emit the canonical input that flows to
 * `operation-envelope`, while refusing to do anything the doc forbids the input
 * step from doing.
 *
 * Contract (from "Contratos"):
 *   Entrada: payload normalizado (do `surface-adapter`).
 *   Saida:   input canonico com conteudo, tipo, origem, anexos e limites.
 *   Invariante: "nenhum input pode perder origem".
 *
 * Documented rules this code enforces (each is load-bearing and tested):
 *   - Invariante "nenhum input pode perder origem" (Contratos) + forbidden_changes
 *     "Descartar metadados necessarios para auditoria."
 *       => a payload with no origin is REJECTED. The input step never mints a
 *          canonical input that cannot be traced back to where it came from.
 *   - "Permitido: transformar input em plano sem Intent Routing." is the ONE
 *     forbidden act (Escopo) + "nao assumir objetivo final sem roteamento de
 *     intencao" (Regras para IA).
 *       => if the normalized payload carries a resolved plan / final objective,
 *          that decision is STRIPPED from the canonical input and flagged. The
 *          input is preserved as a request only; the objective is deferred to
 *          Intent Routing. carries_decision is always false.
 *   - Riscos "Entrada grande demais explodir contexto." + Escopo "Permitido:
 *     ... limite ...".
 *       => content length is measured against a documented limit. Over-limit
 *          input is admitted-but-truncated (content preserved up to the limit,
 *          full original size recorded), never silently passed whole to explode
 *          downstream context. The limit is enforced, not advisory.
 *   - Riscos "Anexo perder relacao com a Obra ou thread."
 *       => every attachment is bound to the input_ref (and to the thread/obra
 *          when present). An attachment that cannot be bound is flagged; the
 *          relation is never dropped.
 *   - Proximas Acoes "Documentar schema de `input_ref` usado em receipts e
 *     evidencia." + requires_evidence:true.
 *       => a deterministic `input_ref` is minted for every admitted input so it
 *          can be referenced from receipts/evidence. Same content+origin =>
 *          same ref (pure, reproducible — no randomness, no clock).
 *   - "flows_to: operation-envelope" (front-matter) + Fluxo "prepara o pacote
 *     para o envelope."
 *       => the canonical input always declares flows_to = operation-envelope.
 *
 * Non-goals honoured (preserve, do not plan):
 *   - It does NOT route intent, does NOT choose a plan/objective, does NOT pick a
 *     provider, does NOT execute. It only validates, limits, sanitizes, preserves
 *     and forwards.
 *
 * @see docs/engineering-knowledge-base/system-graph/atlas-input.md
 */
final class AtlasSystemGraphInputService
{
    /** Stable evidence schema id this step emits. */
    public const SCHEMA = 'atlas.input.canonical.v1';

    /** The single downstream the input may flow to (doc: flows_to / Fluxo). */
    public const FLOWS_TO = 'operation-envelope';

    /** Where the input is received from (doc: depends_on / Onde Se Encaixa). */
    public const RECEIVES_FROM = 'surface-adapter';

    /** Admission outcomes (closed set). */
    public const OUTCOME_CANONICAL = 'canonical';
    public const OUTCOME_REJECTED = 'rejected';

    /**
     * Documented limit (Riscos: "Entrada grande demais explodir contexto.").
     * Content longer than this many characters is truncated-but-preserved so it
     * cannot explode downstream context. Chosen as a conservative, deterministic
     * character budget for a single canonical input body.
     */
    public const MAX_CONTENT_CHARS = 200000;

    /** Documented invariant ids each finding maps to. */
    public const INV_ORIGIN_REQUIRED = 'input_origin_required';
    public const INV_NO_PLAN_WITHOUT_ROUTING = 'input_no_plan_without_intent_routing';
    public const INV_CONTENT_LIMIT = 'input_content_limit';
    public const INV_ATTACHMENT_RELATION = 'input_attachment_relation_preserved';

    /** Reject reasons (closed set). */
    public const REJECT_MISSING_ORIGIN = 'missing_origin';

    /**
     * Canonical input types (closed set). Resolved deterministically from what the
     * normalized payload contains — never inferred from content semantics.
     */
    public const TYPE_TEXT = 'text';
    public const TYPE_ATTACHMENT = 'attachment';
    public const TYPE_COMPOSITE = 'composite';
    public const TYPE_EMPTY = 'empty';

    /**
     * Plan / final-objective fields the input step must NOT resolve (Escopo:
     * "Proibido: transformar input em plano sem Intent Routing." + Regras para IA:
     * "nao assumir objetivo final sem roteamento de intencao."). If present on the
     * normalized payload they are stripped from the canonical input and flagged.
     *
     * @var array<int,string>
     */
    private const PLAN_FIELDS = [
        'plan',
        'final_objective',
        'objective',
        'resolved_intent',
        'final_intent',
        'route',
        'decision',
    ];

    /**
     * Form a canonical input from a normalized payload (typically the `normalized`
     * block emitted by the Surface Adapter, but any normalized-shaped array works).
     *
     * @param array<string,mixed> $payload
     * @return array{
     *     schema:string,
     *     outcome:string,
     *     flows_to:string,
     *     input:array<string,mixed>|null,
     *     stripped_plan_fields:array<int,string>,
     *     violations:array<int,array{invariant:string,detail:string}>,
     *     reject_reason:string|null
     * }
     */
    public function formCanonical(array $payload): array
    {
        $violations = [];

        // Invariante: "nenhum input pode perder origem." Without origin the
        // canonical input is untraceable for audit/evidence => reject.
        $origin = $this->stringField($payload, 'origin');
        if ($origin === null) {
            $violations[] = [
                'invariant' => self::INV_ORIGIN_REQUIRED,
                'detail' => 'Normalized payload has no origin; a canonical input '
                    . 'without origin cannot be traced for audit/evidence. Rejected.',
            ];

            return [
                'schema' => self::SCHEMA,
                'outcome' => self::OUTCOME_REJECTED,
                'flows_to' => self::FLOWS_TO,
                'input' => null,
                'stripped_plan_fields' => [],
                'violations' => $violations,
                'reject_reason' => self::REJECT_MISSING_ORIGIN,
            ];
        }

        // Escopo / Regras para IA: the input may not carry a resolved plan or final
        // objective. Strip any such field and flag it; it is deferred to routing.
        $strippedPlan = $this->detectPlanFields($payload);
        if ($strippedPlan !== []) {
            $violations[] = [
                'invariant' => self::INV_NO_PLAN_WITHOUT_ROUTING,
                'detail' => 'Input must not become a plan without Intent Routing. '
                    . 'Stripped: ' . implode(', ', $strippedPlan)
                    . '. The objective is deferred to intent routing.',
            ];
        }

        // Riscos: content limit. Preserve raw content up to the documented budget.
        $rawContent = $this->content($payload);
        $limit = $this->applyContentLimit($rawContent);
        if ($limit['truncated']) {
            $violations[] = [
                'invariant' => self::INV_CONTENT_LIMIT,
                'detail' => 'Content of ' . $limit['original_chars'] . ' chars exceeds '
                    . 'the ' . self::MAX_CONTENT_CHARS . '-char budget; preserved up to '
                    . 'the limit to avoid exploding downstream context.',
            ];
        }

        // Proximas Acoes: mint a deterministic input_ref for receipts/evidence.
        $inputRef = $this->mintInputRef($origin, $limit['content'], $payload);

        // Riscos: bind every attachment to the input_ref + thread/obra relation.
        $attachments = $this->bindAttachments($payload, $inputRef);
        $unbound = $this->unboundAttachmentIndexes($attachments);
        if ($unbound !== []) {
            $violations[] = [
                'invariant' => self::INV_ATTACHMENT_RELATION,
                'detail' => 'Attachment(s) at index ' . implode(', ', $unbound)
                    . ' could not be bound to a thread/obra relation; flagged so the '
                    . 'relation is never silently dropped.',
            ];
        }

        $input = [
            'input_ref' => $inputRef,
            // Contratos: content, tipo, origem, anexos e limites — all preserved.
            'origin' => $origin,
            'type' => $this->resolveType($limit['content'], $attachments),
            'content' => $limit['content'],
            'attachments' => $attachments,
            'limits' => [
                'max_content_chars' => self::MAX_CONTENT_CHARS,
                'original_content_chars' => $limit['original_chars'],
                'preserved_content_chars' => $limit['preserved_chars'],
                'truncated' => $limit['truncated'],
            ],
            // Metadata preserved for audit (forbidden_changes: never discarded),
            // minus any plan/decision field that tried to ride along.
            'metadata' => $this->metadata($payload),
            'thread' => $this->stringField($payload, 'thread'),
            'obra' => $this->stringField($payload, 'obra'),
            // Regras para IA: the input is a request, never a resolved objective.
            'carries_decision' => false,
            // Fluxo / flows_to: prepared for the operation envelope only.
            'flows_to' => self::FLOWS_TO,
        ];

        return [
            'schema' => self::SCHEMA,
            'outcome' => self::OUTCOME_CANONICAL,
            'flows_to' => self::FLOWS_TO,
            'input' => $input,
            'stripped_plan_fields' => $strippedPlan,
            'violations' => $violations,
            'reject_reason' => null,
        ];
    }

    /**
     * Pure predicate of the core invariants: is this normalized payload a clean
     * canonical-input source, i.e. does it have an origin and avoid carrying a
     * resolved plan/objective?
     *
     * @param array<string,mixed> $payload
     * @return array{is_canonical_source:bool,missing_origin:bool,plan_fields:array<int,string>}
     */
    public function isCanonicalSource(array $payload): array
    {
        $missingOrigin = $this->stringField($payload, 'origin') === null;
        $planFields = $this->detectPlanFields($payload);

        return [
            'is_canonical_source' => ! $missingOrigin && $planFields === [],
            'missing_origin' => $missingOrigin,
            'plan_fields' => $planFields,
        ];
    }

    /**
     * The canonical contract of the input step: schema, the single downstream it
     * forwards to, where it receives from, the content limit and the closed sets.
     *
     * @return array{
     *     schema:string,
     *     receives_from:string,
     *     flows_to:string,
     *     max_content_chars:int,
     *     plan_fields:array<int,string>,
     *     types:array<int,string>
     * }
     */
    public function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'receives_from' => self::RECEIVES_FROM,
            'flows_to' => self::FLOWS_TO,
            'max_content_chars' => self::MAX_CONTENT_CHARS,
            'plan_fields' => self::PLAN_FIELDS,
            'types' => [self::TYPE_TEXT, self::TYPE_ATTACHMENT, self::TYPE_COMPOSITE, self::TYPE_EMPTY],
        ];
    }

    /**
     * Resolve the canonical input type deterministically from what is present.
     *
     * @param array<int,array<string,mixed>> $attachments
     */
    private function resolveType(string $content, array $attachments): string
    {
        $hasText = $content !== '';
        $hasAttachments = $attachments !== [];

        if ($hasText && $hasAttachments) {
            return self::TYPE_COMPOSITE;
        }

        if ($hasAttachments) {
            return self::TYPE_ATTACHMENT;
        }

        if ($hasText) {
            return self::TYPE_TEXT;
        }

        return self::TYPE_EMPTY;
    }

    /**
     * Enforce the documented content budget. Returns the preserved content (raw up
     * to the limit) and the measured sizes. Truncation preserves the head of the
     * content (the request body) rather than dropping it whole.
     *
     * @return array{content:string,original_chars:int,preserved_chars:int,truncated:bool}
     */
    private function applyContentLimit(string $content): array
    {
        $originalChars = mb_strlen($content);

        if ($originalChars <= self::MAX_CONTENT_CHARS) {
            return [
                'content' => $content,
                'original_chars' => $originalChars,
                'preserved_chars' => $originalChars,
                'truncated' => false,
            ];
        }

        $preserved = mb_substr($content, 0, self::MAX_CONTENT_CHARS);

        return [
            'content' => $preserved,
            'original_chars' => $originalChars,
            'preserved_chars' => mb_strlen($preserved),
            'truncated' => true,
        ];
    }

    /**
     * Mint a deterministic input_ref (Proximas Acoes). Pure function of origin +
     * preserved content + thread/obra relation — no randomness, no clock — so the
     * same input always references the same ref in receipts/evidence.
     *
     * @param array<string,mixed> $payload
     */
    private function mintInputRef(string $origin, string $content, array $payload): string
    {
        $thread = $this->stringField($payload, 'thread') ?? '';
        $obra = $this->stringField($payload, 'obra') ?? '';
        $digest = hash('sha256', $origin . "\0" . $thread . "\0" . $obra . "\0" . $content);

        return 'input_' . substr($digest, 0, 24);
    }

    /**
     * Bind each attachment to the input_ref and to a thread/obra relation when
     * present (Riscos: an attachment must never lose its relation to the Obra or
     * thread). An attachment whose relation cannot be resolved is marked bound=false.
     *
     * @param array<string,mixed> $payload
     * @return array<int,array{
     *     kind:string,
     *     source:string,
     *     input_ref:string,
     *     thread:string|null,
     *     obra:string|null,
     *     bound:bool,
     *     attachment:array<string,mixed>
     * }>
     */
    private function bindAttachments(array $payload, string $inputRef): array
    {
        $thread = $this->stringField($payload, 'thread');
        $obra = $this->stringField($payload, 'obra');
        $hasRelation = $thread !== null || $obra !== null;

        $out = [];
        foreach ($this->rawAttachments($payload) as $attachment) {
            $out[] = [
                'kind' => is_string($attachment['kind'] ?? null) ? $attachment['kind'] : 'attachment',
                'source' => is_string($attachment['source'] ?? null) ? $attachment['source'] : 'unknown',
                'input_ref' => $inputRef,
                'thread' => $thread,
                'obra' => $obra,
                'bound' => $hasRelation,
                'attachment' => is_array($attachment['attachment'] ?? null)
                    ? $attachment['attachment']
                    : $attachment,
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array{bound:bool}> $attachments
     * @return array<int,int>
     */
    private function unboundAttachmentIndexes(array $attachments): array
    {
        $out = [];
        foreach ($attachments as $i => $attachment) {
            if ($attachment['bound'] === false) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /**
     * Read attachments from the normalized payload. Accepts the adapter's shape
     * (list of {kind,source,attachment}) and a plain list of attachment arrays.
     *
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function rawAttachments(array $payload): array
    {
        $raw = $payload['attachments'] ?? null;
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
     * Carry the request content verbatim from a stable set of content keys. The
     * input step preserves the raw value; it never rewrites or interprets it.
     *
     * @param array<string,mixed> $payload
     */
    private function content(array $payload): string
    {
        foreach (['content', 'text', 'prompt', 'message', 'query', 'input'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Metadata pass-through for audit. Any plan/decision key that leaked into the
     * metadata bag is removed so the canonical input can never smuggle an objective.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function metadata(array $payload): array
    {
        $metadata = isset($payload['metadata']) && is_array($payload['metadata'])
            ? $payload['metadata']
            : [];

        foreach (self::PLAN_FIELDS as $key) {
            unset($metadata[$key]);
        }

        // Surface/format/channel are origin context — keep them for traceability.
        foreach (['surface', 'format', 'channel'] as $key) {
            if (array_key_exists($key, $payload) && ! array_key_exists($key, $metadata)) {
                $metadata[$key] = $payload[$key];
            }
        }

        return $metadata;
    }

    /**
     * Detect any plan/objective field at the top level or inside the metadata bag,
     * with a meaningful (non-empty) value.
     *
     * @param array<string,mixed> $payload
     * @return array<int,string> sorted, de-duplicated offending keys
     */
    private function detectPlanFields(array $payload): array
    {
        $metadata = isset($payload['metadata']) && is_array($payload['metadata'])
            ? $payload['metadata']
            : [];

        $found = [];
        foreach (self::PLAN_FIELDS as $key) {
            if ($this->hasMeaningfulValue($payload, $key) || $this->hasMeaningfulValue($metadata, $key)) {
                $found[$key] = true;
            }
        }

        $list = array_keys($found);
        sort($list);

        return $list;
    }

    /**
     * A value counts as a real field only if present and non-empty (so an explicit
     * null / empty string is not treated as a smuggled objective).
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
