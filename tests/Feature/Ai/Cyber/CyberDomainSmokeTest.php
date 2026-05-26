<?php

namespace Tests\Feature\Ai\Cyber;

use App\Models\AiAppSecReview;
use App\Models\AiBugBountyIntake;
use App\Models\AiCyberEngagement;
use App\Models\AiCyberEvidenceChainEntry;
use App\Models\AiDefensiveSecurityReview;
use App\Services\Ai\Cyber\AuthorizedBugBountyIntakeService;
use App\Services\Ai\Cyber\CyberEngagementIntakeService;
use App\Services\Ai\Cyber\CyberEvidenceChainService;
use Tests\Concerns\CreatesCyberRuntimeTables;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\TestCase;

class CyberDomainSmokeTest extends TestCase
{
    use CreatesCyberRuntimeTables;
    use CreatesEvidenceRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEvidenceRuntimeTables();
        $this->createCyberRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropCyberRuntimeTables();
        $this->dropEvidenceRuntimeTables();
        parent::tearDown();
    }

    public function test_smoke_runs_full_defensive_chain_and_passes_certification(): void
    {
        $exit = $this->artisan('atlas:ai:cyber-domain', [
            '--action' => 'smoke',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);

        $this->assertGreaterThan(0, AiCyberEngagement::query()->count());
        $this->assertGreaterThan(0, AiAppSecReview::query()->count());
        $this->assertGreaterThan(0, AiDefensiveSecurityReview::query()->count());
        $this->assertGreaterThan(0, AiBugBountyIntake::query()->count());
        $this->assertGreaterThan(0, AiCyberEvidenceChainEntry::query()->count());

        $engagement = AiCyberEngagement::query()->latest('created_at')->first();
        $this->assertSame(CyberEngagementIntakeService::STATUS_AUTHORIZED, $engagement->status);
        $verification = app(CyberEvidenceChainService::class)->verify($engagement);
        $this->assertTrue($verification['integrity_ok']);
        $this->assertSame(8, $verification['entry_count']);

        $bounty = AiBugBountyIntake::query()->latest('created_at')->first();
        $this->assertSame(AuthorizedBugBountyIntakeService::STATUS_AUTHORIZED, $bounty->status);
    }
}
