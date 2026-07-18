<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;
use RuntimeException;

/**
 * ELEV-29s — Spec de capacidades de modelo por função.
 *
 * Read-only autoridade sobre as capacidades exigidas por função (dense_embed,
 * late_chunk, rerank, sparse). Recebe uma descrição do modelo carregado
 * ({dim, pooling, ctx, multilingual_pt, license, deterministic, latency_per_pair_ms_p95,
 * token_embeddings_exposed, term_weights_exposed}) e retorna verdict + razão nomeada.
 *
 * A troca de modelo é detectável pela provenance MAXA-03 (`embedding_model` por
 * vetor); esta classe apenas governa o CONTRATO — não persiste nada.
 */
final class AtlasModelCapabilitySpecService
{

    public const SPEC_CONFIG_KEY = 'atlas_model_capability_spec';

    public const REASON_MISSING_MODEL_ID = 'missing_model_id';

    public const REASON_LATENCY_ABOVE_SPEC_CEILING = 'latency_above_spec_ceiling';

    public const REASON_LICENSE_MISSING = 'license_missing';

    public const REASON_LICENSE_NOT_ALLOWED = 'license_not_allowed';

    public const STATUS_OK = 'ok';

    public const STATUS_VIOLATES_SPEC = 'violates_spec';

    public const FALLBACK_MODEL_ID = 'unknown';
    public const FIELD_REASON = 'reason';
    public const FIELD_FIELD = 'field';
    public const FIELD_EXPECTED = 'expected';
    public const FIELD_ACTUAL = 'actual';
    public const FIELD_STATUS = 'status';
    public const FIELD_MODEL_ID = 'model_id';
    public const FIELD_VIOLATIONS = 'violations';
    public const FIELD_LATENCY_PER_PAIR_MS_P95 = 'latency_per_pair_ms_p95';
    public const FIELD_FUNCTIONS = 'functions';
    public const FIELD_FUNCTION = 'function';
    public const FIELD_LICENSE_ALLOWED = 'license_allowed';
    public const FIELD_LICENSE = 'license';
    public const FIELD_NON_EMPTY_STRING = 'non_empty_string';
    public const FIELD_CONTEXT_BELOW_SPEC_FLOOR = 'context_below_spec_floor';
    public const FIELD_DETERMINISTIC = 'deterministic';
    public const FIELD_DIM = 'dim';
    public const FIELD_DIM_NOT_ALLOWED = 'dim_not_allowed';
    /** @var array<string, array<string, mixed>> */
    private array $functions;

    public function __construct(?array $spec = null)
    {
        $spec = $spec ?? AiValueNormalizer::arrayOrEmpty(config(self::SPEC_CONFIG_KEY, []));
        $functions = AiValueNormalizer::arrayOrEmpty($spec[self::FIELD_FUNCTIONS] ?? null);
        $this->functions = array_change_key_case($functions, CASE_LOWER);
    }

    /** @return list<string> */
    public function functions(): array
    {
        return array_keys($this->functions);
    }

    /** @return array<string, mixed> */
    public function specFor(string $function): array
    {
        $key = AiValueNormalizer::lowerTrimmedString($function);
        if (! array_key_exists($key, $this->functions)) {
            throw new RuntimeException('unknown_model_function:'.$function);
        }

        return $this->functions[$key];
    }

    /**
     * Verify a loaded model implementation against the spec of a function.
     *
     * @param  array<string, mixed>  $model
     * @return array{
     *     status:string,
     *     function:string,
     *     model_id:string,
     *     violations:list<array{field:string,reason:string,expected:mixed,actual:mixed}>
     * }
     */
    public function verify(string $function, array $model): array
    {
        $spec = $this->specFor($function);
        $violations = [];

        $modelId = AiValueNormalizer::trimmedStringOrNull($model[self::FIELD_MODEL_ID] ?? null) ?? '';
        if ($modelId === '') {
            $violations[] = [
                self::FIELD_FIELD => 'model_id',
                self::FIELD_REASON => self::REASON_MISSING_MODEL_ID,
                self::FIELD_EXPECTED => self::FIELD_NON_EMPTY_STRING,
                self::FIELD_ACTUAL => null,
            ];
        }

        $violations = array_merge($violations, $this->checkNumericFloor(
            $spec, $model, 'min_ctx_tokens', 'ctx_tokens', self::FIELD_CONTEXT_BELOW_SPEC_FLOOR
        ));

        $violations = array_merge($violations, $this->checkEnum(
            $spec, $model, self::FIELD_DIM, 'dim', self::FIELD_DIM_NOT_ALLOWED
        ));

        $violations = array_merge($violations, $this->checkEnum(
            $spec, $model, 'pooling', 'pooling', 'pooling_not_allowed'
        ));

        $violations = array_merge($violations, $this->checkBooleanTrue(
            $spec, $model, 'multilingual_pt', 'multilingual_pt_required'
        ));

        $violations = array_merge($violations, $this->checkBooleanTrue(
            $spec, $model, self::FIELD_DETERMINISTIC, 'non_deterministic_model_refused'
        ));

        $violations = array_merge($violations, $this->checkBooleanTrue(
            $spec, $model, 'pair_scoring', 'pair_scoring_missing'
        ));

        $violations = array_merge($violations, $this->checkBooleanTrue(
            $spec, $model, 'token_embeddings_exposed', 'token_embeddings_not_exposed'
        ));

        $violations = array_merge($violations, $this->checkBooleanTrue(
            $spec, $model, 'term_weights_exposed', 'term_weights_not_exposed'
        ));

        $violations = array_merge($violations, $this->checkLatency($spec, $model));

        $violations = array_merge($violations, $this->checkLicense($spec, $model));

        return [
            self::FIELD_STATUS => $violations === [] ? self::STATUS_OK : self::STATUS_VIOLATES_SPEC,
            self::FIELD_FUNCTION => AiValueNormalizer::lowerTrimmedString($function),
            self::FIELD_MODEL_ID => $modelId !== '' ? $modelId : self::FALLBACK_MODEL_ID,
            self::FIELD_VIOLATIONS => $violations,
        ];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $model
     * @return list<array{field:string,reason:string,expected:mixed,actual:mixed}>
     */
    private function checkNumericFloor(array $spec, array $model, string $specKey, string $modelKey, string $reason): array
    {
        if (! array_key_exists($specKey, $spec)) {
            return [];
        }
        $floor = (int) $spec[$specKey];
        $actual = array_key_exists($modelKey, $model) ? (int) $model[$modelKey] : null;
        if ($actual === null || $actual < $floor) {
            return [[
                self::FIELD_FIELD => $modelKey,
                self::FIELD_REASON => $reason,
                self::FIELD_EXPECTED => '>='.$floor,
                self::FIELD_ACTUAL => $actual,
            ]];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $model
     * @return list<array{field:string,reason:string,expected:mixed,actual:mixed}>
     */
    private function checkEnum(array $spec, array $model, string $specKey, string $modelKey, string $reason): array
    {
        if (! array_key_exists($specKey, $spec)) {
            return [];
        }
        $allowed = AiValueNormalizer::arrayOrEmpty($spec[$specKey] ?? null);
        if ($allowed === []) {
            return [];
        }
        if (! array_key_exists($modelKey, $model)) {
            return [[
                self::FIELD_FIELD => $modelKey,
                self::FIELD_REASON => $reason.'_missing',
                self::FIELD_EXPECTED => $allowed,
                self::FIELD_ACTUAL => null,
            ]];
        }
        $actual = $model[$modelKey];
        if (! in_array($actual, $allowed, false)) {
            return [[
                self::FIELD_FIELD => $modelKey,
                self::FIELD_REASON => $reason,
                self::FIELD_EXPECTED => $allowed,
                self::FIELD_ACTUAL => $actual,
            ]];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $model
     * @return list<array{field:string,reason:string,expected:mixed,actual:mixed}>
     */
    private function checkBooleanTrue(array $spec, array $model, string $key, string $reason): array
    {
        if (! array_key_exists($key, $spec) || $spec[$key] !== true) {
            return [];
        }
        $actual = $model[$key] ?? null;
        if ($actual !== true) {
            return [[
                self::FIELD_FIELD => $key,
                self::FIELD_REASON => $reason,
                self::FIELD_EXPECTED => true,
                self::FIELD_ACTUAL => $actual,
            ]];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $model
     * @return list<array{field:string,reason:string,expected:mixed,actual:mixed}>
     */
    private function checkLatency(array $spec, array $model): array
    {
        if (! array_key_exists('latency_per_pair_ms_p95', $spec)) {
            return [];
        }
        $ceiling = AiValueNormalizer::finiteFloatOrNull($spec[self::FIELD_LATENCY_PER_PAIR_MS_P95] ?? null);
        if ($ceiling === null) {
            return [];
        }
        $actual = AiValueNormalizer::finiteFloatOrNull($model[self::FIELD_LATENCY_PER_PAIR_MS_P95] ?? null);
        if ($actual === null) {
            return [];
        }
        if ($actual > $ceiling) {
            return [[
                self::FIELD_FIELD => 'latency_per_pair_ms_p95',
                self::FIELD_REASON => self::REASON_LATENCY_ABOVE_SPEC_CEILING,
                self::FIELD_EXPECTED => '<='.$ceiling,
                self::FIELD_ACTUAL => $actual,
            ]];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $model
     * @return list<array{field:string,reason:string,expected:mixed,actual:mixed}>
     */
    private function checkLicense(array $spec, array $model): array
    {
        if (! array_key_exists('license_allowed', $spec)) {
            return [];
        }
        $allowed = array_values(array_filter(array_map(
            static fn (mixed $value): string => AiValueNormalizer::lowerTrimmedString($value),
            AiValueNormalizer::arrayOrEmpty($spec[self::FIELD_LICENSE_ALLOWED] ?? null),
        )));
        if ($allowed === []) {
            return [];
        }
        $license = AiValueNormalizer::lowerTrimmedString($model[self::FIELD_LICENSE] ?? '');
        if ($license === '') {
            return [[
                self::FIELD_FIELD => 'license',
                self::FIELD_REASON => self::REASON_LICENSE_MISSING,
                self::FIELD_EXPECTED => $allowed,
                self::FIELD_ACTUAL => null,
            ]];
        }
        if (! in_array($license, $allowed, true)) {
            return [[
                self::FIELD_FIELD => 'license',
                self::FIELD_REASON => self::REASON_LICENSE_NOT_ALLOWED,
                self::FIELD_EXPECTED => $allowed,
                self::FIELD_ACTUAL => $license,
            ]];
        }

        return [];
    }
}
