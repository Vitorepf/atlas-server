<?php

namespace App\Services\Ai\Kernel\Decision;

use ArrayAccess;

/**
 * @implements ArrayAccess<string,mixed>
 */
final readonly class DecisionProviderSelection implements ArrayAccess
{
    /**
     * @param  array<int,string>  $fallbacks
     * @param  array<string,mixed>|null  $manualOverride
     * @param  array<string,mixed>|null  $selectionExplanation
     */
    public function __construct(
        public string $primary,
        public string $model,
        public array $fallbacks,
        public string $selectionMode,
        public string $selectionReason,
        public ?array $selectionExplanation = null,
        public ?int $confidenceScore = null,
        public ?string $confidenceBand = null,
        public ?array $manualOverride = null,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $fallbacks = array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), (array) ($payload['fallbacks'] ?? [])),
            static fn (string $value): bool => $value !== '',
        ));
        $mode = trim((string) ($payload['selection_mode'] ?? 'auto_best_allowed'));
        if (! in_array($mode, ['auto_best_allowed', 'auto_best_available', 'manual_override'], true)) {
            $mode = 'auto_best_allowed';
        }

        return new self(
            primary: trim((string) ($payload['primary'] ?? 'auto')) ?: 'auto',
            model: trim((string) ($payload['model'] ?? 'selected-by-decide')) ?: 'selected-by-decide',
            fallbacks: array_values(array_unique($fallbacks)),
            selectionMode: $mode,
            selectionReason: trim((string) ($payload['selection_reason'] ?? '')),
            selectionExplanation: is_array($payload['selection_explanation'] ?? null) ? $payload['selection_explanation'] : null,
            confidenceScore: is_numeric($payload['confidence_score'] ?? null) ? (int) $payload['confidence_score'] : null,
            confidenceBand: trim((string) ($payload['confidence_band'] ?? '')) ?: null,
            manualOverride: is_array($payload['manual_override'] ?? null) ? $payload['manual_override'] : null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'primary' => $this->primary,
            'model' => $this->model,
            'fallbacks' => $this->fallbacks,
            'selection_mode' => $this->selectionMode,
            'selection_reason' => $this->selectionReason,
            'selection_explanation' => $this->selectionExplanation,
            'confidence_score' => $this->confidenceScore,
            'confidence_band' => $this->confidenceBand,
            'manual_override' => $this->manualOverride,
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException(self::class.' is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException(self::class.' is immutable.');
    }
}
