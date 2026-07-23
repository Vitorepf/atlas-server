<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use InvalidArgumentException;

/**
 * Atlas AI Canonical Layer Status — runtime.
 *
 * Turns the canonical Layer Status INDEX doc into deterministic, pure decision
 * logic. The doc is a compact STATUS read model of the Atlas AI architecture
 * layers plus the next enterprise blocks. Its own frontmatter pins two hard
 * invariants that this service enforces (it does NOT invent a parallel status
 * engine — it pins exactly what the doc states, over plain typed arrays, with
 * no side effects, no database and no tokens spent):
 *
 *   1. LAYER MAP. The doc's table enumerates a closed, ordered set of layers
 *      (0, 0.5, 0.6, 0.7, 0.8, 1, 2, 2.5, 3, 4), each with a single documented
 *      state. The table draws an explicit line between layers that are
 *      "active/implemented" (0, 0.5, 1, 2, 2.5, 3, 4) and layers that are only
 *      "documented" with runtime still phased / read-only / advisory
 *      (0.6, 0.7, 0.8). {@see layerMap()} exposes the ordered map and
 *      {@see resolveLayer()} routes a layer id to its state + maturity, or flags
 *      it as unknown rather than guessing.
 *
 *   2. DESCRIPTIVE-ONLY OVERRIDE GUARD. The doc's decision is verbatim: "Layer
 *      status is descriptive and must not override Kernel, Master or domain
 *      specs." {@see evaluateOverride()} hard-denies any attempt to use this
 *      status doc to override a Kernel / Master / domain spec — the status read
 *      model is never authority over those specs.
 *
 *   3. PROMOTION / READINESS GATE. frontmatter forbidden_changes is verbatim:
 *      "Declarar runtime, maturidade ou prontidao sem evidencia verificavel e
 *      gates verdes." {@see evaluatePromotion()} caps any promotion of a layer
 *      to runtime/ready on BOTH verifiable evidence AND green quality gates;
 *      absent either, the promotion is denied with an explicit reason. A
 *      "documented" layer (0.6/0.7/0.8) may never be reported as "ready" purely
 *      from its documentation.
 *
 *   4. ENTERPRISE BLOCK GATE. The doc states verbatim: "Each block must ship
 *      code, tests and docs together." {@see evaluateBlockShip()} caps a block
 *      as shippable only on the full conjunction of code + tests + docs, and
 *      lists every missing leg.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/canonical-index/layer-status.md
 */
final class AtlasLayerStatusService
{
    /** Maturity bucket: layer is active / implemented runtime. */
    public const MATURITY_ACTIVE = 'active';

    /** Maturity bucket: layer is documented as law, runtime still phased / read-only / advisory. */
    public const MATURITY_DOCUMENTED = 'documented';

    /**
     * The closed, ordered Layer Map exactly as the doc's status table states.
     *
     * Key is the layer id (string, because the doc uses fractional ids like
     * "0.5"); value is [state, maturity]. "state" is the documented one-line
     * status; "maturity" classifies it as active runtime vs documented-only,
     * which is the line the table draws between "Active/Implemented..." rows and
     * the "...documented ... runtime still phased / read-only" rows.
     *
     * @var array<string,array{state:string,maturity:string}>
     */
    public const LAYER_MAP = [
        '0' => [
            'state' => 'Active in compact provider-safe form through glossary and human/vault boundaries.',
            'maturity' => self::MATURITY_ACTIVE,
        ],
        '0.5' => [
            'state' => 'Documentation OS, Knowledge Governance and session bootstrap active.',
            'maturity' => self::MATURITY_ACTIVE,
        ],
        '0.6' => [
            'state' => 'Research Self-Improvement Runtime documented as source-backed evolution law.',
            'maturity' => self::MATURITY_DOCUMENTED,
        ],
        '0.7' => [
            'state' => 'Spec Operating System documented as SDD law; runtime implementation still phased.',
            'maturity' => self::MATURITY_DOCUMENTED,
        ],
        '0.8' => [
            'state' => 'Self-Construction OS documented as governed self-programming law; runtime starts read-only/advisory.',
            'maturity' => self::MATURITY_DOCUMENTED,
        ],
        '1' => [
            'state' => 'Implemented foundation: typed envelope/receipt, capability registry, domain catalog, ledger, surface/provider contracts, failure domains, SLOs and validation.',
            'maturity' => self::MATURITY_ACTIVE,
        ],
        '2' => [
            'state' => 'Master Architecture active; large source doc remains grandfathered.',
            'maturity' => self::MATURITY_ACTIVE,
        ],
        '2.5' => [
            'state' => 'Qualitative Levels active as roadmap/read model, not autonomy permission.',
            'maturity' => self::MATURITY_ACTIVE,
        ],
        '3' => [
            'state' => 'Pipeline, core/domain rules, operating system and governance active.',
            'maturity' => self::MATURITY_ACTIVE,
        ],
        '4' => [
            'state' => '15 domains registered ready, including Programming and Self-Improvement.',
            'maturity' => self::MATURITY_ACTIVE,
        ],
    ];

    /**
     * Specs that this descriptive status read model may NEVER override.
     *
     * From the doc decision: "Layer status is descriptive and must not override
     * Kernel, Master or domain specs."
     *
     * @var list<string>
     */
    public const PROTECTED_SPECS = ['kernel', 'master', 'domain'];

    /**
     * The three legs an enterprise block must ship together.
     *
     * From the doc body: "Each block must ship code, tests and docs together."
     *
     * @var array<string,string>
     */
    public const BLOCK_SHIP_LEGS = [
        'code' => 'code is shipped',
        'tests' => 'tests are shipped',
        'docs' => 'docs are shipped',
    ];

    /**
     * Return the ordered Layer Map as a list of typed rows.
     *
     * @return array{
     *   count:int,
     *   active_count:int,
     *   documented_count:int,
     *   layers:list<array{layer:string,state:string,maturity:string}>
     * }
     */
    public function layerMap(): array
    {
        $layers = [];
        $active = 0;
        $documented = 0;

        foreach (self::LAYER_MAP as $id => $row) {
            // PHP coerces numeric-string array keys ("0", "1") back to int on
            // iteration; cast so the contract always emits a stable string id.
            $layers[] = [
                'layer' => (string) $id,
                'state' => $row['state'],
                'maturity' => $row['maturity'],
            ];

            if ($row['maturity'] === self::MATURITY_ACTIVE) {
                $active++;
            } else {
                $documented++;
            }
        }

        return [
            'count' => count($layers),
            'active_count' => $active,
            'documented_count' => $documented,
            'layers' => $layers,
        ];
    }

    /**
     * Resolve a layer id to its documented state and maturity.
     *
     * The id may be given as a string ("0.5", "2.5") or a number for whole
     * layers (0, 1, 2, 3, 4). Unknown ids are NOT guessed — they are reported.
     *
     * @param int|float|string $layer
     *
     * @return array{resolved:bool,layer:string,state:?string,maturity:?string,reason:?string}
     */
    public function resolveLayer(int|float|string $layer): array
    {
        $id = $this->normalizeLayerId($layer);

        if (! array_key_exists($id, self::LAYER_MAP)) {
            return [
                'resolved' => false,
                'layer' => $id,
                'state' => null,
                'maturity' => null,
                'reason' => 'layer_not_in_canonical_status_table',
            ];
        }

        return [
            'resolved' => true,
            'layer' => $id,
            'state' => self::LAYER_MAP[$id]['state'],
            'maturity' => self::LAYER_MAP[$id]['maturity'],
            'reason' => null,
        ];
    }

    /**
     * Decide whether this status read model may override a target spec.
     *
     * Hard-denies any Kernel / Master / domain spec. The status doc is
     * descriptive and is never authority over those specs. Any other target is
     * out of this doc's concern and is reported as not governed here (also not
     * an override grant).
     *
     * @return array{allowed:false,target:string,reason:string}
     */
    public function evaluateOverride(string $target): array
    {
        $normalized = strtolower(trim($target));

        if (in_array($normalized, self::PROTECTED_SPECS, true)) {
            return [
                'allowed' => false,
                'target' => $normalized,
                'reason' => 'layer_status_is_descriptive_must_not_override_kernel_master_or_domain_specs',
            ];
        }

        return [
            'allowed' => false,
            'target' => $normalized,
            'reason' => 'target_not_governed_by_layer_status_read_model',
        ];
    }

    /**
     * Promotion / readiness gate for a layer.
     *
     * forbidden_changes invariant: runtime / maturity / readiness may NOT be
     * declared without verifiable evidence AND green quality gates. This method
     * therefore only allows a "ready" / "runtime" promotion when BOTH
     * $evidenceVerifiable and $gatesGreen are true. It also refuses to promote a
     * layer that the canonical table records as merely "documented" purely on
     * documentation: such a layer must additionally carry verifiable evidence
     * and green gates (i.e. real runtime), never just its own doc.
     *
     * @param int|float|string $layer            layer id being promoted
     * @param bool             $evidenceVerifiable verifiable evidence is present
     * @param bool             $gatesGreen         quality gates (docs-health etc.) are green
     *
     * @return array{
     *   promoted:bool,
     *   layer:string,
     *   resolved:bool,
     *   current_maturity:?string,
     *   evidence_verifiable:bool,
     *   gates_green:bool,
     *   reason:string
     * }
     */
    public function evaluatePromotion(
        int|float|string $layer,
        bool $evidenceVerifiable,
        bool $gatesGreen,
    ): array {
        $resolution = $this->resolveLayer($layer);

        $base = [
            'layer' => $resolution['layer'],
            'resolved' => $resolution['resolved'],
            'current_maturity' => $resolution['maturity'],
            'evidence_verifiable' => $evidenceVerifiable,
            'gates_green' => $gatesGreen,
        ];

        if (! $resolution['resolved']) {
            return $base + [
                'promoted' => false,
                'reason' => 'layer_not_in_canonical_status_table',
            ];
        }

        if (! $evidenceVerifiable && ! $gatesGreen) {
            return $base + [
                'promoted' => false,
                'reason' => 'promotion_requires_verifiable_evidence_and_green_gates',
            ];
        }

        if (! $evidenceVerifiable) {
            return $base + [
                'promoted' => false,
                'reason' => 'promotion_requires_verifiable_evidence',
            ];
        }

        if (! $gatesGreen) {
            return $base + [
                'promoted' => false,
                'reason' => 'promotion_requires_green_gates',
            ];
        }

        return $base + [
            'promoted' => true,
            'reason' => 'promotion_backed_by_verifiable_evidence_and_green_gates',
        ];
    }

    /**
     * Enterprise block ship gate.
     *
     * From the doc: "Each block must ship code, tests and docs together." A
     * block is shippable only when all three legs are present. Every missing
     * leg is listed so the block is never reported done with a hole.
     *
     * @param array<string,bool> $legs flag map keyed by BLOCK_SHIP_LEGS keys
     *
     * @return array{
     *   shippable:bool,
     *   verdict:string,
     *   shipped:list<string>,
     *   missing:list<string>,
     *   total:int,
     *   reason:?string
     * }
     */
    public function evaluateBlockShip(array $legs): array
    {
        $shipped = [];
        $missing = [];

        foreach (self::BLOCK_SHIP_LEGS as $key => $_label) {
            if (($legs[$key] ?? false) === true) {
                $shipped[] = $key;
            } else {
                $missing[] = $key;
            }
        }

        $allShipped = $missing === [];

        return [
            'shippable' => $allShipped,
            'verdict' => $allShipped ? 'shippable' : 'blocked',
            'shipped' => $shipped,
            'missing' => $missing,
            'total' => count(self::BLOCK_SHIP_LEGS),
            'reason' => $allShipped
                ? null
                : 'block_must_ship_code_tests_and_docs_together',
        ];
    }

    /**
     * Normalize a layer identifier to its canonical string id.
     *
     * Accepts 0, 1, 2.5, "0.5", "Layer 2", "layer-3", " 4 ". Whole floats like
     * 2.0 normalize to "2"; fractional floats like 0.5 normalize to "0.5".
     *
     * @param int|float|string $layer
     */
    private function normalizeLayerId(int|float|string $layer): string
    {
        if (is_int($layer)) {
            return (string) $layer;
        }

        if (is_float($layer)) {
            // Whole-valued floats (2.0) -> "2"; otherwise keep the fraction.
            if (floor($layer) === $layer) {
                return (string) (int) $layer;
            }

            return rtrim(rtrim((string) $layer, '0'), '.');
        }

        $trimmed = strtolower(trim($layer));
        // Pull the numeric token out of forms like "layer 2.5" / "layer-3".
        if (preg_match('/(\d+(?:\.\d+)?)/', $trimmed, $m) === 1) {
            $token = $m[1];
            // Trim a trailing ".0" so "2.0" -> "2".
            if (str_contains($token, '.')) {
                $token = rtrim(rtrim($token, '0'), '.');
            }

            return $token === '' ? '0' : $token;
        }

        return $trimmed;
    }
}
