<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCircuitStateNormalizer;
use Tests\TestCase;

final class AreaFocusCircuitStateNormalizerTest extends TestCase
{
    public function test_normalizes_known_circuit_state_aliases(): void
    {
        $this->assertSame('half_open', AreaFocusCircuitStateNormalizer::fromPayload(['state' => ' half-open ']));
        $this->assertSame('half_open', AreaFocusCircuitStateNormalizer::fromPayload(['circuit_state' => 'probing']));
        $this->assertSame('open', AreaFocusCircuitStateNormalizer::fromPayload(['state' => 'tripped']));
        $this->assertSame('open', AreaFocusCircuitStateNormalizer::fromPayload(['state' => 'circuit_open']));
        $this->assertSame('closed', AreaFocusCircuitStateNormalizer::fromPayload(['state' => 'healthy']));
        $this->assertSame('closed', AreaFocusCircuitStateNormalizer::fromPayload(['state' => 'ok']));
    }

    public function test_missing_state_fails_closed_to_open_and_unknown_state_is_preserved(): void
    {
        $this->assertSame('open', AreaFocusCircuitStateNormalizer::fromPayload([]));
        $this->assertSame('paused', AreaFocusCircuitStateNormalizer::fromPayload(['state' => ' Paused ']));
    }

    public function test_nullable_from_value_preserves_provider_override_contract(): void
    {
        $this->assertSame('half_open', AreaFocusCircuitStateNormalizer::nullableFromValue(' probing '));
        $this->assertSame('open', AreaFocusCircuitStateNormalizer::nullableFromValue('tripped'));
        $this->assertSame('closed', AreaFocusCircuitStateNormalizer::nullableFromValue('healthy'));
        $this->assertNull(AreaFocusCircuitStateNormalizer::nullableFromValue(null));
        $this->assertNull(AreaFocusCircuitStateNormalizer::nullableFromValue('paused'));
        $this->assertNull(AreaFocusCircuitStateNormalizer::nullableFromValue(''));
    }

    public function test_circuit_state_in_has_priority_over_other_payload_keys(): void
    {
        $this->assertSame(
            'closed',
            AreaFocusCircuitStateNormalizer::fromPayload([
                'circuit_state_in' => 'healthy',
                'circuit_state' => 'open',
                'state' => 'half_open',
            ]),
        );
    }
}
