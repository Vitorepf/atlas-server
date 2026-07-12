<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AutonomosAutoApplyPreflightService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class AutonomosAutoApplyPreflightCommandTest extends TestCase
{
    use BootsCompoundingSchema;

    public function test_preflight_reports_four_checks_and_never_flips(): void
    {
        $this->bootCompoundingSchema();
        try {
            config(['atlas.autonomous.auto_apply.enabled' => false]);

            Artisan::call('atlas:autonomos:auto-apply-preflight', ['--json' => true]);
            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(AutonomosAutoApplyPreflightService::SCHEMA, $payload['schema']);
            $this->assertSame(4, $payload['total']);
            $this->assertCount(4, $payload['checks']);
            $this->assertSame('operator', $payload['flip_by']);
            $this->assertSame('ATLAS_AUTONOMOUS_AUTO_APPLY', $payload['flag']);
            $this->assertTrue($payload['never_flip_by_machine']);

            $ids = array_map(static fn (array $c): string => (string) $c['id'], $payload['checks']);
            $this->assertContains(AutonomosAutoApplyPreflightService::CHECK_FLAG_DEFAULT_OFF, $ids);
            $this->assertContains(AutonomosAutoApplyPreflightService::CHECK_FAIL_CLOSED_PRIVACY, $ids);
            $this->assertContains(AutonomosAutoApplyPreflightService::CHECK_REVERSAL_HANDLE, $ids);
            $this->assertContains(AutonomosAutoApplyPreflightService::CHECK_DIGEST_METRICS, $ids);
        } finally {
            $this->dropCompoundingSchema();
        }
    }

    public function test_flag_enabled_state_marks_check_red(): void
    {
        $this->bootCompoundingSchema();
        try {
            config(['atlas.autonomous.auto_apply.enabled' => true]);

            Artisan::call('atlas:autonomos:auto-apply-preflight', ['--json' => true]);
            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $flagCheck = null;
            foreach ($payload['checks'] as $check) {
                if (($check['id'] ?? '') === AutonomosAutoApplyPreflightService::CHECK_FLAG_DEFAULT_OFF) {
                    $flagCheck = $check;
                    break;
                }
            }
            $this->assertNotNull($flagCheck);
            $this->assertFalse($flagCheck['pass']);
        } finally {
            $this->dropCompoundingSchema();
        }
    }
}
