<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\Governance\GovernanceAmendmentLedger;
use App\Services\Ai\Governance\GovernanceFloorRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasGovernanceAmendmentsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->removeLedger();
    }

    protected function tearDown(): void
    {
        $this->removeLedger();
        parent::tearDown();
    }

    public function test_command_lists_governance_amendment_history_as_json(): void
    {
        $result = app(GovernanceFloorRegistry::class)->amend([
            'autonomy_ladder.trust_threshold' => 0.96,
        ], [
            'proposal_id' => 'MAXK-07-command-test',
            'actor' => 'phpunit',
            'at' => '2026-07-12T04:10:00+00:00',
            'labels' => [],
            'rollback_predeclared' => [
                'id' => 'ROL-01',
                'command' => 'php artisan atlas:governance:amendments --json',
            ],
        ]);

        $this->assertSame('accepted', $result['status']);

        $exitCode = Artisan::call('atlas:governance:amendments', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "ok"', $output);
        $this->assertStringContainsString('"proposal_id": "MAXK-07-command-test"', $output);
        $this->assertStringContainsString('"rollback_predeclared"', $output);
    }

    private function removeLedger(): void
    {
        $path = (new GovernanceAmendmentLedger)->path();
        if (is_file($path)) {
            unlink($path);
        }
    }
}
