<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AreaFocusCandidateQuarantineServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_candidate_quarantine_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AreaFocusCandidateQuarantineService
    {
        $service = app(AreaFocusCandidateQuarantineService::class);
        $service->setStorageRootForTesting($this->tmp);

        return $service;
    }

    public function test_routing_not_executable_quarantine_is_retryable_not_permanent(): void
    {
        $service = $this->service();

        $entry = $service->appendFromCycle(
            'agentic_engineering_os',
            'dev_forge',
            ['finding_id' => 'find_retry', 'title' => 'Retry candidate'],
            ['owner_runtime_routing_not_executable'],
        );

        $this->assertNotSame('permanent', $entry['retry_after']);
        $this->assertArrayHasKey('find_retry', $service->quarantinedFindingKeys('agentic_engineering_os', 'dev_forge'));
    }

    public function test_expired_routing_quarantine_no_longer_locks_candidate(): void
    {
        $service = $this->service();
        $path = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode([
            'schema_version' => AreaFocusCandidateQuarantineService::SCHEMA,
            'finding_id' => 'find_expired',
            'finding_hash' => 'sha256:expired',
            'title' => 'Expired routing candidate',
            'blocker' => 'owner_runtime_routing_not_executable',
            'retry_after' => $this->timestamp('-1 minute'),
            'recorded_at' => $this->timestamp('-30 minutes'),
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->assertArrayNotHasKey('find_expired', $service->quarantinedFindingKeys('agentic_engineering_os', 'dev_forge'));
    }

    public function test_legacy_permanent_routing_quarantine_expires_after_retry_window(): void
    {
        $service = $this->service();
        $path = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode([
            'schema_version' => AreaFocusCandidateQuarantineService::SCHEMA,
            'finding_id' => 'find_legacy',
            'finding_hash' => 'sha256:legacy',
            'title' => 'Legacy routing candidate',
            'blocker' => 'owner_runtime_routing_not_executable',
            'retry_after' => 'permanent',
            'recorded_at' => $this->timestamp('-30 minutes'),
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->assertArrayNotHasKey('find_legacy', $service->quarantinedFindingKeys('agentic_engineering_os', 'dev_forge'));
    }

    public function test_permanent_no_patch_needed_quarantine_remains_active(): void
    {
        $service = $this->service();
        $path = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode([
            'schema_version' => AreaFocusCandidateQuarantineService::SCHEMA,
            'finding_id' => 'find_no_patch',
            'finding_hash' => 'sha256:no-patch',
            'title' => 'No patch candidate',
            'blocker' => 'owner_runtime_no_patch_needed',
            'retry_after' => 'permanent',
            'recorded_at' => $this->timestamp('-30 minutes'),
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->assertArrayHasKey('find_no_patch', $service->quarantinedFindingKeys('agentic_engineering_os', 'dev_forge'));
    }

    public function test_transient_provider_timeout_never_quarantines_even_with_not_passed(): void
    {
        $service = $this->service();

        // A provider timeout co-occurs with senior_loop_execution_not_passed (a
        // permanent blocker), but the timeout means the attempt produced no real
        // verdict — it must be retried, never permanently quarantined. This is
        // the exact pattern that starved AP-790 into synthetic recovery work.
        $this->assertFalse($service->shouldQuarantine([
            'owner_runtime_provider_timeout',
            'owner_runtime_senior_loop_execution_not_passed',
        ]));
        $this->assertTrue($service->hasTransientBlocker(['owner_runtime_provider_timeout']));

        // A genuine senior-loop failure with no transient infra blocker still
        // quarantines as before.
        $this->assertTrue($service->shouldQuarantine([
            'owner_runtime_senior_loop_execution_not_passed',
        ]));
    }

    private function timestamp(string $modifier): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify($modifier)
            ->format(DateTimeInterface::ATOM);
    }
}
