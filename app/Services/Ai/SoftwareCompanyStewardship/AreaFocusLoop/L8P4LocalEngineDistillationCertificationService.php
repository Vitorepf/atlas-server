<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S124 — L8P4LocalEngineDistillationCertificationService (block: L8 Transcendence).
 *
 * Certifies the L8-P4 teto ("Motor proprio destilado") only when the system has a
 * MEASURED local capability — not model hype. P4 arrives, per
 * atlas-aaeos-l8-transcendence-map.md (criterio de chegada line 163 and the
 * transcendence checklist line 247), when AT LEAST ONE recurring task class has
 * actually started being served by a LOCAL distilled engine grown from Atlas's own
 * governed evidence, with:
 *   - measured local quality EQUAL TO OR GREATER THAN the external path, and
 *   - external dependency demonstrably REDUCED in that segment.
 *
 * The local engine enters the portfolio as one more governed provider under the
 * same gates (line 162); it never becomes automatic authority, and the local-first
 * privacy classes — `sensitive`, `secret`, `cyber` — must stay local by contract.
 * This class mirrors that local-first class set byte-for-byte from the canonical
 * AreaFocusForgeHandoffBuilderService ("sensitive_classes_stay_local") rather than
 * inventing a new one.
 *
 * Per served-class evidence (a class only counts as a genuine local capability when
 * every condition holds):
 *   1. real local provider evidence — a named local engine/provider id, the class
 *      is flagged served-by-local, the engine ran a measured sample volume, and both
 *      a measured local quality and an external-path baseline quality exist;
 *   2. privacy honest — if the class is a local-first class (sensitive/secret/cyber)
 *      the serving local engine must itself be local-first / on-device and must NOT
 *      route externally (a privacy violation otherwise);
 *   3. quality honest — measured local quality is >= the external path quality.
 *
 * Returned measures (all COMPUTED from the inputs):
 *   - local_task_class_count: how many distinct recurring task classes are served by
 *     a local engine with real provider evidence (the candidate set);
 *   - quality_delta: the BINDING margin = the minimum measured (local - external)
 *     quality across the evidence-backed served classes (negative when any served
 *     class is still below its external path; 0.0 when there is no such class);
 *   - external_dependency_reduction: the share of recurring task-class invocation
 *     volume that the QUALIFYING local engines now serve instead of the external
 *     path, clamped to the inclusive 0..1 band (never exceeds 1.0).
 *
 * Verdict rules (ordered, evidence/safety-first — same doctrine as the L7/P1 certs):
 *   - no served class with real local provider evidence blocks
 *     (no_local_provider_evidence);
 *   - any local-first class served by a non-local-first / externally routed engine
 *     blocks (privacy_violation) — soberania local-first is invariant;
 *   - no evidence-backed served class whose measured quality reaches the external
 *     path blocks (quality_below_external_path) — P4 is measured capability, not hype;
 *   - with all of the above clean, P4 is certified only when at least one class is
 *     served by a local engine at >= external quality AND external dependency was
 *     actually reduced (> 0) in that segment.
 *
 * Pure: every returned field is computed from the method inputs via the rules above.
 * No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or randomness.
 * Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l8-transcendence-map.md
 * @see app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusForgeHandoffBuilderService.php
 */
final class L8P4LocalEngineDistillationCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.p4_local_engine_distillation_certification.v1';

    public const BLOCKER_NO_LOCAL_PROVIDER_EVIDENCE = 'no_local_provider_evidence';

    public const BLOCKER_PRIVACY_VIOLATION = 'privacy_violation';

    public const BLOCKER_QUALITY_BELOW_EXTERNAL_PATH = 'quality_below_external_path';

    /**
     * Local-first privacy classes that must stay on-device by contract. Mirrored
     * byte-for-byte from AreaFocusForgeHandoffBuilderService ("sensitive_classes_
     * stay_local") and the local-first gate of atlas-aaeos-l8-transcendence-map.md
     * (line 162). NOT recreated as a new lexicon.
     *
     * @var list<string>
     */
    private const LOCAL_FIRST_CLASSES = ['sensitive', 'secret', 'cyber'];

    /**
     * Certify L8-P4 from a portfolio of recurring task classes and the measured
     * evidence of how each is now served.
     *
     * Recognised `$inputs`:
     *   - task_classes: list<array<string,mixed>> — each record may carry:
     *       - task_class_id | class_id | id (string) — the recurring class;
     *       - privacy_class | privacy (string) — e.g. 'normal' | 'sensitive' |
     *         'secret' | 'cyber';
     *       - served_by_local=true (or serving_engine.kind == 'local') and a named
     *         local_engine_id | engine_id (string) — the distilled engine actually
     *         serving the class;
     *       - local_quality | measured_local_quality (0..1) — measured local quality;
     *       - external_quality | external_path_quality (0..1) — the external baseline;
     *       - local_first=true (or on_device=true / routes_external=false) — whether
     *         the serving local engine keeps data on-device;
     *       - invocation_volume | volume | recurrence_count (>=0) — measured request
     *         volume for the class (weights the dependency-reduction share);
     *       - local_invocation_volume | local_volume (>=0) — how much of that volume
     *         the local engine actually served (defaults to the full volume when the
     *         class is served by a qualifying local engine and no split is given).
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     p4_certified:bool,
     *     local_task_class_count:int,
     *     served_quality_pass_count:int,
     *     privacy_violation_count:int,
     *     quality_delta:float,
     *     external_dependency_reduction:float,
     *     blockers:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $classes = $this->classList($inputs['task_classes'] ?? $inputs['classes'] ?? null);

        $evidenceBackedCount = 0;
        $qualityPassCount = 0;
        $privacyViolationCount = 0;

        $minQualityDelta = null;

        $totalVolume = 0.0;
        $reducedVolume = 0.0;

        foreach ($classes as $class) {
            $volume = $this->volume($class);
            $totalVolume += $volume;

            // Without real local provider evidence the class cannot count toward P4
            // at all (no engine, no measured local/external quality, no served flag).
            if (! $this->hasLocalProviderEvidence($class)) {
                continue;
            }

            $evidenceBackedCount++;

            $localFirstOk = $this->privacyHonest($class);
            if (! $localFirstOk) {
                // A local-first class routed off-device is a sovereignty breach; it
                // can never count as a clean qualifying capability.
                $privacyViolationCount++;
            }

            $delta = $this->localQuality($class) - $this->externalQuality($class);
            $minQualityDelta = $minQualityDelta === null ? $delta : min($minQualityDelta, $delta);

            // Quality is honest only when the measured local path reaches the
            // external path. >= keeps an exact tie as a pass (parity is sufficient
            // to start capturing N internally).
            $qualityOk = $delta >= 0.0;

            if ($qualityOk) {
                $qualityPassCount++;
            }

            // Only a fully clean class (real evidence + privacy honest + quality at
            // or above the external path) genuinely moves dependency off the
            // external path in its segment.
            if ($localFirstOk && $qualityOk) {
                $reducedVolume += $this->localVolume($class, $volume);
            }
        }

        $externalDependencyReduction = $this->dependencyReduction($reducedVolume, $totalVolume);

        $blockers = $this->resolveBlockers(
            $evidenceBackedCount,
            $privacyViolationCount,
            $qualityPassCount,
            $externalDependencyReduction,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'p4_certified' => $blockers === [],
            'local_task_class_count' => $evidenceBackedCount,
            'served_quality_pass_count' => $qualityPassCount,
            'privacy_violation_count' => $privacyViolationCount,
            'quality_delta' => $minQualityDelta ?? 0.0,
            'external_dependency_reduction' => $externalDependencyReduction,
            'blockers' => $blockers,
        ];
    }

    /**
     * Ordered, evidence/safety-first blocker resolution. No local provider evidence
     * blocks first (nothing measured yet); a local-first privacy breach blocks before
     * any capability claim; quality below the external path blocks; finally a clean
     * portfolio that did not actually reduce external dependency still blocks (the
     * capability was not realised end-to-end).
     *
     * @return list<string>
     */
    private function resolveBlockers(
        int $evidenceBackedCount,
        int $privacyViolationCount,
        int $qualityPassCount,
        float $externalDependencyReduction,
    ): array {
        if ($evidenceBackedCount === 0) {
            // No local provider evidence at all — and nothing else is meaningful yet.
            return [self::BLOCKER_NO_LOCAL_PROVIDER_EVIDENCE];
        }

        $blockers = [];

        if ($privacyViolationCount > 0) {
            $blockers[] = self::BLOCKER_PRIVACY_VIOLATION;
        }

        if ($qualityPassCount === 0) {
            $blockers[] = self::BLOCKER_QUALITY_BELOW_EXTERNAL_PATH;
        }

        // A clean, quality-passing portfolio that nonetheless captured none of the
        // external volume has not realised the capability in any segment.
        if ($blockers === [] && $externalDependencyReduction <= 0.0) {
            $blockers[] = self::BLOCKER_QUALITY_BELOW_EXTERNAL_PATH;
        }

        return $blockers;
    }

    /**
     * @param  mixed  $value
     * @return list<array<string,mixed>>
     */
    private function classList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Real local provider evidence: the class is served by a named local engine and
     * carries BOTH a measured local quality and an external-path baseline plus a
     * measured (non-negative) sample volume. A bare "served_by_local" flag with no
     * engine id or no measured qualities is hype, not evidence.
     *
     * @param  array<string,mixed>  $class
     */
    private function hasLocalProviderEvidence(array $class): bool
    {
        if (! $this->servedByLocal($class)) {
            return false;
        }

        if ($this->engineId($class) === '') {
            return false;
        }

        if (! $this->hasNumeric($class, ['local_quality', 'measured_local_quality'])) {
            return false;
        }

        if (! $this->hasNumeric($class, ['external_quality', 'external_path_quality'])) {
            return false;
        }

        return $this->volume($class) > 0.0;
    }

    /**
     * @param  array<string,mixed>  $class
     */
    private function servedByLocal(array $class): bool
    {
        if (($class['served_by_local'] ?? false) === true) {
            return true;
        }

        $engine = $class['serving_engine'] ?? null;
        if (is_array($engine)) {
            return strtolower(trim((string) ($engine['kind'] ?? $engine['type'] ?? ''))) === 'local';
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $class
     */
    private function engineId(array $class): string
    {
        $engine = $class['serving_engine'] ?? null;
        $nested = is_array($engine) ? ($engine['id'] ?? $engine['engine_id'] ?? '') : '';

        return trim((string) (
            $class['local_engine_id']
            ?? $class['engine_id']
            ?? $nested
        ));
    }

    /**
     * Privacy honest: a local-first class (sensitive/secret/cyber) must be served by
     * an engine that is itself local-first / on-device and does NOT route externally.
     * Non local-first classes are unconstrained here.
     *
     * @param  array<string,mixed>  $class
     */
    private function privacyHonest(array $class): bool
    {
        if (! $this->isLocalFirstClass($class)) {
            return true;
        }

        if (($class['routes_external'] ?? false) === true) {
            return false;
        }

        $engine = $class['serving_engine'] ?? null;
        if (is_array($engine) && ($engine['routes_external'] ?? false) === true) {
            return false;
        }

        return ($class['local_first'] ?? null) === true
            || ($class['on_device'] ?? null) === true
            || (is_array($engine) && (($engine['local_first'] ?? null) === true || ($engine['on_device'] ?? null) === true));
    }

    /**
     * @param  array<string,mixed>  $class
     */
    private function isLocalFirstClass(array $class): bool
    {
        $privacy = strtolower(trim((string) (
            $class['privacy_class']
            ?? $class['privacy']
            ?? ''
        )));

        return in_array($privacy, self::LOCAL_FIRST_CLASSES, true);
    }

    /**
     * @param  array<string,mixed>  $class
     */
    private function localQuality(array $class): float
    {
        return $this->numeric($class, ['local_quality', 'measured_local_quality']);
    }

    /**
     * @param  array<string,mixed>  $class
     */
    private function externalQuality(array $class): float
    {
        return $this->numeric($class, ['external_quality', 'external_path_quality']);
    }

    /**
     * Total measured invocation volume for the class (weights its share of the
     * portfolio). Negative inputs are floored to zero.
     *
     * @param  array<string,mixed>  $class
     */
    private function volume(array $class): float
    {
        $volume = $this->numeric($class, ['invocation_volume', 'volume', 'recurrence_count'], 0.0);

        return $volume > 0.0 ? $volume : 0.0;
    }

    /**
     * Volume the local engine actually served for the class. Defaults to the full
     * measured volume when no explicit split is supplied (a qualifying local engine
     * serving the class serves its volume). Clamped to the inclusive 0..volume band
     * so a malformed local split can never exceed the class volume.
     *
     * @param  array<string,mixed>  $class
     */
    private function localVolume(array $class, float $volume): float
    {
        if (! $this->hasNumeric($class, ['local_invocation_volume', 'local_volume'])) {
            return $volume;
        }

        $local = $this->numeric($class, ['local_invocation_volume', 'local_volume'], 0.0);

        return min(max($local, 0.0), $volume);
    }

    /**
     * Share of total recurring task-class volume now served locally instead of
     * externally, clamped to the inclusive 0..1 band. With no measured volume the
     * reduction is 0.0 (nothing was captured).
     */
    private function dependencyReduction(float $reducedVolume, float $totalVolume): float
    {
        if ($totalVolume <= 0.0) {
            return 0.0;
        }

        $ratio = $reducedVolume / $totalVolume;

        return min(max($ratio, 0.0), 1.0);
    }

    /**
     * A real measurement is a FINITE int/float. A non-finite value (NAN/INF) is the
     * residue of an upstream divide-by-zero or overflow on a 0..1-declared score, not
     * a measured local/external quality or volume — so it is NOT evidence. This keeps
     * the certifier fail-closed and symmetric: NAN already fails the `>= external`
     * gate, and INF must not be allowed to satisfy it (`INF >= 0.0`) and falsely
     * certify the P4 teto. Mirrors the finite-numeric convention of the sibling
     * L8 P-phase certs (e.g. L8P3PredictiveTwinCertificationService).
     *
     * @param  array<string,mixed>  $class
     * @param  list<string>  $keys
     */
    private function hasNumeric(array $class, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $class)
                && (is_int($class[$key]) || is_float($class[$key]))
                && is_finite((float) $class[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $class
     * @param  list<string>  $keys
     */
    private function numeric(array $class, array $keys, float $default = 0.0): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $class)
                && (is_int($class[$key]) || is_float($class[$key]))
                && is_finite((float) $class[$key])) {
                return (float) $class[$key];
            }
        }

        return $default;
    }
}
