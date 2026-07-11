<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckRegistry;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class AtlasWatchdogFrameworkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateLedger();
        $this->app->instance(AtlasWatchdogCheckRegistry::class, new AtlasWatchdogCheckRegistry);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_registered_check_runs_alerts_and_records_aggregate_to_ledger(): void
    {
        $registry = app(AtlasWatchdogCheckRegistry::class);
        $registry->register(new AlertingWatchdogCheck);

        $this->assertSame(Command::FAILURE, Artisan::call('atlas:watchdog:run', ['--json' => true]));

        $payload = json_decode($this->artisanOutput(), true);
        $this->assertIsArray($payload);
        $this->assertSame('atlas.acos.watchdog_run.v1', $payload['schema_version']);
        $this->assertSame('alert', $payload['status']);
        $this->assertTrue($payload['alert']);
        $this->assertSame(['test.alerting'], array_column($payload['checks'], 'id'));
        $this->assertSame('alert', $payload['checks'][0]['status']);
        $this->assertSame('threshold_breached', $payload['checks'][0]['alert']['code'] ?? null);

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::WatchdogRunRecorded->value)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('acos_watchdog', $event->scope_type);
        $this->assertSame('unified', $event->scope_id);
        $this->assertSame('atlas.acos.watchdog_run.v1', data_get($event->payload, 'schema_version'));
        $this->assertSame('test.alerting', data_get($event->payload, 'checks.0.id'));
    }

    public function test_throwing_check_does_not_kill_other_checks(): void
    {
        $registry = app(AtlasWatchdogCheckRegistry::class);
        $registry->register(new ThrowingWatchdogCheck);
        $registry->register(new HealthyWatchdogCheck);

        $this->assertSame(Command::FAILURE, Artisan::call('atlas:watchdog:run', ['--json' => true]));

        $payload = json_decode($this->artisanOutput(), true);
        $this->assertIsArray($payload);
        $this->assertSame(['test.throwing', 'test.healthy'], array_column($payload['checks'], 'id'));
        $this->assertSame('error', $payload['checks'][0]['status']);
        $this->assertSame('RuntimeException', $payload['checks'][0]['evidence']['exception_class'] ?? null);
        $this->assertSame('ok', $payload['checks'][1]['status']);
        $this->assertSame('still ran', $payload['checks'][1]['evidence']['note'] ?? null);
    }

    public function test_aggregated_json_lists_registered_checks_when_healthy(): void
    {
        $registry = app(AtlasWatchdogCheckRegistry::class);
        $registry->register(new HealthyWatchdogCheck);

        $this->assertSame(Command::SUCCESS, Artisan::call('atlas:watchdog:run', ['--json' => true]));

        $payload = json_decode($this->artisanOutput(), true);
        $this->assertIsArray($payload);
        $this->assertSame('healthy', $payload['status']);
        $this->assertFalse($payload['alert']);
        $this->assertSame(1, $payload['counts']['total']);
        $this->assertSame(['test.healthy'], array_column($payload['checks'], 'id'));
    }

    private function artisanOutput(): string
    {
        return trim(Artisan::output());
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
    }
}

final class AlertingWatchdogCheck implements AtlasWatchdogCheck
{
    public function id(): string
    {
        return 'test.alerting';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        return AtlasWatchdogCheckResult::alert(
            evidence: ['observed' => 9, 'threshold' => 3],
            alert: ['code' => 'threshold_breached', 'message' => 'fixture alert'],
        );
    }
}

final class ThrowingWatchdogCheck implements AtlasWatchdogCheck
{
    public function id(): string
    {
        return 'test.throwing';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        throw new RuntimeException('boom');
    }
}

final class HealthyWatchdogCheck implements AtlasWatchdogCheck
{
    public function id(): string
    {
        return 'test.healthy';
    }

    public function run(): AtlasWatchdogCheckResult
    {
        return AtlasWatchdogCheckResult::ok(['note' => 'still ran']);
    }
}
