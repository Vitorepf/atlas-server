<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cognitive Plane — Overview decider.
 *
 * Pure, deterministic enforcement of the four concrete contracts the executive
 * overview doc fixes for the Cognitive Plane. The service never mutates code,
 * calls a provider, runs a gate or touches the database — it classifies an
 * input and returns a typed verdict so the doc's invariants cannot be silently
 * skipped.
 *
 * Concrete rules grounded in the doc:
 *   - "Pergunta-norte cognitiva"  → a cognitive feature is built only when it
 *     BOTH multiplies the operator's cognitive output AND keeps Atlas as the
 *     single channel. "Compete com o ato de aprender" OR "cria friccao de
 *     escape" => descarta. (multiplies AND single_channel) -> build, else drop.
 *   - "Os 4 Pilares Cognitivos"   → mastery requires ALL four pillars
 *     (teorico, pratico, cognitivo, transferencial). "O Atlas nao marca
 *     dominado em rubrica que cobre so um" — faltar um invalida.
 *   - "Compressao temporal"        → tempo_atlas / tempo_natural >= 3x is the
 *     floor; below 3x is not admissible. 5-10x is the long-horizon target band.
 *   - "5 Movimentos"               → the method runs DECLARAR -> GERAR ERRO ->
 *     PRATICAR -> PROVAR -> REVISAR, in that exact order. The documented
 *     inversion places GERAR ERRO *before* PRATICAR. A "Gerar Erro" step
 *     without a later comparison + transfer becomes ruido (invalid).
 *
 * @see docs/engineering-knowledge-base/cognitive/overview.md
 */
final class AtlasCognitiveOverviewService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.cognitive.overview.v1';

    // ---- North-question verdicts (closed set) -------------------------------

    public const NORTH_BUILD = 'build';
    public const NORTH_DISCARD = 'discard';

    // ---- The 4 cognitive pillars (mastery requires all) ---------------------

    public const PILLAR_THEORETICAL = 'teorico';
    public const PILLAR_PRACTICAL = 'pratico';
    public const PILLAR_COGNITIVE = 'cognitivo';
    public const PILLAR_TRANSFER = 'transferencial';

    /**
     * The four inseparable pillars, in the doc's order. Mastery ("dominado")
     * is only valid when every one of these is met.
     *
     * @var list<string>
     */
    public const PILLARS = [
        self::PILLAR_THEORETICAL,
        self::PILLAR_PRACTICAL,
        self::PILLAR_COGNITIVE,
        self::PILLAR_TRANSFER,
    ];

    // ---- The 5 movements (strict canonical order) ---------------------------

    public const MOVEMENT_DECLARE = 'declarar';
    public const MOVEMENT_GENERATE_ERROR = 'gerar_erro';
    public const MOVEMENT_PRACTICE = 'praticar';
    public const MOVEMENT_PROVE = 'provar';
    public const MOVEMENT_REVIEW = 'revisar';

    /**
     * The 5 movements in the doc's exact order. The documented inversion is
     * that "gerar_erro" precedes "praticar" (Generation Effect / Productive
     * Failure before Deliberate Practice).
     *
     * @var list<string>
     */
    public const MOVEMENTS = [
        self::MOVEMENT_DECLARE,
        self::MOVEMENT_GENERATE_ERROR,
        self::MOVEMENT_PRACTICE,
        self::MOVEMENT_PROVE,
        self::MOVEMENT_REVIEW,
    ];

    // ---- Temporal-compression thresholds ------------------------------------

    /** Floor: Atlas must compress the mastery cycle by at least this factor. */
    public const COMPRESSION_FLOOR = 3.0;

    /** Long-horizon target band lower / upper bounds (5x..10x). */
    public const COMPRESSION_TARGET_MIN = 5.0;
    public const COMPRESSION_TARGET_MAX = 10.0;

    /**
     * Apply the cognitive north-question to a candidate cognitive feature.
     *
     * The doc: "Esta feature multiplica meu output cognitivo, ou compete com o
     * ato de aprender? Mantem o Atlas como canal unico, ou cria friccao de
     * escape? Multiplica + canal unico -> constroi. Compete + escape ->
     * descarta."
     *
     * A feature is built ONLY when it both multiplies cognitive output and
     * keeps Atlas as the single channel. Competing with the act of learning, or
     * creating an escape-hatch out of the channel, forces a discard. Both
     * conditions are required; failing either is fatal (fail-closed default).
     *
     * @param  array{multiplies_cognitive_output?:bool,single_channel?:bool,competes_with_learning?:bool,creates_escape?:bool}  $feature
     * @return array{verdict:string,build:bool,multiplies:bool,single_channel:bool,reasons:list<string>}
     */
    public function applyNorthQuestion(array $feature): array
    {
        $multiplies = (bool) ($feature['multiplies_cognitive_output'] ?? false);
        $singleChannel = (bool) ($feature['single_channel'] ?? false);

        // The two failure phrasings the doc names explicitly. Either, if set,
        // independently negates the corresponding positive condition.
        $competes = (bool) ($feature['competes_with_learning'] ?? false);
        $createsEscape = (bool) ($feature['creates_escape'] ?? false);

        $multipliesNet = $multiplies && ! $competes;
        $channelNet = $singleChannel && ! $createsEscape;

        $reasons = [];
        if (! $multiplies) {
            $reasons[] = 'does_not_multiply_cognitive_output';
        }
        if ($competes) {
            $reasons[] = 'competes_with_act_of_learning';
        }
        if (! $singleChannel) {
            $reasons[] = 'not_single_channel';
        }
        if ($createsEscape) {
            $reasons[] = 'creates_escape_friction';
        }

        $build = $multipliesNet && $channelNet;

        return [
            'verdict' => $build ? self::NORTH_BUILD : self::NORTH_DISCARD,
            'build' => $build,
            'multiplies' => $multipliesNet,
            'single_channel' => $channelNet,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Evaluate whether an area may be marked "dominado" given per-pillar
     * coverage. Mastery requires ALL FOUR pillars; a rubric that covers only a
     * subset must NOT flip the area to mastered.
     *
     * The doc: "Maestria de uma area exige os quatro. O Atlas nao marca
     * dominado em rubrica que cobre so um." Each missing pillar invalidates
     * mastery and is reported, so the caller can show what is still open.
     *
     * Unknown / undeclared pillars fail closed (treated as not met).
     *
     * @param  array<string,bool>  $pillarCoverage  pillar key => met?
     * @return array{
     *   mastered:bool,
     *   covered:list<string>,
     *   missing:list<string>,
     *   coverage_ratio:float,
     *   invalidated_by:list<string>
     * }
     */
    public function evaluateMastery(array $pillarCoverage): array
    {
        $covered = [];
        $missing = [];

        foreach (self::PILLARS as $pillar) {
            // Fail closed: absent key => pillar not met.
            if (($pillarCoverage[$pillar] ?? false) === true) {
                $covered[] = $pillar;
            } else {
                $missing[] = $pillar;
            }
        }

        $mastered = $missing === [];
        $total = count(self::PILLARS);
        $ratio = $total > 0 ? round(count($covered) / $total, 4) : 0.0;

        return [
            'mastered' => $mastered,
            'covered' => array_values($covered),
            'missing' => array_values($missing),
            'coverage_ratio' => $ratio,
            // Each missing pillar is a reason mastery is invalidated.
            'invalidated_by' => array_values($missing),
        ];
    }

    /**
     * Classify the temporal-compression factor achieved against the documented
     * thresholds.
     *
     * The doc: "Compressao temporal | tempo Atlas / tempo natural >= 3x; meta
     * longa 5-10x." The factor here is the natural/atlas speedup multiple
     * (e.g. 4.0 means Atlas reaches mastery 4x faster). Below 3x is not
     * admissible; 3x..<5x meets the floor; 5x..10x is on-target; >10x exceeds.
     *
     * @return array{
     *   factor:float,
     *   meets_floor:bool,
     *   on_target:bool,
     *   exceeds_target:bool,
     *   band:string,
     *   floor:float,
     *   target_min:float,
     *   target_max:float
     * }
     */
    public function classifyCompression(float $factor): array
    {
        $meetsFloor = $factor >= self::COMPRESSION_FLOOR;
        $onTarget = $factor >= self::COMPRESSION_TARGET_MIN && $factor <= self::COMPRESSION_TARGET_MAX;
        $exceeds = $factor > self::COMPRESSION_TARGET_MAX;

        if (! $meetsFloor) {
            $band = 'below_floor';
        } elseif ($exceeds) {
            $band = 'exceeds_target';
        } elseif ($onTarget) {
            $band = 'on_target';
        } else {
            // >= floor (3x) but < target_min (5x): meets the floor only.
            $band = 'meets_floor';
        }

        return [
            'factor' => $factor,
            'meets_floor' => $meetsFloor,
            'on_target' => $onTarget,
            'exceeds_target' => $exceeds,
            'band' => $band,
            'floor' => self::COMPRESSION_FLOOR,
            'target_min' => self::COMPRESSION_TARGET_MIN,
            'target_max' => self::COMPRESSION_TARGET_MAX,
        ];
    }

    /**
     * Validate a proposed sequence of the 5 movements against the canonical
     * order DECLARAR -> GERAR ERRO -> PRATICAR -> PROVAR -> REVISAR.
     *
     * Two documented invariants are enforced:
     *   - the full method runs all five movements in the canonical order;
     *   - the inversion holds: "gerar_erro" must come strictly BEFORE
     *     "praticar" (the doc's explicit reordering).
     *
     * Unknown movements are reported and make the sequence invalid.
     *
     * @param  list<string>  $sequence
     * @return array{
     *   valid:bool,
     *   canonical:bool,
     *   error_before_practice:bool,
     *   unknown_movements:list<string>,
     *   missing_movements:list<string>,
     *   expected:list<string>,
     *   reasons:list<string>
     * }
     */
    public function validateMovementSequence(array $sequence): array
    {
        $reasons = [];

        $unknown = array_values(array_diff($sequence, self::MOVEMENTS));
        $missing = array_values(array_diff(self::MOVEMENTS, $sequence));

        $canonical = $sequence === self::MOVEMENTS;

        // The inversion check: gerar_erro must precede praticar when both are
        // present. If either is absent, the inversion cannot be satisfied.
        $errBefore = false;
        $errPos = array_search(self::MOVEMENT_GENERATE_ERROR, $sequence, true);
        $pracPos = array_search(self::MOVEMENT_PRACTICE, $sequence, true);
        if ($errPos !== false && $pracPos !== false) {
            $errBefore = $errPos < $pracPos;
        }

        if ($unknown !== []) {
            $reasons[] = 'unknown_movements_present';
        }
        if ($missing !== []) {
            $reasons[] = 'missing_movements';
        }
        if (! $errBefore) {
            $reasons[] = 'generate_error_must_precede_practice';
        }
        if (! $canonical && $unknown === [] && $missing === []) {
            $reasons[] = 'order_deviates_from_canonical';
        }

        $valid = $canonical && $errBefore;

        return [
            'valid' => $valid,
            'canonical' => $canonical,
            'error_before_practice' => $errBefore,
            'unknown_movements' => $unknown,
            'missing_movements' => $missing,
            'expected' => self::MOVEMENTS,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Validate a single "Gerar Erro" step. The doc warns that this step is not
     * artificial frustration: "Sem comparacao e transferencia, 'errar' vira
     * ruido." A productive-failure step must capture the operator prediction,
     * then later compare divergence AND run a transfer test; otherwise it is
     * ruido and invalid.
     *
     * @param  array{captured_prediction?:bool,has_comparison?:bool,has_transfer_test?:bool}  $step
     * @return array{productive:bool,is_noise:bool,reasons:list<string>}
     */
    public function validateGenerateErrorStep(array $step): array
    {
        $captured = (bool) ($step['captured_prediction'] ?? false);
        $comparison = (bool) ($step['has_comparison'] ?? false);
        $transfer = (bool) ($step['has_transfer_test'] ?? false);

        $reasons = [];
        if (! $captured) {
            $reasons[] = 'operator_prediction_not_captured';
        }
        if (! $comparison) {
            $reasons[] = 'missing_divergence_comparison';
        }
        if (! $transfer) {
            $reasons[] = 'missing_transfer_test';
        }

        $productive = $captured && $comparison && $transfer;

        return [
            'productive' => $productive,
            // Without comparison AND transfer the "error" is just noise.
            'is_noise' => ! ($comparison && $transfer),
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Convenience boolean: may this area be marked mastered?
     *
     * @param  array<string,bool>  $pillarCoverage
     */
    public function isMastered(array $pillarCoverage): bool
    {
        return $this->evaluateMastery($pillarCoverage)['mastered'];
    }

    /**
     * Convenience boolean: should this cognitive feature be built?
     *
     * @param  array<string,mixed>  $feature
     */
    public function shouldBuild(array $feature): bool
    {
        return $this->applyNorthQuestion($feature)['build'];
    }
}
