<?php

namespace Tests\Feature\Marketing;

use Tests\TestCase;

/**
 * Locks the moat CLI: ingredient → coinable tokens + the fabrication plan that pre-owns the search the
 * advertorial will fabricate. Provider-free; no DB.
 */
class AtlasAiMarketingKeywordMoatCommandTest extends TestCase
{
    public function test_outputs_tokens_and_fabrication_plan(): void
    {
        $this->artisan('atlas:ai:marketing:keyword-moat', [
            '--ingredient' => ['gelatin'],
            '--category' => ['weight loss'],
            '--form' => ['drops'],
        ])
            ->expectsOutputToContain('TOKENS COINÁVEIS')
            ->expectsOutputToContain('gelatin diet')
            ->expectsOutputToContain('POSSE-no-instante')
            ->assertSuccessful();
    }

    public function test_json_mode_and_requires_ingredient(): void
    {
        $this->artisan('atlas:ai:marketing:keyword-moat', ['--ingredient' => ['blue salt'], '--json' => true])
            ->assertSuccessful();
        $this->artisan('atlas:ai:marketing:keyword-moat')->assertFailed(); // sem ingrediente → falha clara
    }
}
