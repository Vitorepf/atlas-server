<?php

namespace Tests\Unit\Ai\Cognitive\SRL;

use App\Services\Ai\Cognitive\SRL\SRLPreferenceService;
use Tests\Concerns\TestsWithLedgerEvents;
use Tests\TestCase;

class SRLPreferenceServiceTest extends TestCase
{
    use TestsWithLedgerEvents;

    private const LEDGER_COMPANION_MIGRATIONS = ['2026_05_07_170000_create_srl_episodes_table.php'];

    private const LEDGER_COMPANION_TABLES = ['srl_preferences', 'srl_episodes'];

    public function test_preference_can_enable_domain_without_enabling_global(): void
    {
        $service = app(SRLPreferenceService::class);
        $service->set(true, 'programming');

        $this->assertTrue($service->resolve('programming')['enabled']);
        $this->assertFalse($service->resolve('finance')['enabled']);
    }
}
