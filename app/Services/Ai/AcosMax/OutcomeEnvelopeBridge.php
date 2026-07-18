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
            'kind' => self::KIND_MEASURE_FREEZE,
            self::FIELD_MEASURE_ID => self::MEASURE_ID,
            self::FIELD_FORMULA_VERSION => OutcomeEnvelope::FORMULA_VERSION,
            'formula' => 'Outcome envelope = MULTX-03 atlas.engineering_outcome.v2 contract projected through one thin adapter per native organ (dev_procedural, aemor, compounding). Divergent native fields remain in native_divergent.fields labeled by origin — never coerced or fused.',
            'thresholds' => [
                'adapter_origins' => OutcomeEnvelope::ADAPTER_ORIGINS,
                'flag' => self::ADAPTERS_ENABLED_CONFIG_KEY,
                'flag_default' => false,
            ],
            'denominator_min' => 1,
            'ttl_days' => 90,
            'author_engine_id' => 'cursor-acos-max-esp06',
            'judge_engine_id' => 'codex-independent-esp06-judge',
            'dual_read_required' => true,
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
