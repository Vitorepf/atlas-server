<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDuplicateTargetCircuitBreaker;
use Tests\TestCase;

final class AtlasExternalBrainDuplicateTargetCircuitBreakerTest extends TestCase
{
    private function breaker(): AtlasExternalBrainDuplicateTargetCircuitBreaker
    {
        return new AtlasExternalBrainDuplicateTargetCircuitBreaker;
    }

    // ── AC: live target duplicates trip the circuit breaker ──

    public function test_live_target_duplicate_trips_breaker(): void
    {
        $result = $this->breaker()->check([
            'proposed_targets' => [
                ['target_family' => 'implementation'],
            ],
            'queued_targets' => ['implementation'],
        ]);

        $this->assertTrue($result['tripped']);
        $this->assertTrue($result['stop_before_enqueue']);
        $tripTypes = array_column($result['trips'], 'type');
        $this->assertContains('live_target_duplicate', $tripTypes);
    }

    // ── AC: intra-batch duplicates trip the circuit breaker ──

    public function test_intra_batch_duplicate_trips_breaker(): void
    {
        $result = $this->breaker()->check([
            'proposed_targets' => [
                ['target_family' => 'refactor'],
                ['target_family' => 'refactor'],
            ],
            'queued_targets' => [],
        ]);

        $this->assertTrue($result['tripped']);
        $tripTypes = array_column($result['trips'], 'type');
        $this->assertContains('intra_batch_duplicate', $tripTypes);
    }

    // ── AC: implementation/test swapped duplicates trip the circuit breaker ──

    public function test_impl_test_swapped_duplicate_trips_breaker(): void
    {
        $result = $this->breaker()->check([
            'proposed_targets' => [
                [
                    'target_family' => 'target_a',
                    'allowed_files' => ['tests/Unit/FooTest.php'],
                ],
                [
                    'target_family' => 'target_b',
                    'allowed_files' => ['app/Services/Foo.php'],
                ],
            ],
            'queued_targets' => [],
        ]);

        $this->assertTrue($result['tripped']);
        $tripTypes = array_column($result['trips'], 'type');
        $this->assertContains('implementation_test_swapped_duplicate', $tripTypes);
    }

    // ── no duplicates → no trip ──

    public function test_no_duplicates_does_not_trip(): void
    {
        $result = $this->breaker()->check([
            'proposed_targets' => [
                ['target_family' => 'a'],
                ['target_family' => 'b'],
            ],
            'queued_targets' => ['c'],
        ]);

        $this->assertFalse($result['tripped']);
        $this->assertFalse($result['stop_before_enqueue']);
        $this->assertSame([], $result['trips']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->breaker()->check([]);

        $this->assertSame(AtlasExternalBrainDuplicateTargetCircuitBreaker::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('tripped', $result);
        $this->assertArrayHasKey('trips', $result);
        $this->assertArrayHasKey('stop_before_enqueue', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'proposed_targets' => [
                ['target_family' => 'a'],
                ['target_family' => 'a'],
            ],
            'queued_targets' => [],
        ];

        $a = $this->breaker()->check($input);
        $b = $this->breaker()->check($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
