<?php

namespace Tests\Unit\Ai\Cognitive\SRL;

use App\Services\Ai\Cognitive\SRL\SRLPreferenceService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SRLPreferenceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_170000_create_srl_episodes_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('srl_preferences');
        Schema::dropIfExists('srl_episodes');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_preference_can_enable_domain_without_enabling_global(): void
    {
        $service = app(SRLPreferenceService::class);
        $service->set(true, 'programming');

        $this->assertTrue($service->resolve('programming')['enabled']);
        $this->assertFalse($service->resolve('finance')['enabled']);
    }
}
