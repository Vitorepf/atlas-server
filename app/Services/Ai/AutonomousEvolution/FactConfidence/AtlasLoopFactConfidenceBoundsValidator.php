<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FactConfidence;

/**
 * Concrete FACT confidence-bounds validator.
 *
 * Default OFF (atlas.loop.fact_confidence.enforce=false): pétreo no-op, byte-identical to
 * not having the validator wired at all.
 *
 * When ON:
 *   - Critical path (listed in atlas.loop.fact_confidence.critical_paths) + missing envelope
 *       → throws AtlasLoopFactBoundsMissingException
 *   - Non-critical path + missing envelope → emits a structured warning to the Loop logger
 *   - Envelope present → silently accepts
 */
final class AtlasLoopFactConfidenceBoundsValidator implements AtlasLoopFactConfidenceBoundsValidatorInterface
{
    public const DEFAULT_CRITICAL_PATHS = [
        'loop.comprehend.snapshot_writer',
        'loop.next_work_decider',
    ];

    public const ENVELOPE_KEY = 'confidence_bounds';

    /** @var callable(string,array<string,mixed>):void */
    private $logger;

    /** @var callable():array<string,mixed> */
    private $configReader;

    /**
     * @param  callable(string,array<string,mixed>):void|null  $logger        warning emitter; defaults to Laravel Log::warning
     * @param  callable():array<string,mixed>|null              $configReader  returns the atlas.loop.fact_confidence config block
     */
    public function __construct(?callable $logger = null, ?callable $configReader = null)
    {
        $this->logger = $logger ?? static function (string $message, array $context): void {
            if (function_exists('logger')) {
                /** @phpstan-ignore-next-line */
                \Illuminate\Support\Facades\Log::warning($message, $context);
            }
        };
        $this->configReader = $configReader ?? static function (): array {
            if (function_exists('config')) {
                $cfg = (array) config('atlas.loop.fact_confidence', []);
                return $cfg;
            }

            return [];
        };
    }

    public function validate(string $emissionPath, mixed $fact): void
    {
        $cfg = ($this->configReader)();
        if (! (bool) ($cfg['enforce'] ?? false)) {
            return; // byte-identical OFF
        }

        if ($this->hasEnvelope($fact)) {
            return;
        }

        $criticalPaths = (array) ($cfg['critical_paths'] ?? self::DEFAULT_CRITICAL_PATHS);
        $isCritical = in_array($emissionPath, $criticalPaths, true);

        if ($isCritical) {
            throw new AtlasLoopFactBoundsMissingException(
                criticalPath: $emissionPath,
                detail: 'missing_confidence_bounds_envelope',
            );
        }

        ($this->logger)(
            'atlas.loop.fact_confidence.bounds_missing',
            [
                'emission_path' => $emissionPath,
                'fact_type' => get_debug_type($fact),
                'reason' => 'missing_confidence_bounds_envelope',
            ],
        );
    }

    private function hasEnvelope(mixed $fact): bool
    {
        if (! is_array($fact)) {
            return false;
        }
        if (! array_key_exists(self::ENVELOPE_KEY, $fact)) {
            return false;
        }
        $envelope = $fact[self::ENVELOPE_KEY];

        return is_array($envelope) && array_key_exists('sample_size', $envelope) && array_key_exists('source_count', $envelope);
    }
}
