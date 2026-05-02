<?php

namespace Tests\Unit;

use App\Services\BitaculaService;
use Tests\TestCase;

class BitaculaServiceTest extends TestCase
{
    public function test_behavior_priority_score_sanitizes_timestamp_sized_values(): void
    {
        $payload = app(BitaculaService::class)->normalizeBehaviorPayload([
            'client_id' => '65bdb8ba-c07e-4f5c-b10c-7a10babae91a',
            'name' => 'Jantar pesado tarde',
            'slug' => 'jantar_pesado_tarde',
            'category' => 'alimentacao',
            'priority_score' => 1777514536481,
        ]);

        $this->assertSame(50, $payload['priority_score']);
    }

    public function test_behavior_priority_score_preserves_valid_operational_score(): void
    {
        $payload = app(BitaculaService::class)->normalizeBehaviorPayload([
            'client_id' => '65bdb8ba-c07e-4f5c-b10c-7a10babae91a',
            'name' => 'Cafeina tarde',
            'slug' => 'cafeina_tarde',
            'category' => 'substancias',
            'priority_score' => 73,
        ]);

        $this->assertSame(73, $payload['priority_score']);
    }
}
