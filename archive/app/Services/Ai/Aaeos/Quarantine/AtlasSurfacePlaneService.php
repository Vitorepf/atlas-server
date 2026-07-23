<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Surface Plane — pure, deterministic entry-plane admission + normalization.
 *
 * The Surface Plane is the plane where humans, apps, CLI, mobile, API and MCP
 * enter Atlas. It exists to RECEIVE interaction without mixing the UI surface
 * with operational decision. This service turns the doc's "Contratos", "Fluxo" and
 * "Regras para IA" into a deterministic decision: given a raw surface
 * interaction, decide whether it may be admitted to the plane, strip any
 * operational decision it tries to smuggle through the UI boundary, and emit
 * the normalized raw event that flows to `surface-adapter`.
 *
 * Contract (from "Contratos" + frontmatter decisions):
 *   Entrada: interacao do usuario ou sistema externo (surface id, channel,
 *            origin, affordances, request payload).
 *   Saida:   evento bruto encaminhado ao adapter (`surface-adapter`).
 *   Invariante: "a surface nao escolhe provider, budget, autonomia ou policy".
 *
 * Documented invariants this code enforces (each is load-bearing and tested):
 *   - "Invariante: a surface nao escolhe provider, budget, autonomia ou policy."
 *       => any of provider / budget / autonomy / policy present on the inbound
 *          interaction is STRIPPED from the forwarded event and recorded as a
 *          boundary violation. The surface never forwards a decision field.
 *   - "Superficies apresentam entrada e saida; elas nao escolhem provider,
 *      politica ou autonomia." (frontmatter decision)
 *       => same closed set of forbidden decision keys is enforced.
 *   - "Adicionar superficies oficiais" / "Inventariar todas as superficies
 *      oficiais" (allowed_changes + next_actions)
 *       => only an official surface is admitted; an unknown surface is REJECTED
 *          (not admitted to the plane), never silently forwarded.
 *   - "O evento segue para `surface-adapter`." (Fluxo + flows_to)
 *       => an admitted event always declares flows_to = surface-adapter, never a
 *          provider, kernel stage or policy engine.
 *   - "A surface preserva origem, canal e affordances." (Fluxo)
 *       => origin, channel and affordances are carried verbatim onto the event.
 *   - "Canais diferentes gerarem semantica divergente para o mesmo pedido."
 *      (Riscos)
 *       => a stable canonical request signature is derived from the intent +
 *          payload ALONE (channel/surface excluded), so the same logical request
 *          on two channels yields the same signature.
 *
 * Non-goals honoured (read-only boundary decision):
 *   - It does NOT route a model/provider, does NOT pick policy/autonomy/budget,
 *     does NOT call the Kernel — it only admits/normalizes a surface interaction
 *     and hands a raw event to the adapter. "Proibido: implementar roteamento de
 *     modelo na camada de UI."
 *
 * @see docs/engineering-knowledge-base/system-graph/surface-plane.md
 */
final class AtlasSurfacePlaneService
{
    /** Stable evidence schema id this plane emits. */
    public const SCHEMA = 'atlas.surface_plane.admission.v1';

    /** The single downstream this plane may flow to (doc: flows_to / Fluxo). */
    public const FLOWS_TO = 'surface-adapter';

    /** Admission decisions (closed set). */
    public const DECISION_ADMIT = 'admit';
    public const DECISION_REJECT = 'reject';

    /** Documented invariant ids each finding maps to. */
    public const INV_NO_OPERATIONAL_DECISION = 'surface_decides_no_operational_field';
    public const INV_OFFICIAL_SURFACE_ONLY = 'official_surface_only';
    public const INV_FLOWS_TO_ADAPTER = 'event_flows_to_adapter_only';

    /**
     * Official surfaces that may enter the plane (doc Resumo + Exemplos, aligned
     * with the kernel SurfaceAdapterRegistry families). Closed set; only these
     * are admitted. Maps a stable plane-level surface id to its canonical kind.
     *
     * @var array<string,string>
     */
    private const OFFICIAL_SURFACES = [
        'atlas_code' => 'code',
        'atlas_cli' => 'cli',
        'atlas_mobile' => 'mobile',
        'atlas_api' => 'api',
        'atlas_mcp' => 'mcp',
        'atlas_app' => 'app',
    ];

    /**
     * Aliases so divergent channel labels resolve to one official surface — this
     * is the antidote to "canais diferentes gerarem semantica divergente".
     *
     * @var array<string,string>
     */
    private const SURFACE_ALIASES = [
        'code' => 'atlas_code',
        'desktop_code' => 'atlas_code',
        'cli' => 'atlas_cli',
        'terminal' => 'atlas_cli',
        'mobile' => 'atlas_mobile',
        'phone' => 'atlas_mobile',
        'ios' => 'atlas_mobile',
        'api' => 'atlas_api',
        'mcp' => 'atlas_mcp',
        'app' => 'atlas_app',
        'desktop_app' => 'atlas_app',
    ];

    /**
     * Operational decision keys a surface is FORBIDDEN to choose. These are
     * stripped from the forwarded event and recorded as violations. Exactly the
     * four named in the invariant: provider, budget, autonomy, policy (+ obvious
     * synonyms so a renamed field cannot smuggle a decision past the boundary).
     *
     * @var array<int,string>
     */
    private const FORBIDDEN_DECISION_KEYS = [
        'provider',
        'model',
        'budget',
        'cost_cap',
        'autonomy',
        'autonomy_level',
        'policy',
        'policy_override',
    ];

    /**
     * Admit (or reject) a single surface interaction and produce the raw event
     * that flows to `surface-adapter`.
     *
     * @param array<string,mixed> $interaction
     * @return array{
     *     schema:string,
     *     decision:string,
     *     surface:string|null,
     *     surface_kind:string|null,
     *     flows_to:string,
     *     event:array<string,mixed>|null,
     *     stripped_decisions:array<int,string>,
     *     violations:array<int,array{invariant:string,detail:string}>,
     *     reject_reason:string|null
     * }
     */
    public function admit(array $interaction): array
    {
        $violations = [];

        $surface = $this->resolveSurface($this->stringField($interaction, 'surface'));
        $channel = $this->stringField($interaction, 'channel');
        $origin = $this->stringField($interaction, 'origin');
        $affordances = $this->affordances($interaction);
        $intent = $this->stringField($interaction, 'intent');

        /** @var array<string,mixed> $payload */
        $payload = isset($interaction['payload']) && is_array($interaction['payload'])
            ? $interaction['payload']
            : [];

        // Invariant: the surface NEVER chooses provider/budget/autonomy/policy.
        // Detect such keys at the top level AND inside the payload, then strip.
        $stripped = $this->detectForbiddenDecisions($interaction, $payload);
        $cleanPayload = $this->stripForbiddenDecisions($payload);

        if ($stripped !== []) {
            $violations[] = [
                'invariant' => self::INV_NO_OPERATIONAL_DECISION,
                'detail' => 'Surface attempted to carry operational decision(s): '
                    . implode(', ', $stripped)
                    . '. Stripped; provider/budget/autonomy/policy belong to the Kernel.',
            ];
        }

        // Invariant: only an official surface may enter the plane.
        if ($surface === null) {
            $violations[] = [
                'invariant' => self::INV_OFFICIAL_SURFACE_ONLY,
                'detail' => 'Unknown or missing surface; not an official Atlas surface.',
            ];

            return [
                'schema' => self::SCHEMA,
                'decision' => self::DECISION_REJECT,
                'surface' => null,
                'surface_kind' => null,
                'flows_to' => self::FLOWS_TO,
                'event' => null,
                'stripped_decisions' => $stripped,
                'violations' => $violations,
                'reject_reason' => 'unofficial_surface',
            ];
        }

        $event = [
            'kind' => 'raw_surface_event',
            'surface' => $surface,
            'surface_kind' => self::OFFICIAL_SURFACES[$surface],
            // Fluxo: the surface preserves origin, channel and affordances.
            'origin' => $origin,
            'channel' => $channel ?? self::OFFICIAL_SURFACES[$surface],
            'affordances' => $affordances,
            'intent' => $intent,
            'payload' => $cleanPayload,
            // Risks antidote: signature excludes channel/surface so the same
            // logical request normalizes identically across channels.
            'request_signature' => $this->requestSignature($intent, $cleanPayload),
            // Fluxo / flows_to: the event only ever goes to the adapter.
            'flows_to' => self::FLOWS_TO,
            'carries_operational_decision' => false,
        ];

        return [
            'schema' => self::SCHEMA,
            'decision' => self::DECISION_ADMIT,
            'surface' => $surface,
            'surface_kind' => self::OFFICIAL_SURFACES[$surface],
            'flows_to' => self::FLOWS_TO,
            'event' => $event,
            'stripped_decisions' => $stripped,
            'violations' => $violations,
            'reject_reason' => null,
        ];
    }

    /**
     * Pure check of the core invariant: does this interaction try to make an
     * operational decision at the surface boundary?
     *
     * @param array<string,mixed> $interaction
     * @return array{respects_invariant:bool,offending_keys:array<int,string>}
     */
    public function respectsBoundary(array $interaction): array
    {
        /** @var array<string,mixed> $payload */
        $payload = isset($interaction['payload']) && is_array($interaction['payload'])
            ? $interaction['payload']
            : [];

        $offending = $this->detectForbiddenDecisions($interaction, $payload);

        return [
            'respects_invariant' => $offending === [],
            'offending_keys' => $offending,
        ];
    }

    /**
     * The canonical inventory of official surfaces and the single downstream the
     * plane forwards to. Mirrors next_actions "Inventariar todas as superficies".
     *
     * @return array{
     *     schema:string,
     *     flows_to:string,
     *     official_surfaces:array<string,string>,
     *     forbidden_decision_keys:array<int,string>
     * }
     */
    public function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'flows_to' => self::FLOWS_TO,
            'official_surfaces' => self::OFFICIAL_SURFACES,
            'forbidden_decision_keys' => self::FORBIDDEN_DECISION_KEYS,
        ];
    }

    /**
     * Resolve a raw surface label to an official surface id, or null if it is
     * not an official Atlas surface.
     */
    private function resolveSurface(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $key = strtolower(trim($raw));
        if ($key === '') {
            return null;
        }

        if (array_key_exists($key, self::OFFICIAL_SURFACES)) {
            return $key;
        }

        if (array_key_exists($key, self::SURFACE_ALIASES)) {
            return self::SURFACE_ALIASES[$key];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $interaction
     * @param array<string,mixed> $payload
     * @return array<int,string> sorted, de-duplicated offending keys
     */
    private function detectForbiddenDecisions(array $interaction, array $payload): array
    {
        $found = [];

        foreach (self::FORBIDDEN_DECISION_KEYS as $key) {
            if ($this->hasMeaningfulValue($interaction, $key) || $this->hasMeaningfulValue($payload, $key)) {
                $found[$key] = true;
            }
        }

        $keys = array_keys($found);
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function stripForbiddenDecisions(array $payload): array
    {
        foreach (self::FORBIDDEN_DECISION_KEYS as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * A value counts as a real decision only if present and non-empty (so an
     * explicit null / empty string is not a smuggled decision).
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
     * Stable signature of the logical request, derived from intent + payload
     * only. Channel and surface are intentionally excluded so the same request
     * on different surfaces yields the same signature.
     *
     * @param array<string,mixed> $payload
     */
    private function requestSignature(?string $intent, array $payload): string
    {
        $normalized = [
            'intent' => $intent !== null ? strtolower(trim($intent)) : '',
            'payload' => $this->normalizeForSignature($payload),
        ];

        return 'sig_' . substr(hash('sha256', (string) json_encode($normalized)), 0, 32);
    }

    /**
     * Recursively sort array keys so payload ordering does not change the
     * signature.
     *
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private function normalizeForSignature(array $value): array
    {
        ksort($value);

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->normalizeForSignature($v);
            }
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $interaction
     * @return array<int,string>
     */
    private function affordances(array $interaction): array
    {
        if (! isset($interaction['affordances']) || ! is_array($interaction['affordances'])) {
            return [];
        }

        $out = [];
        foreach ($interaction['affordances'] as $affordance) {
            if (is_string($affordance) && trim($affordance) !== '') {
                $out[] = trim($affordance);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string,mixed> $interaction
     */
    private function stringField(array $interaction, string $key): ?string
    {
        if (! isset($interaction[$key]) || ! is_string($interaction[$key])) {
            return null;
        }

        $value = trim($interaction[$key]);

        return $value === '' ? null : $value;
    }
}
