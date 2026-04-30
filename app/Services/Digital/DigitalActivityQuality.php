<?php

namespace App\Services\Digital;

class DigitalActivityQuality
{
    private const CATEGORY_FIELDS = [
        'curated_input_min',
        'algorithmic_input_min',
        'intentional_entertainment_min',
        'default_entertainment_min',
        'communication_primary_min',
        'communication_shallow_min',
        'market_min',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function enrichPayload(array $payload): array
    {
        $metadata = $this->arrayValue($payload['metadata'] ?? []) ?? [];
        $total = $this->numberValue($payload['total_screen_time_min'] ?? null);
        $classifiedMinutes = $this->classifiedMinutes($payload);
        $classificationRatio = $total !== null && $total > 0
            ? round(min(1, $classifiedMinutes / $total), 3)
            : null;
        $capabilities = $this->capabilities($payload, $classificationRatio);
        $warnings = array_values(array_unique(array_merge(
            $this->stringArray(data_get($metadata, 'quality.warnings')),
            $this->warnings($payload, $classificationRatio, $capabilities),
        )));
        $score = $this->score($payload, $classificationRatio, $capabilities, $warnings);

        $metadata['coverage'] = array_merge(
            $this->arrayValue($metadata['coverage'] ?? []) ?? [],
            [
                'total_minutes' => $total,
                'classified_minutes' => $classifiedMinutes,
                'classification_ratio' => $classificationRatio,
            ],
        );
        $metadata['capabilities'] = array_merge(
            $this->arrayValue($metadata['capabilities'] ?? []) ?? [],
            $capabilities,
        );
        $metadata['quality'] = array_merge(
            $this->arrayValue($metadata['quality'] ?? []) ?? [],
            [
                'score' => $score,
                'status' => $score >= 80 ? 'high' : ($score >= 50 ? 'partial' : 'low'),
                'has_native_iphone_source' => $this->hasNativeIphoneSource($payload, $metadata),
                'warnings' => $warnings,
            ],
        );

        $payload['metadata'] = $metadata;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function consistencyErrors(array $payload): array
    {
        $errors = [];
        $received = $this->numberValue($payload['notifications_received'] ?? null);
        $actioned = $this->numberValue($payload['notifications_actioned'] ?? null);
        $total = $this->numberValue($payload['total_screen_time_min'] ?? null);
        $classifiedMinutes = $this->classifiedMinutes($payload);
        $firstUse = $this->numberValue($payload['first_offensive_use_min_after_wake'] ?? null);

        if ($received !== null && $actioned !== null && $actioned > $received) {
            $errors['notifications_actioned'] = 'Nao pode ser maior que notificacoes recebidas.';
        }

        if ($total !== null && $total > 0 && $classifiedMinutes > max($total + 5, (int) ceil($total * 1.15))) {
            $errors['category_breakdown'] = 'A soma das categorias digitais excede o tempo total medido.';
        }

        if ($firstUse !== null && $firstUse > 24 * 60) {
            $errors['first_offensive_use_min_after_wake'] = 'Primeiro uso apos acordar deve ficar dentro da janela do dia.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function classifiedMinutes(array $payload): int
    {
        return array_reduce(
            self::CATEGORY_FIELDS,
            fn (int $sum, string $field): int => $sum + max(0, (int) ($this->numberValue($payload[$field] ?? null) ?? 0)),
            0,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, bool>
     */
    private function capabilities(array $payload, ?float $classificationRatio): array
    {
        return [
            'screen_time' => $this->numberValue($payload['total_screen_time_min'] ?? null) !== null,
            'category_classification' => $classificationRatio !== null && $classificationRatio > 0,
            'pickups' => $this->numberValue($payload['pickups_count'] ?? null) !== null,
            'notifications' => $this->numberValue($payload['notifications_received'] ?? null) !== null
                || $this->numberValue($payload['notifications_actioned'] ?? null) !== null,
            'first_use_after_wake' => $this->numberValue($payload['first_offensive_use_min_after_wake'] ?? null) !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, bool>  $capabilities
     * @return array<int, string>
     */
    private function warnings(array $payload, ?float $classificationRatio, array $capabilities): array
    {
        $warnings = [];
        $total = $this->numberValue($payload['total_screen_time_min'] ?? null);
        $signalCount = $this->numberValue($payload['signal_count'] ?? null);
        $metadata = $this->arrayValue($payload['metadata'] ?? []) ?? [];

        if (($signalCount ?? 0) <= 0) {
            $warnings[] = 'no_digital_sessions';
        }

        if ($total === null) {
            $warnings[] = 'no_screen_time_total';
        }

        if ($total !== null && $total > 0 && ($classificationRatio ?? 0) <= 0) {
            $warnings[] = 'no_category_classification';
        } elseif (($classificationRatio ?? 1) < 0.8) {
            $warnings[] = 'partial_category_classification';
        }

        if (! $this->hasNativeIphoneSource($payload, $metadata)) {
            $warnings[] = 'native_iphone_source_absent';
        }

        if (! $capabilities['pickups'] || ! $capabilities['notifications'] || ! $capabilities['first_use_after_wake']) {
            $warnings[] = 'iphone_interruptions_incomplete';
        }

        foreach (array_keys($this->consistencyErrors($payload)) as $field) {
            $warnings[] = 'invalid_'.$field;
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, bool>  $capabilities
     * @param  array<int, string>  $warnings
     */
    private function score(array $payload, ?float $classificationRatio, array $capabilities, array $warnings): int
    {
        $total = $this->numberValue($payload['total_screen_time_min'] ?? null);
        $metadata = $this->arrayValue($payload['metadata'] ?? []) ?? [];
        $native = $this->hasNativeIphoneSource($payload, $metadata);
        $interruptions = ($capabilities['pickups'] ? 1 : 0)
            + ($capabilities['notifications'] ? 1 : 0)
            + ($capabilities['first_use_after_wake'] ? 1 : 0);

        $score = ($total !== null ? 25 : 0)
            + (($classificationRatio ?? 0) * 35)
            + ($native ? 25 : 0)
            + (($interruptions / 3) * 15);

        if (! $native) {
            $score = min($score, 72);
        }

        if (($classificationRatio ?? 0) <= 0) {
            $score = min($score, 55);
        }

        if (array_filter($warnings, fn (string $warning): bool => str_starts_with($warning, 'invalid_'))) {
            $score = min($score, 40);
        }

        return (int) round(max(0, min(100, $score)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    private function hasNativeIphoneSource(array $payload, array $metadata): bool
    {
        return ($payload['source'] ?? null) === 'screentime'
            && (
                data_get($metadata, 'quality.has_native_iphone_source') === true
                || data_get($metadata, 'native.source') === 'ios_screentime'
                || data_get($metadata, 'native.entitlement') === 'family_controls'
            );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayValue(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function numberValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<int, string>
     */
    private function stringArray(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && trim($item) !== ''))
            : [];
    }
}
