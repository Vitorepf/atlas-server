<?php

namespace Tests\Unit;

use App\Support\HealthMetricIntegrity;
use PHPUnit\Framework\TestCase;

class HealthMetricIntegrityTest extends TestCase
{
    public function test_accepts_manual_body_measurements_with_stable_metric_units(): void
    {
        $this->assertNull(HealthMetricIntegrity::passiveSignalError([
            'source' => 'manual',
            'signal_type' => 'height',
            'value_numeric' => 1.79,
            'unit' => 'm',
        ]));

        $this->assertNull(HealthMetricIntegrity::passiveSignalError([
            'source' => 'manual',
            'signal_type' => 'waist_circumference',
            'value_numeric' => 82,
            'unit' => 'cm',
        ]));
    }

    public function test_accepts_fractional_and_percent_body_fat_payloads(): void
    {
        $this->assertNull(HealthMetricIntegrity::passiveSignalError([
            'source' => 'healthkit',
            'signal_type' => 'body_fat_percentage',
            'value_numeric' => 0.202,
            'unit' => '%',
        ]));

        $this->assertNull(HealthMetricIntegrity::passiveSignalError([
            'source' => 'healthkit',
            'signal_type' => 'body_fat_percentage',
            'value_numeric' => 20.2,
            'unit' => '%',
        ]));
    }

    public function test_restricts_manual_passive_signals_to_body_composition_metrics(): void
    {
        $this->assertSame(
            'Manual passive signals are restricted to body-composition metrics.',
            HealthMetricIntegrity::passiveSignalError([
                'source' => 'manual',
                'signal_type' => 'resting_heart_rate_bpm',
                'value_numeric' => 52,
                'unit' => 'count/min',
            ]),
        );
    }

    public function test_requires_numeric_values_for_manual_body_composition_signals(): void
    {
        $this->assertSame(
            'Manual body-composition signals require a numeric value.',
            HealthMetricIntegrity::passiveSignalError([
                'source' => 'manual',
                'signal_type' => 'height',
                'value_numeric' => null,
                'unit' => 'm',
            ]),
        );
    }

    public function test_rejects_implausible_body_composition_values(): void
    {
        $this->assertSame(
            'Invalid body-composition value for height.',
            HealthMetricIntegrity::passiveSignalError([
                'source' => 'manual',
                'signal_type' => 'height',
                'value_numeric' => 4.1,
                'unit' => 'm',
            ]),
        );

        $this->assertSame(
            'Invalid body-composition value for waist_circumference.',
            HealthMetricIntegrity::passiveSignalError([
                'source' => 'manual',
                'signal_type' => 'waist_circumference',
                'value_numeric' => 400,
                'unit' => 'cm',
            ]),
        );
    }

    public function test_rejects_body_composition_values_with_wrong_units(): void
    {
        $this->assertSame(
            'Invalid body-composition value for body_mass.',
            HealthMetricIntegrity::passiveSignalError([
                'source' => 'manual',
                'signal_type' => 'body_mass',
                'value_numeric' => 154,
                'unit' => 'lb',
            ]),
        );
    }
}
