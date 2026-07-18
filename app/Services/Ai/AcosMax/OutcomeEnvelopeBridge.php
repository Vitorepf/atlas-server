<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * ESP-06 — producer/consumer bridge for the shared outcome envelope.
 *
 * Default-OFF: when disabled, {@see project()} returns null and native organs stay
 * byte-identical. When enabled, thin adapters map native↔envelope with divergent
 * fields labeled by origin (anti-unification fence).
 */
final class OutcomeEnvelopeBridge
{
    public const MEASURE_ID = 'atlas.esp_06.outcome_envelope.v1';

    public const BRIDGE_SCHEMA = 'atlas.esp_06.outcome_envelope_bridge.v1';

    public const ADAPTERS_ENABLED_CONFIG_KEY = 'atlas.esp_06.outcome_envelope_adapters_enabled';

    public const DEFAULT_ADAPTERS_ENABLED = false;

    public const KIND_MEASURE_FREEZE = 'measure_freeze';

    public const FIELD_ENABLED = 'enabled';
    public const FIELD_DEV_PROCEDURAL = 'dev_procedural';
    public const FIELD_AEMOR = 'aemor';
    public const FIELD_COMPOUNDING = 'compounding';
    public const FIELD_MEASURE_ID = 'measure_id';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_PRODUCERS = 'producers';
    public const FIELD_CONSUMERS = 'consumers';
    public const FIELD_FREEZE = 'freeze';
    public const FIELD_ANTI_UNIFICATION_FENCE = 'anti_unification_fence';
    public const FIELD_FORMULA_VERSION = 'formula_version';
    public const FIELD_FORMULA = 'formula';
    public const FIELD_THRESHOLDS = 'thresholds';
    public const FIELD_TTL_DAYS = 'ttl_days';
    public const FIELD_KIND = 'kind';
    public const FIELD_ADAPTER_ORIGINS = 'adapter_origins';
    public const FIELD_AUTHOR_ENGINE_ID = 'author_engine_id';
    public const FIELD_DENOMINATOR_MIN = 'denominator_min';
    public const FIELD_DUAL_READ_REQUIRED = 'dual_read_required';
    public const FIELD_FLAG = 'flag';
    public const FIELD_FLAG_DEFAULT = 'flag_default';
    public const FIELD_JUDGE_ENGINE_ID = 'judge_engine_id';
    public const FIELD_CODEX_INDEPENDENT_ESP06_JUDGE = 'codex-independent-esp06-judge';
    public const FIELD_CURSOR_ACOS_MAX_ESP06 = 'cursor-acos-max-esp06';
    public const INT_90 = 90;

    /** @var array<string, OutcomeEnvelopeAdapter> */
    private array $adapters;

    public function __construct(
        ?DevProceduralOutcomeEnvelopeAdapter $dev = null,
        ?AemorOutcomeEnvelopeAdapter $aemor = null,
        ?CompoundingOutcomeEnvelopeAdapter $compounding = null,
    ) {
        $this->adapters = [
            self::FIELD_DEV_PROCEDURAL => $dev ?? new DevProceduralOutcomeEnvelopeAdapter,
            self::FIELD_AEMOR => $aemor ?? new AemorOutcomeEnvelopeAdapter,
            self::FIELD_COMPOUNDING => $compounding ?? new CompoundingOutcomeEnvelopeAdapter,
        ];
    }

    public static function enabled(): bool
    {
        return (AiValueNormalizer::boolOrNull(config(self::ADAPTERS_ENABLED_CONFIG_KEY, self::DEFAULT_ADAPTERS_ENABLED)) ?? self::DEFAULT_ADAPTERS_ENABLED);
    }

    /**
     * @param  array<string,mixed>  $native
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>|null validated envelope array when enabled
     */
    public function project(string $origin, array $native, array $context = []): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        return $this->adapter($origin)->toEnvelope($native, $context)->toArray();
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    public function consume(string $targetOrigin, array $envelope): array
    {
        if (! self::enabled()) {
            return [];
        }

        return $this->adapter($targetOrigin)->fromEnvelope(OutcomeEnvelope::fromArray($envelope));
    }

    /** @return array<string,mixed> */
    public function producerConsumerMeta(): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::BRIDGE_SCHEMA,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_ENABLED => self::enabled(),
            self::FIELD_PRODUCERS => array_keys($this->adapters),
            self::FIELD_CONSUMERS => array_keys($this->adapters),
            self::FIELD_ANTI_UNIFICATION_FENCE => [
                self::FIELD_DEV_PROCEDURAL => \App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevOutcomeMemoryService::class,
                self::FIELD_AEMOR => \App\Services\Ai\Aemor\AtlasAemorRuntimeService::class,
                self::FIELD_COMPOUNDING => \App\Services\Ai\Compounding\AtlasCompoundingOutcomeEvaluator::class,
            ],
            self::FIELD_FREEZE => self::freezePayload(),
        ];
    }

    /** @return array<string,mixed> */
    public static function freezePayload(): array
    {
        return [
            self::FIELD_KIND => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => OutcomeEnvelope::FORMULA_VERSION,
            self::FIELD_FORMULA => 'Outcome envelope = MULTX-03 atlas.engineering_outcome.v2 contract projected through one thin adapter per native organ (dev_procedural, aemor, compounding). Divergent native fields remain in native_divergent.fields labeled by origin — never coerced or fused.',
            self::FIELD_THRESHOLDS => [
                self::FIELD_ADAPTER_ORIGINS => OutcomeEnvelope::ADAPTER_ORIGINS,
                self::FIELD_FLAG => self::ADAPTERS_ENABLED_CONFIG_KEY,
                self::FIELD_FLAG_DEFAULT => false,
            ],
            self::FIELD_DENOMINATOR_MIN => 1,
            self::FIELD_TTL_DAYS => self::INT_90,
            self::FIELD_AUTHOR_ENGINE_ID => self::FIELD_CURSOR_ACOS_MAX_ESP06,
            self::FIELD_JUDGE_ENGINE_ID => self::FIELD_CODEX_INDEPENDENT_ESP06_JUDGE,
            self::FIELD_DUAL_READ_REQUIRED => true,
        ];
    }

    private function adapter(string $origin): OutcomeEnvelopeAdapter
    {
        $key = AiValueNormalizer::lowerTrimmedString($origin);
        if (! in_array($key, OutcomeEnvelope::ADAPTER_ORIGINS, true) || ! isset($this->adapters[$key])) {
            throw new \InvalidArgumentException('outcome_envelope_adapter_unknown:'.$key);
        }

        return $this->adapters[$key];
    }
}
