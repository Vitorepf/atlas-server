<?php

namespace App\Support;

use Illuminate\Validation\Validator;

class HealthMetricIntegrity
{
    public const PASSIVE_SOURCES = ['healthkit', 'rize', 'manual'];

    private const MANUAL_BODY_SIGNAL_TYPES = [
        'height',
        'waist_circumference',
        'body_mass',
        'body_fat_percentage',
        'lean_body_mass',
        'body_mass_index',
        'muscle_mass_percentage',
        'skeletal_muscle_percentage',
        'body_muscle_percentage',
    ];

    public static function snapshotMetricRules(string $presence): array
    {
        return [
            'body_mass_kg' => [$presence, 'nullable', 'numeric', 'between:20,350'],
            'body_fat_percentage' => [$presence, 'nullable', 'numeric', 'between:3,75'],
            'lean_body_mass_kg' => [$presence, 'nullable', 'numeric', 'between:10,250'],
            'muscle_mass_percentage' => [$presence, 'nullable', 'numeric', 'between:15,95'],
            'body_mass_index' => [$presence, 'nullable', 'numeric', 'between:8,90'],
            'waist_circumference_cm' => [$presence, 'nullable', 'numeric', 'between:30,250'],
        ];
    }

    public static function passiveSignalError(array $signal): ?string
    {
        $source = $signal['source'] ?? null;
        $type = $signal['signal_type'] ?? null;

        if ($source === 'manual' && (! is_string($type) || ! in_array($type, self::MANUAL_BODY_SIGNAL_TYPES, true))) {
            return 'Manual passive signals are restricted to body-composition metrics.';
        }

        $isDeleted = array_key_exists('deleted_at', $signal) && $signal['deleted_at'] !== null && $signal['deleted_at'] !== '';
        if ($source === 'manual' && ! $isDeleted && (! array_key_exists('value_numeric', $signal) || $signal['value_numeric'] === null || $signal['value_numeric'] === '')) {
            return 'Manual body-composition signals require a numeric value.';
        }

        if (! is_string($type) || ! array_key_exists('value_numeric', $signal) || $signal['value_numeric'] === null || $signal['value_numeric'] === '') {
            return null;
        }

        $value = filter_var($signal['value_numeric'], FILTER_VALIDATE_FLOAT);
        if ($value === false || ! is_finite((float) $value)) {
            return null;
        }

        $unit = is_string($signal['unit'] ?? null) ? $signal['unit'] : null;
        $normalized = self::normalizePassiveBodyValue($type, (float) $value, $unit);

        if ($normalized === null) {
            return "Invalid body-composition value for {$type}.";
        }

        return null;
    }

    public static function validatePassiveSignal(Validator $validator, array $signal, string $path = ''): void
    {
        $error = self::passiveSignalError($signal);
        if ($error === null) {
            return;
        }

        $field = $path === '' ? 'value_numeric' : "{$path}.value_numeric";
        $validator->errors()->add($field, $error);
    }

    private static function normalizePassiveBodyValue(string $type, float $value, ?string $unit): ?float
    {
        $unit = self::normalizeUnit($unit);

        return match ($type) {
            'height' => self::unitIn($unit, ['m', 'cm'])
                ? self::range($unit === 'cm' || ($unit === null && $value > 3) ? $value / 100 : $value, 0.5, 2.5)
                : null,
            'waist_circumference' => self::unitIn($unit, ['m', 'cm'])
                ? self::range($unit === 'm' || ($unit === null && $value <= 3) ? $value * 100 : $value, 30, 250)
                : null,
            'body_mass' => self::unitIn($unit, ['kg']) ? self::range($value, 20, 350) : null,
            'body_fat_percentage' => self::unitIn($unit, ['%', 'count'])
                ? self::range(abs($value) <= 1 ? $value * 100 : $value, 3, 75)
                : null,
            'lean_body_mass' => self::unitIn($unit, ['kg']) ? self::range($value, 10, 250) : null,
            'body_mass_index' => self::unitIn($unit, ['count']) ? self::range($value, 8, 90) : null,
            'muscle_mass_percentage', 'skeletal_muscle_percentage', 'body_muscle_percentage' => self::unitIn($unit, ['%', 'count'])
                ? self::range(abs($value) <= 1 ? $value * 100 : $value, 15, 95)
                : null,
            default => 0.0,
        };
    }

    private static function normalizeUnit(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $unit = trim($unit);

        return $unit === '' ? null : $unit;
    }

    private static function unitIn(?string $unit, array $allowed): bool
    {
        return $unit === null || in_array($unit, $allowed, true);
    }

    private static function range(float $value, float $min, float $max): ?float
    {
        return $value >= $min && $value <= $max ? $value : null;
    }
}
