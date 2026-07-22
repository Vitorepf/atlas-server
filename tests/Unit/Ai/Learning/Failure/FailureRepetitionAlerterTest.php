<?php

namespace Tests\Unit\Ai\Learning\Failure;

use App\Services\Ai\Learning\Failure\FailureRepetitionAlerter;
use App\Services\Ai\Learning\Failure\FailureSignatureRepository;
use Tests\Concerns\TestsWithLedgerEvents;
use Tests\TestCase;

class FailureRepetitionAlerterTest extends TestCase
{
    use TestsWithLedgerEvents;

    private const LEDGER_COMPANION_MIGRATIONS = ['2026_05_07_160000_create_failure_signatures_table.php'];

    private const LEDGER_COMPANION_TABLES = ['failure_repetition_alerts', 'failure_diversity_metrics', 'failure_signatures'];

    public function test_alerter_triggers_on_third_repeated_signature(): void
    {
        $repository = app(FailureSignatureRepository::class);
        $alerter = app(FailureRepetitionAlerter::class);
        $last = null;

        for ($i = 0; $i < 3; $i++) {
            $last = $repository->record(['domain' => 'programming', 'message' => 'provider timeout while calling model']);
        }

        $alert = $alerter->evaluate($last);

        $this->assertSame('triggered', $alert['status']);
        $this->assertSame(3, $alert['repetition_count']);
        $this->assertSame('critical', $alert['severity']);
    }
}
