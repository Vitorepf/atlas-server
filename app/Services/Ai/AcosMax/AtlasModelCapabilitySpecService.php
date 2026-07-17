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
    /** @var array<string, array<string, mixed>> */
    private array $functions;

    public function __construct(?array $spec = null)
    {
        $spec = $spec ?? AiValueNormalizer::arrayOrEmpty(config(self::SPEC_CONFIG_KEY, []));
        $functions = AiValueNormalizer::arrayOrEmpty($spec['functions'] ?? null);
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

        $modelId = AiValueNormalizer::trimmedStringOrNull($model['model_id'] ?? null) ?? '';
        if ($modelId === '') {
            $violations[] = [
                'field' => 'model_id',
                'reason' => self::REASON_MISSING_MODEL_ID,
                'expected' => 'non_empty_string',
                'actual' => null,
            ];
        }

        $violations = array_merge($violations, $this->checkNumericFloor(
            $spec, $model, 'min_ctx_tokens', 'ctx_tokens', 'context_below_spec_floor'
        ));

        $violations = array_merge($violations, $this->checkEnum(
            $spec, $model, 'dim', 'dim', 'dim_not_allowed'
        ));

        $violations = array_merge($violations, $this->checkEnum(
            $spec, $model, 'pooling', 'pooling', 'pooling_not_allowed'
        ));

        $violations = array_merge($violations, $this->checkBooleanTrue(
            $spec, $model, 'multilingual_pt', 'multilingual_pt_required'
        ));

        $violations = array_merge($violations, $this->checkBooleanTrue(
            $spec, $model, 'deterministic', 'non_deterministic_model_refused'
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
            'status' => $violations === [] ? 'ok' : 'violates_spec',
            'function' => AiValueNormalizer::lowerTrimmedString($function),
            'model_id' => $modelId !== '' ? $modelId : 'unknown',
            'violations' => $violations,
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
                'field' => $modelKey,
                'reason' => $reason,
                'expected' => '>='.$floor,
                'actual' => $actual,
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
                'field' => $modelKey,
                'reason' => $reason.'_missing',
                'expected' => $allowed,
                'actual' => null,
            ]];
        }
        $actual = $model[$modelKey];
        if (! in_array($actual, $allowed, false)) {
            return [[
                'field' => $modelKey,
                'reason' => $reason,
                'expected' => $allowed,
                'actual' => $actual,
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
                'field' => $key,
                'reason' => $reason,
                'expected' => true,
                'actual' => $actual,
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
        $ceiling = AiValueNormalizer::finiteFloatOrNull($spec['latency_per_pair_ms_p95'] ?? null);
        if ($ceiling === null) {
            return [];
        }
        $actual = AiValueNormalizer::finiteFloatOrNull($model['latency_per_pair_ms_p95'] ?? null);
        if ($actual === null) {
            return [];
        }
        if ($actual > $ceiling) {
            return [[
                'field' => 'latency_per_pair_ms_p95',
                'reason' => self::REASON_LATENCY_ABOVE_SPEC_CEILING,
                'expected' => '<='.$ceiling,
                'actual' => $actual,
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
            AiValueNormalizer::arrayOrEmpty($spec['license_allowed'] ?? null),
        )));
        if ($allowed === []) {
            return [];
        }
        $license = AiValueNormalizer::lowerTrimmedString($model['license'] ?? '');
        if ($license === '') {
            return [[
                'field' => 'license',
                'reason' => self::REASON_LICENSE_MISSING,
                'expected' => $allowed,
                'actual' => null,
            ]];
        }
        if (! in_array($license, $allowed, true)) {
            return [[
                'field' => 'license',
                'reason' => self::REASON_LICENSE_NOT_ALLOWED,
                'expected' => $allowed,
                'actual' => $license,
            ]];
        }

        return [];
    }
}
