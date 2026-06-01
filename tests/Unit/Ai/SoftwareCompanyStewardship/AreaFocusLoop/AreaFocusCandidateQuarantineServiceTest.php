<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FinalDeliveryQualityGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\InertNewClassDeliveryGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ZeroProviderPreflightGate;
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

    public function test_preflight_not_autonomously_testable_is_quarantined_before_reselection(): void
    {
        $service = $this->service();
        $blocker = ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE;

        $this->assertTrue($service->shouldQuarantine([$blocker]));

        $policy = $service->repairPolicyForBlockers([$blocker]);
        $this->assertSame('quarantine_continue', $policy['action']);
        $this->assertSame(0, $policy['max_retries']);
        $this->assertSame('test_subject_not_autonomously_testable', $policy['reason']);

        $entry = $service->appendFromCycle(
            'agentic_engineering_os',
            'dev_forge',
            ['finding_id' => 'find_untestable_test', 'kind' => 'test', 'title' => 'Add focused unit coverage for untestable subject'],
            [$blocker],
        );

        $this->assertSame('permanent', $entry['retry_after']);
        $this->assertArrayHasKey('find_untestable_test', $service->quarantinedFindingKeys('agentic_engineering_os', 'dev_forge'));
    }

    public function test_large_existing_runtime_surface_preflight_block_is_quarantined_before_reselection(): void
    {
        $service = $this->service();
        $blocker = ZeroProviderPreflightGate::REASON_LARGE_EXISTING_RUNTIME_SURFACE_NEEDS_NARROWER_SLICE;

        $this->assertTrue($service->shouldQuarantine([$blocker]));

        $policy = $service->repairPolicyForBlockers([$blocker]);
        $this->assertSame('quarantine_continue', $policy['action']);
        $this->assertSame(0, $policy['max_retries']);
        $this->assertFalse($policy['emit_failure_capsule']);
        $this->assertSame('large_existing_runtime_surface_needs_narrower_slice', $policy['reason']);
    }

    public function test_existing_runtime_surface_without_structured_anchor_is_quarantined_before_reselection(): void
    {
        $service = $this->service();
        $blocker = ZeroProviderPreflightGate::REASON_EXISTING_RUNTIME_SURFACE_NEEDS_STRUCTURED_ANCHOR;

        $this->assertTrue($service->shouldQuarantine([$blocker]));

        $policy = $service->repairPolicyForBlockers([$blocker]);
        $this->assertSame('quarantine_continue', $policy['action']);
        $this->assertSame(0, $policy['max_retries']);
        $this->assertFalse($policy['emit_failure_capsule']);
        $this->assertSame('existing_runtime_surface_needs_structured_anchor', $policy['reason']);
    }

    public function test_non_retryable_delivery_and_no_code_failures_are_quarantined_before_reselection(): void
    {
        $service = $this->service();

        foreach ([
            FinalDeliveryQualityGateService::BLOCKER,
            'owner_runtime_minimax_no_code_extracted',
            ZeroProviderPreflightGate::REASON_PRIOR_NON_RETRYABLE_FAILURE_PATTERN,
            'owner_runtime_repeated_repair_no_progress',
            'owner_runtime_php_syntax_error_after_max_repairs',
            'provider_diff_quality_gate_failed',
        ] as $blocker) {
            $this->assertTrue($service->shouldQuarantine([$blocker]));
            $policy = $service->repairPolicyForBlockers([$blocker]);

            $this->assertSame('quarantine_continue', $policy['action']);
            $this->assertSame(0, $policy['max_retries']);
            $this->assertTrue($policy['emit_failure_capsule']);
        }
    }

    public function test_inert_new_class_delivery_blocker_is_quarantined_not_spun(): void
    {
        $service = $this->service();

        foreach ([
            InertNewClassDeliveryGate::BLOCKER,
            'owner_runtime_'.InertNewClassDeliveryGate::BLOCKER,
        ] as $blocker) {
            // Spin-loop guard: an inert-delivery block must quarantine, never fall
            // through to action=none (which would re-select + re-block the finding
            // every cycle and burn provider budget).
            $this->assertTrue($service->shouldQuarantine([$blocker]));
            $policy = $service->repairPolicyForBlockers([$blocker]);
            $this->assertSame('quarantine_continue', $policy['action']);
            $this->assertSame('inert_new_class_not_runtime_wired', $policy['reason']);
            $this->assertNotSame('not_repairable', $policy['reason']);
            $this->assertFalse($policy['stop_session']);
        }
    }

    public function test_legacy_untestable_preflight_quarantine_does_not_lock_runtime_bugfix_candidates(): void
    {
        $service = $this->service();
        $path = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode([
            'schema_version' => AreaFocusCandidateQuarantineService::SCHEMA,
            'finding_id' => 'factory_max_ap789_forge_authority_readiness',
            'finding_hash' => 'sha256:runtime-bugfix',
            'title' => 'Improve AP-789 live authority readiness diagnostics',
            'blocker' => ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE,
            'retry_after' => 'permanent',
            'recorded_at' => $this->timestamp('-5 minutes'),
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);

        $locked = $service->quarantinedFindingKeys('agentic_engineering_os', 'dev_forge');

        $this->assertArrayNotHasKey('factory_max_ap789_forge_authority_readiness', $locked);
        $this->assertArrayNotHasKey('sha256:runtime-bugfix', $locked);
    }

    public function test_legacy_untestable_preflight_quarantine_keeps_test_candidates_locked(): void
    {
        $service = $this->service();
        $path = $service->ledgerPath('agentic_engineering_os', 'dev_forge');
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode([
            'schema_version' => AreaFocusCandidateQuarantineService::SCHEMA,
            'finding_id' => 'factory_max_ap786_read_model_test',
            'finding_hash' => 'sha256:test-candidate',
            'title' => 'Add focused unit coverage for AP-786 session read model',
            'blocker' => ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE,
            'retry_after' => 'permanent',
            'recorded_at' => $this->timestamp('-5 minutes'),
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);

        $locked = $service->quarantinedFindingKeys('agentic_engineering_os', 'dev_forge');

        $this->assertArrayHasKey('factory_max_ap786_read_model_test', $locked);
        $this->assertArrayHasKey('sha256:test-candidate', $locked);
    }

    private function timestamp(string $modifier): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify($modifier)
            ->format(DateTimeInterface::ATOM);
    }
}
