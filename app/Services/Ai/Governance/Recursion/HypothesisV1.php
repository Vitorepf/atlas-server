<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance\Recursion;

/**
 * REC-01 — canonical `hypothesis.v1` schema (pétreo).
 *
 * The single, mechanical contract that every important evolution must fill
 * BEFORE a promotion flip (ELEV-26s) references it. The ingredients (frozen
 * metric, falsifier, rollback) already exist scattered across MED-01, ROL-01
 * and the promotion protocol — REC-01 is the CONSOLIDATION into one object,
 * not a new form.
 *
 * Frontier plan §3036-3040 mandates the 10 canonical fields and the
 * ELEV-31 3-alternatives comparative. This class is the deterministic
 * validator: a payload is either complete-and-well-typed, or it is
 * mechanically refused with the list of missing/invalid fields (no partial
 * acceptance, no fuzzy "close enough" — the point of a canonical schema is
 * that a hypothesis without a falsifier is not a hypothesis).
 *
 * READ-ONLY / provider-safe. Zero I/O. Pure function of its input.
 */
final class HypothesisV1
{
    public const SCHEMA_VERSION = 'atlas.acos.hypothesis.v1';

    /**
     * Canonical required top-level fields. Every one is a documented
     * ingredient in the plan (§3036-3040) — nothing is bureaucratic filler.
     *
     * @var list<string>
     */
    public const REQUIRED_FIELDS = [
        'proposed_change',        // free-form intent (what the flip does)
        'causal_mechanism',       // WHY the change is expected to move the metric
        'frozen_metric_ref',      // MED-01 freeze reference (must exist before)
        'expected_result',        // predicted direction+magnitude
        'falsifier',              // ROL-01 refutation condition (mandatory)
        'treatment_control',      // A/B design or explicit N/A justification
        'budget',                 // token/wall-clock budget declared upfront
        'rollback_pre_declared',  // ROL-01 rollback handle (must be real)
        'architectural_cost',     // ELEV-27: declared cost the flip is allowed
        'alternatives_compared',  // ELEV-31: 3 alternatives comparative
    ];

    /**
     * ELEV-31: every hypothesis must compare AT LEAST these three
     * alternatives so "do nothing" and "simplify" are on record as
     * considered — the anti-busywork guard baked into the schema.
     *
     * @var list<string>
     */
    public const REQUIRED_ALTERNATIVES = [
        'do_nothing',
        'simplify_existing',
        'remove_a_layer',
    ];

    /**
     * Validate a hypothesis payload against the canonical `v1` schema.
     * Returns a list of {field, code, reason} triples. Empty list ⇒ valid.
     *
     * @param  array<string,mixed>  $payload
     * @return list<array{field:string,code:string,reason:string}>
     */
    public static function validate(array $payload): array
    {
        $errors = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                $errors[] = self::error($field, 'missing', 'required field absent from payload');

                continue;
            }
            $value = $payload[$field];
            if ($value === null || $value === '' || $value === []) {
                $errors[] = self::error($field, 'empty', 'required field present but empty');
            }
        }

        if (isset($payload['falsifier']) && is_array($payload['falsifier'])) {
            $errors = array_merge($errors, self::validateFalsifier($payload['falsifier']));
        }

        if (isset($payload['rollback_pre_declared']) && is_array($payload['rollback_pre_declared'])) {
            $errors = array_merge($errors, self::validateRollback($payload['rollback_pre_declared']));
        }

        if (array_key_exists('alternatives_compared', $payload)) {
            $errors = array_merge($errors, self::validateAlternatives($payload['alternatives_compared']));
        }

        if (isset($payload['frozen_metric_ref']) && ! self::isNonEmptyString($payload['frozen_metric_ref'])) {
            $errors[] = self::error(
                'frozen_metric_ref',
                'invalid_type',
                'frozen_metric_ref must be a non-empty string (MED-01 freeze ref)',
            );
        }

        return array_values($errors);
    }

    /**
     * True iff the payload passes `validate()` with zero errors.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function isValid(array $payload): bool
    {
        return self::validate($payload) === [];
    }

    /**
     * Canonicalize a valid payload for downstream hashing/ledgering.
     * Adds the `schema_version` stamp and orders keys deterministically.
     * Callers MUST call `validate()` first — this method does not repeat
     * validation and does not silently coerce missing fields.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function canonicalize(array $payload): array
    {
        $canonical = ['schema_version' => self::SCHEMA_VERSION];
        foreach (self::REQUIRED_FIELDS as $field) {
            $canonical[$field] = $payload[$field] ?? null;
        }
        foreach ($payload as $key => $value) {
            if ($key === 'schema_version' || in_array($key, self::REQUIRED_FIELDS, true)) {
                continue;
            }
            $canonical[$key] = $value;
        }

        return $canonical;
    }

    /**
     * @return list<array{field:string,code:string,reason:string}>
     */
    private static function validateFalsifier(array $falsifier): array
    {
        $errors = [];
        // The falsifier must be a machinable condition, not a paragraph.
        // We require at minimum a metric handle and a threshold expression;
        // "prose falsifier" is exactly the anti-pattern REC-01 blocks.
        if (! self::isNonEmptyString($falsifier['metric'] ?? null)) {
            $errors[] = self::error(
                'falsifier.metric',
                'missing',
                'falsifier must reference a MED-01 metric handle (string)',
            );
        }
        if (! self::isNonEmptyString($falsifier['condition'] ?? null)) {
            $errors[] = self::error(
                'falsifier.condition',
                'missing',
                'falsifier must declare a machinable condition (e.g. "<0.05" or ">baseline+2sigma")',
            );
        }

        return $errors;
    }

    /**
     * @return list<array{field:string,code:string,reason:string}>
     */
    private static function validateRollback(array $rollback): array
    {
        $errors = [];
        // A rollback handle without a `handle` or `reverse_command` is not
        // a rollback — it is a promise. ROL-01 refuses promises.
        $hasHandle = self::isNonEmptyString($rollback['handle'] ?? null);
        $hasCommand = self::isNonEmptyString($rollback['reverse_command'] ?? null);
        if (! $hasHandle && ! $hasCommand) {
            $errors[] = self::error(
                'rollback_pre_declared',
                'incomplete',
                'rollback must declare either a `handle` (ROL-01 reverse_handle id) or a `reverse_command`',
            );
        }

        return $errors;
    }

    /**
     * @return list<array{field:string,code:string,reason:string}>
     */
    private static function validateAlternatives(mixed $alternatives): array
    {
        if (! is_array($alternatives)) {
            return [self::error(
                'alternatives_compared',
                'invalid_type',
                'alternatives_compared must be an array keyed by ['.implode(',', self::REQUIRED_ALTERNATIVES).']',
            )];
        }

        $errors = [];
        foreach (self::REQUIRED_ALTERNATIVES as $key) {
            if (! array_key_exists($key, $alternatives)) {
                $errors[] = self::error(
                    'alternatives_compared.'.$key,
                    'missing',
                    'ELEV-31: the three canonical alternatives ('.implode(',', self::REQUIRED_ALTERNATIVES).') must all be compared',
                );

                continue;
            }
            $value = $alternatives[$key];
            if (! self::isNonEmptyString($value) && ! (is_array($value) && $value !== [])) {
                $errors[] = self::error(
                    'alternatives_compared.'.$key,
                    'empty',
                    'alternative "'.$key.'" was declared but not compared (empty rationale)',
                );
            }
        }

        return $errors;
    }

    private static function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @return array{field:string,code:string,reason:string}
     */
    private static function error(string $field, string $code, string $reason): array
    {
        return ['field' => $field, 'code' => $code, 'reason' => $reason];
    }
}
