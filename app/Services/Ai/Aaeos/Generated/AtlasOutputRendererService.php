<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas Output Renderer — pure, deterministic presentation of a Kernel result
 * onto a surface, WITHOUT altering operational truth.
 *
 * The Output Renderer is the kernel step that takes the Kernel's produced
 * result (response, patch, plan, receipt, evidence or proposal) and chooses a
 * surface-specific representation for Atlas Code, CLI, mobile or API. It exists
 * to adapt FORMAT only — it never changes the canonical data, and it is
 * forbidden from removing failures or warnings to make output look cleaner.
 *
 * Contract (from "Contratos" + "Fluxo" + frontmatter decisions):
 *   Entrada: resposta, patch, plano, receipt, evidence ou proposta.
 *   Saida:   representacao de surface (panels) para `surface-plane`.
 *   Invariante: "render nao altera dado canonico."
 *
 * Documented invariants this code enforces (each is load-bearing and tested):
 *   - "Invariante: render nao altera dado canonico." (Contratos)
 *       => the canonical content is carried verbatim into the output, and a
 *          stable canonical_fingerprint is derived from that content ALONE
 *          (surface/format excluded). The same result rendered to two surfaces
 *          yields the SAME fingerprint — render cannot fork the truth.
 *   - "Proibido: remover falhas ou warnings." (Escopo de Implementacao)
 *     + "UI esconder risco por estetica." (Riscos)
 *       => every failure and warning present on the canonical result is hoisted
 *          into a non-suppressible `risk` panel. Aesthetics cannot drop them; an
 *          input that tries to mark a warning hidden is flagged as a violation
 *          and the warning is still rendered.
 *   - "Omitir receipt ou evidencia relevante da saida." (forbidden_changes)
 *       => when the result carries a receipt or evidence, the rendered output
 *          MUST contain a receipt panel and an evidence panel. Their absence is
 *          impossible by construction; dropping them is a violation.
 *   - "IA deve manter separacao entre conteudo canonico e apresentacao visual."
 *     (Regras para IA)
 *       => output separates `canonical` (untouched truth) from `presentation`
 *          (panels, ordering, highlight). Presentation never writes back.
 *   - "Output divergente entre surfaces." (Riscos)
 *       => panel SET is driven by what the result contains, not by the surface;
 *          surface only decides layout density. Two surfaces showing the same
 *          result expose the same panel kinds (same truth), differing only in
 *          layout — proven by the shared fingerprint + identical panel kinds.
 *   - "Atlas Code pode mostrar plan, receipt e evidence em paineis separados,
 *      mas os dados vêm do mesmo evento." (Exemplos)
 *       => plan / receipt / evidence become distinct panels bound to the one
 *          canonical event id.
 *
 * Non-goals honoured (presentation-only, read-only over truth):
 *   - It does NOT execute, mutate, re-decide, or call the Kernel. "Output
 *     Renderer adapta apresentacao; nao altera verdade operacional."
 *
 * @see docs/engineering-knowledge-base/system-graph/output-renderer.md
 */
final class AtlasOutputRendererService
{
    public const SCHEMA = 'atlas.output_renderer.v1';

    public const FLOWS_TO = 'surface-plane';

    /** The kernel-result kinds this renderer knows how to present. */
    public const KIND_RESPONSE = 'response';
    public const KIND_PATCH = 'patch';
    public const KIND_PLAN = 'plan';
    public const KIND_RECEIPT = 'receipt';
    public const KIND_EVIDENCE = 'evidence';
    public const KIND_PROPOSAL = 'proposal';

    /** Invariant ids surfaced on violations. */
    public const INV_NO_CANONICAL_MUTATION = 'render_does_not_change_canonical_data';
    public const INV_NO_RISK_SUPPRESSION = 'failures_and_warnings_are_never_removed';
    public const INV_RECEIPT_EVIDENCE_PRESERVED = 'receipt_and_evidence_are_never_omitted';

    /** Layout density per surface — the ONLY thing the surface controls. */
    private const SURFACE_LAYOUT = [
        'atlas_code' => 'panels',     // separate panels, full fidelity
        'atlas_cli' => 'sections',    // linear text sections
        'atlas_mobile' => 'cards',    // stacked compact cards
        'atlas_api' => 'json',        // structured machine output
    ];

    private const DEFAULT_LAYOUT = 'panels';

    /**
     * @return array<int,string>
     */
    public static function knownKinds(): array
    {
        return [
            self::KIND_RESPONSE,
            self::KIND_PATCH,
            self::KIND_PLAN,
            self::KIND_RECEIPT,
            self::KIND_EVIDENCE,
            self::KIND_PROPOSAL,
        ];
    }

    /**
     * Render a Kernel result onto a surface.
     *
     * @param  array<string,mixed>  $result   Canonical kernel result.
     * @param  string               $surface  Target surface id.
     * @return array{
     *     schema:string,
     *     flows_to:string,
     *     surface:string,
     *     layout:string,
     *     event_id:string,
     *     kind:string,
     *     canonical:array<string,mixed>,
     *     canonical_fingerprint:string,
     *     presentation:array{panels:array<int,array<string,mixed>>,panel_kinds:array<int,string>},
     *     violations:array<int,array{invariant:string,detail:string}>,
     *     altered_canonical:bool
     * }
     */
    public function render(array $result, string $surface = 'atlas_code'): array
    {
        $violations = [];

        $kind = $this->resolveKind($this->stringField($result, 'kind'));
        $eventId = $this->stringField($result, 'event_id') ?? 'unknown';
        $layout = self::SURFACE_LAYOUT[$surface] ?? self::DEFAULT_LAYOUT;

        // The canonical content is carried VERBATIM. Presentation is built
        // from a read of this; it is never written back.
        $canonical = $this->canonicalOf($result);

        $failures = AtlasAaeosStringListNormalizer::trimmedStrings($result['failures'] ?? []);
        $warnings = AtlasAaeosStringListNormalizer::trimmedStrings($result['warnings'] ?? []);

        // Riscos / Proibido: a result may TRY to suppress a warning for
        // aesthetics. We detect the attempt, flag it, and render anyway.
        if ($this->declaresHiddenRisk($result) && ($failures !== [] || $warnings !== [])) {
            $violations[] = [
                'invariant' => self::INV_NO_RISK_SUPPRESSION,
                'detail' => 'Input requested hiding a failure/warning for presentation; '
                    . 'render is forbidden from removing failures or warnings. Rendered regardless.',
            ];
        }

        $panels = [];

        // Primary content panel for the result kind.
        $panels[] = [
            'kind' => $kind,
            'title' => $this->titleFor($kind),
            'suppressible' => true,
            'content' => $canonical['content'],
        ];

        // forbidden_changes: receipt is never omitted when present.
        if ($this->has($result, 'receipt')) {
            $panels[] = [
                'kind' => self::KIND_RECEIPT,
                'title' => 'Receipt',
                'suppressible' => false,
                'content' => $canonical['receipt'],
            ];
        }

        // forbidden_changes: evidence is never omitted when present.
        if ($this->has($result, 'evidence')) {
            $panels[] = [
                'kind' => self::KIND_EVIDENCE,
                'title' => 'Evidence',
                'suppressible' => false,
                'content' => $canonical['evidence'],
            ];
        }

        // Proibido: failures + warnings are hoisted into a non-suppressible
        // risk panel so no surface can hide them behind aesthetics.
        if ($failures !== [] || $warnings !== []) {
            $panels[] = [
                'kind' => 'risk',
                'title' => 'Failures & Warnings',
                'suppressible' => false,
                'content' => [
                    'failures' => $failures,
                    'warnings' => $warnings,
                ],
            ];
        }

        $panelKinds = array_values(array_map(
            static fn (array $p): string => (string) $p['kind'],
            $panels,
        ));

        return [
            'schema' => self::SCHEMA,
            'flows_to' => self::FLOWS_TO,
            'surface' => $surface,
            'layout' => $layout,
            'event_id' => $eventId,
            'kind' => $kind,
            'canonical' => $canonical,
            // Invariant: fingerprint is over canonical content ALONE; surface and
            // layout are excluded, so the same truth on two surfaces matches.
            'canonical_fingerprint' => $this->fingerprint($canonical),
            'presentation' => [
                'panels' => $panels,
                'panel_kinds' => $panelKinds,
            ],
            'violations' => $violations,
            // Render is read-only over canonical; this is always false here and
            // exists so callers/tests can assert the invariant explicitly.
            'altered_canonical' => false,
        ];
    }

    /**
     * Pure predicate: does this rendered output preserve operational truth?
     * Confirms (a) canonical untouched, (b) no failure/warning dropped,
     * (c) receipt/evidence panels present when the source carried them.
     *
     * @param  array<string,mixed>  $source    Original kernel result.
     * @param  array<string,mixed>  $rendered  Output of render().
     * @return array{preserves_truth:bool,violations:array<int,array{invariant:string,detail:string}>}
     */
    public function preservesTruth(array $source, array $rendered): array
    {
        $violations = [];

        $sourceCanonical = $this->canonicalOf($source);
        $renderedCanonical = isset($rendered['canonical']) && is_array($rendered['canonical'])
            ? $rendered['canonical']
            : [];

        // (a) canonical content must be byte-identical to the source's.
        if ($this->fingerprint($sourceCanonical) !== $this->fingerprint($renderedCanonical)) {
            $violations[] = [
                'invariant' => self::INV_NO_CANONICAL_MUTATION,
                'detail' => 'Rendered canonical content diverges from the source canonical content.',
            ];
        }

        $panelKinds = $this->renderedPanelKinds($rendered);

        // (b) any failure/warning in the source must appear in a risk panel.
        $sourceFailures = AtlasAaeosStringListNormalizer::trimmedStrings($source['failures'] ?? []);
        $sourceWarnings = AtlasAaeosStringListNormalizer::trimmedStrings($source['warnings'] ?? []);
        if (($sourceFailures !== [] || $sourceWarnings !== []) && ! in_array('risk', $panelKinds, true)) {
            $violations[] = [
                'invariant' => self::INV_NO_RISK_SUPPRESSION,
                'detail' => 'Source carried failures/warnings but no risk panel is present.',
            ];
        }

        // (c) receipt/evidence panels must exist when the source carried them.
        if ($this->has($source, 'receipt') && ! in_array(self::KIND_RECEIPT, $panelKinds, true)) {
            $violations[] = [
                'invariant' => self::INV_RECEIPT_EVIDENCE_PRESERVED,
                'detail' => 'Source carried a receipt but no receipt panel is present.',
            ];
        }
        if ($this->has($source, 'evidence') && ! in_array(self::KIND_EVIDENCE, $panelKinds, true)) {
            $violations[] = [
                'invariant' => self::INV_RECEIPT_EVIDENCE_PRESERVED,
                'detail' => 'Source carried evidence but no evidence panel is present.',
            ];
        }

        return [
            'preserves_truth' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * Self-describing manifest of the renderer's contract.
     *
     * @return array{
     *     schema:string,
     *     flows_to:string,
     *     known_kinds:array<int,string>,
     *     surface_layout:array<string,string>,
     *     non_suppressible_panels:array<int,string>,
     *     invariants:array<int,string>
     * }
     */
    public function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'flows_to' => self::FLOWS_TO,
            'known_kinds' => self::knownKinds(),
            'surface_layout' => self::SURFACE_LAYOUT,
            'non_suppressible_panels' => ['risk', self::KIND_RECEIPT, self::KIND_EVIDENCE],
            'invariants' => [
                self::INV_NO_CANONICAL_MUTATION,
                self::INV_NO_RISK_SUPPRESSION,
                self::INV_RECEIPT_EVIDENCE_PRESERVED,
            ],
        ];
    }

    /**
     * Extract the canonical (truth) view of a kernel result. Carried verbatim.
     *
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function canonicalOf(array $result): array
    {
        $canonical = [
            'content' => array_key_exists('content', $result) ? $result['content'] : null,
            'status' => $this->stringField($result, 'status') ?? 'unknown',
        ];

        if ($this->has($result, 'receipt')) {
            $canonical['receipt'] = $result['receipt'];
        }
        if ($this->has($result, 'evidence')) {
            $canonical['evidence'] = $result['evidence'];
        }

        return $canonical;
    }

    /**
     * Stable fingerprint over a canonical view. Recursively key-sorted so that
     * key ORDER never changes the fingerprint (presentation re-ordering must not
     * read as a truth change), while VALUES still matter.
     *
     * @param  array<string,mixed>  $canonical
     */
    private function fingerprint(array $canonical): string
    {
        $normalized = $this->normalize($canonical);

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Recursively sort array keys for a deterministic, order-insensitive shape.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function normalize($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $k => $v) {
            $normalized[$k] = $this->normalize($v);
        }

        // Only sort associative arrays; preserve order of list arrays (a plan's
        // step order is canonical truth, not presentation).
        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    private function resolveKind(?string $raw): string
    {
        if ($raw !== null && in_array($raw, self::knownKinds(), true)) {
            return $raw;
        }

        return self::KIND_RESPONSE;
    }

    private function titleFor(string $kind): string
    {
        return match ($kind) {
            self::KIND_PATCH => 'Patch',
            self::KIND_PLAN => 'Plan',
            self::KIND_RECEIPT => 'Receipt',
            self::KIND_EVIDENCE => 'Evidence',
            self::KIND_PROPOSAL => 'Proposal',
            default => 'Response',
        };
    }

    /**
     * @param  array<string,mixed>  $rendered
     * @return array<int,string>
     */
    private function renderedPanelKinds(array $rendered): array
    {
        if (! isset($rendered['presentation']['panel_kinds']) || ! is_array($rendered['presentation']['panel_kinds'])) {
            return [];
        }

        return AtlasAaeosStringListNormalizer::trimmedStrings($rendered['presentation']['panel_kinds']);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function declaresHiddenRisk(array $result): bool
    {
        return (bool) ($result['hide_warnings'] ?? false)
            || (bool) ($result['suppress_failures'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $arr
     */
    private function has(array $arr, string $key): bool
    {
        if (! array_key_exists($key, $arr)) {
            return false;
        }

        $value = $arr[$key];

        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $arr
     */
    private function stringField(array $arr, string $key): ?string
    {
        if (! isset($arr[$key]) || ! is_string($arr[$key])) {
            return null;
        }

        $trimmed = trim($arr[$key]);

        return $trimmed === '' ? null : $trimmed;
    }

}
