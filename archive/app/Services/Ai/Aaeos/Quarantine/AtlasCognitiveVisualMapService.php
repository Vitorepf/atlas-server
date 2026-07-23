<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cognitive Plane Visual Map governance validator.
 *
 * Pure, deterministic enforcement of the canonical visual spec used to draw the
 * "Arquitetura do Cognitive Development Plane do Atlas AI" image, so a human and
 * an AI read the same architecture before implementing an AP, capability,
 * surface, memory artifact or Curator flow. Given a PROPOSED diagram
 * (its declared elements, pillars, movements, pipeline-stage count and the
 * title/subtitle/footer text) it checks the proposal against the documented
 * contract and returns a structured verdict — never touching a provider, the
 * filesystem or the database.
 *
 * Contract (from the doc body):
 *   - "Regras Visuais Obrigatorias" #2: exactly the 4 pillars must appear
 *     (teorico, pratico, cognitivo, transferencial).
 *   - #3: exactly the 5 movements in order (declarar, gerar-erro, praticar,
 *     provar, revisar).
 *   - #5: the canonical pipeline overlay has 17 stages (must be drawn as a
 *     17-stage overlay, not a different number).
 *   - #7: Evidence Ledger, Read Models, Learning Signals, Curator and Human
 *     Review must all be present.
 *   - #8: learning must NOT auto-alter critical behaviour without
 *     proposal/review — the diagram must mark the proposal/review gate.
 *   - #9: Multiplier Edge must be shown.
 *   - "Texto De Referencia": title / subtitle / footer must match the
 *     canonical strings.
 *   - "Conflito" precedence: principles.md > pipeline-overlay.md > AP-### >
 *     this doc > image (image always loses).
 *   - "Nao Fazer": 5 forbidden depictions (isolated study app, passive summary
 *     instead of evidence/mastery, hidden Human Review on active curriculum
 *     change, external/guru/raw source as canonical, removing APs/status/refs).
 *
 * @see docs/engineering-knowledge-base/cognitive/visual-map.md
 */
final class AtlasCognitiveVisualMapService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const SCHEMA = 'atlas.aaeos.cognitive_visual_map.v1';

    /** Doc rule #2 — the 4 canonical pillars (order-independent set). */
    public const PILLARS = [
        'teorico',
        'pratico',
        'cognitivo',
        'transferencial',
    ];

    /** Doc rule #3 — the 5 movements, in the documented sequence. */
    public const MOVEMENTS = [
        'declarar',
        'gerar-erro',
        'praticar',
        'provar',
        'revisar',
    ];

    /** Doc rule #5 — the canonical pipeline overlay length. */
    public const PIPELINE_STAGES = 17;

    /**
     * Doc rule #7 — governance elements that MUST be present on the map.
     *
     * @var list<string>
     */
    public const REQUIRED_ELEMENTS = [
        'evidence-ledger',
        'read-models',
        'learning-signals',
        'curator',
        'human-review',
        'multiplier-edge',
    ];

    /**
     * "Nao Fazer" — depictions that must never appear. Keyed by id with the
     * doc rationale, so a verdict can name exactly which prohibition fired.
     *
     * @var array<string,string>
     */
    public const FORBIDDEN_DEPICTIONS = [
        'isolated-study-app' => 'Cognitive Plane desenhado como app de estudo isolado.',
        'passive-summary' => 'evidence/mastery trocado por resumo passivo.',
        'hidden-human-review' => 'Human Review escondido em mudanca de curriculo ativo.',
        'external-canonical-source' => 'guru, IA externa ou conteudo bruto como fonte canonica.',
        'removed-aps-or-refs' => 'APs, status ou docs de referencia removidos do visual.',
    ];

    /**
     * "Conflito" precedence — authority order; index 0 wins, the image always
     * loses. Used by resolveConflict().
     *
     * @var list<string>
     */
    public const CONFLICT_ORDER = [
        'principles',
        'pipeline-overlay',
        'ap',
        'this-doc',
        'image',
    ];

    /** Canonical title ("Texto De Referencia"). */
    public const CANONICAL_TITLE = 'Arquitetura do Cognitive Development Plane do Atlas AI';

    /** Canonical footer ("Rodape obrigatorio"). */
    public const CANONICAL_FOOTER = 'Surface nao decide · Provider nao decide · Tool nao decide · Domain nao burla policy · Tudo repetido vira Core';

    /**
     * Validate a proposed cognitive-plane diagram against the canonical spec.
     *
     * @param array{
     *     pillars?: list<string>,
     *     movements?: list<string>,
     *     pipeline_stages?: int,
     *     elements?: list<string>,
     *     forbidden?: list<string>,
     *     learning_gated?: bool,
     *     title?: string,
     *     footer?: string
     * } $proposal
     *
     * @return array<string,mixed> structured verdict + audit receipt
     */
    public function validateDiagram(array $proposal): array
    {
        $violations = [];

        // Rule #2 — pillars must be exactly the 4 canonical ones.
        $pillars = $this->normalize($proposal['pillars'] ?? []);
        $missingPillars = array_values(array_diff(self::PILLARS, $pillars));
        $extraPillars = array_values(array_diff($pillars, self::PILLARS));
        foreach ($missingPillars as $p) {
            $violations[] = ['rule' => 'missing_pillar', 'element' => $p, 'doc_rule' => 2];
        }
        foreach ($extraPillars as $p) {
            $violations[] = ['rule' => 'unknown_pillar', 'element' => $p, 'doc_rule' => 2];
        }

        // Rule #3 — movements must be exactly the 5, in order.
        $movements = $this->normalize($proposal['movements'] ?? []);
        if ($movements !== self::MOVEMENTS) {
            $violations[] = [
                'rule' => 'movements_mismatch',
                'expected' => self::MOVEMENTS,
                'found' => $movements,
                'doc_rule' => 3,
            ];
        }

        // Rule #5 — pipeline overlay must be the canonical 17 stages.
        $stages = (int) ($proposal['pipeline_stages'] ?? 0);
        if ($stages !== self::PIPELINE_STAGES) {
            $violations[] = [
                'rule' => 'pipeline_stage_count',
                'expected' => self::PIPELINE_STAGES,
                'found' => $stages,
                'doc_rule' => 5,
            ];
        }

        // Rule #7 — required governance elements must all be present.
        $elements = $this->normalize($proposal['elements'] ?? []);
        $missingElements = array_values(array_diff(self::REQUIRED_ELEMENTS, $elements));
        foreach ($missingElements as $el) {
            $violations[] = ['rule' => 'missing_element', 'element' => $el, 'doc_rule' => 7];
        }

        // Rule #8 — learning must be gated by proposal/review.
        if (($proposal['learning_gated'] ?? false) !== true) {
            $violations[] = ['rule' => 'learning_not_gated', 'doc_rule' => 8];
        }

        // "Nao Fazer" — any forbidden depiction is a hard violation.
        foreach ($this->normalize($proposal['forbidden'] ?? []) as $bad) {
            if (isset(self::FORBIDDEN_DEPICTIONS[$bad])) {
                $violations[] = [
                    'rule' => 'forbidden_depiction',
                    'element' => $bad,
                    'reason' => self::FORBIDDEN_DEPICTIONS[$bad],
                ];
            } else {
                $violations[] = ['rule' => 'forbidden_depiction', 'element' => $bad, 'reason' => 'unspecified'];
            }
        }

        // "Texto De Referencia" — title/footer must match (when supplied).
        if (array_key_exists('title', $proposal) && trim((string) $proposal['title']) !== self::CANONICAL_TITLE) {
            $violations[] = ['rule' => 'title_mismatch', 'expected' => self::CANONICAL_TITLE];
        }
        if (array_key_exists('footer', $proposal) && trim((string) $proposal['footer']) !== self::CANONICAL_FOOTER) {
            $violations[] = ['rule' => 'footer_mismatch', 'expected' => self::CANONICAL_FOOTER];
        }

        $valid = $violations === [];

        return [
            'schema' => self::SCHEMA,
            'check' => 'cognitive_plane_diagram',
            'valid' => $valid,
            'pillar_count' => count($pillars),
            'movement_count' => count($movements),
            'pipeline_stages' => $stages,
            'missing_pillars' => $missingPillars,
            'missing_elements' => $missingElements,
            'violations' => $violations,
            'auditable' => true,
        ];
    }

    /**
     * Resolve which source wins when two cognitive sources disagree
     * ("Conflito" precedence). Lower index == higher authority; the image
     * always loses.
     *
     * @return array<string,mixed>
     */
    public function resolveConflict(string $a, string $b): array
    {
        $ia = $this->authorityIndex($a);
        $ib = $this->authorityIndex($b);

        if ($ia === null || $ib === null) {
            return [
                'schema' => self::SCHEMA,
                'check' => 'conflict',
                'resolvable' => false,
                'winner' => null,
                'reason' => 'unknown_source',
            ];
        }

        $winnerIdx = min($ia, $ib);

        return [
            'schema' => self::SCHEMA,
            'check' => 'conflict',
            'resolvable' => true,
            'winner' => self::CONFLICT_ORDER[$winnerIdx],
            'loser' => self::CONFLICT_ORDER[max($ia, $ib)],
            'image_never_wins' => self::CONFLICT_ORDER[$winnerIdx] !== 'image',
        ];
    }

    /**
     * Authority rank of a source in the conflict order (lower wins).
     */
    public function authorityIndex(string $source): ?int
    {
        $id = $this->slug($source);
        // Normalize a couple of natural aliases.
        $id = match ($id) {
            'principles-md', 'cognitive-principles' => 'principles',
            'pipeline', 'overlay' => 'pipeline-overlay',
            'doc', 'visual-map' => 'this-doc',
            'png', 'asset' => 'image',
            default => $id,
        };
        $idx = array_search($id, self::CONFLICT_ORDER, true);

        return $idx === false ? null : $idx;
    }

    /**
     * The canonical reference texts as a single structured block.
     *
     * @return array{title:string,subtitle:string,footer:string}
     */
    public function canonicalTexts(): array
    {
        return [
            'title' => self::CANONICAL_TITLE,
            'subtitle' => 'Estado final enterprise: trajetoria cognitiva governada — Pareto, 4 pilares, '
                .'erro preditivo calibrado, evidencia, mastery e multiplier edge',
            'footer' => self::CANONICAL_FOOTER,
        ];
    }

    /**
     * @param list<string> $items
     *
     * @return list<string>
     */
    private function normalize(array $items): array
    {
        return array_values(array_filter(
            array_map(fn ($v): string => $this->slug((string) $v), $items),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[\s_]+/', '-', $value) ?? $value;

        return preg_replace('/[^a-z0-9\-]/', '', $value) ?? $value;
    }
}
