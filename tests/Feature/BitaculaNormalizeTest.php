<?php

namespace Tests\Feature;

use Tests\TestCase;

class BitaculaNormalizeTest extends TestCase
{
    public function test_normalize_endpoint_returns_canonical_suggestion(): void
    {
        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/bitacula/normalize', ['text' => 'tereré depois das 16 horas'])
            ->assertOk()
            ->assertJsonPath('suggestions.0.id', 'caffeine_late')
            ->assertJsonPath('suggestions.0.category', 'substancias')
            ->assertJsonPath('suggestions.0.parent_factor', 'caffeine')
            ->assertJsonPath('suggestions.0.factor_condition', 'after_14h');
    }
}
