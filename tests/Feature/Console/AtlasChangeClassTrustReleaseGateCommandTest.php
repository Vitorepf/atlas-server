<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\Governance\ChangeClassTrustReleaseGateService;
use App\Services\Ai\Policy\PolicyCanon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasChangeClassTrustReleaseGateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'atlas.ai.trust_ladder.enabled' => true,
            'atlas.ai.trust_ladder.thresholds' => [
                'draft' => 1,
                'execute_with_approval' => 2,
                'autonomous' => 3,
            ],
            'atlas.ai.trust_ladder.eligible_classes' => [
                'documentation_only',
                'tests_only',
                'docs_and_tests',
            ],
            'atlas.ai.trust_ladder.blocked_class_patterns' => [
                'never_merge',
                'merge_gate',
                'constitutional_kernel',
                'harness_guard',
                'frozen_judge',
                'formal_invariant',
                'kernel',
            ],
            'atlas.ai.trust_ladder.release_gate.enabled' => true,
            'atlas.ai.trust_ladder.release_gate.schedule_enabled' => true,
            'atlas.ai.trust_ladder.release_gate.schedule_time' => '07:40',
            'atlas.ai.trust_ladder.release_gate.target_class' => 'documentation_only',
            'atlas.ai.trust_ladder.release_gate.min_clean_streak' => 3,
            'atlas.ai.trust_ladder.release_gate.blocked_class_probe' => 'constitutional_kernel',
        ]);
    }

    public function test_mature_fixture_releases_one_allowlisted_class_and_proves_regression_revocation(): void
    {
        $payload = app(ChangeClassTrustReleaseGateService::class)->evaluate([
            'fixture' => 'mature',
        ]);

        $this->assertSame('change_class_trust_release_ready', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertTrue($payload['completion_claim_allowed']);
        $this->assertSame([], $payload['blockers']);
        $this->assertSame(PolicyCanon::AUTONOMY_AUTONOMOUS, data_get($payload, 'assessments.after.snapshot.earned_autonomy'));
        $this->assertSame('allow_autonomous', data_get($payload, 'assessments.after.admission.decision'));
        $this->assertFalse((bool) data_get($payload, 'assessments.after.admission.requires_human_approval'));
        $this->assertTrue((bool) data_get($payload, 'assessments.regression_probe.reverted_to_max_friction'));
        $this->assertSame(0, data_get($payload, 'assessments.regression_probe.after_revert.snapshot.clean_streak'));
        $this->assertTrue((bool) data_get($payload, 'assessments.blocked_class_probe.blocked_despite_clean_refs'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.never_merge_changed'));
    }

    public function test_regressed_fixture_blocks_after_one_revert(): void
    {
        $exit = Artisan::call('atlas:governance:change-class-trust-release-gate', [
            '--fixture' => 'regressed',
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('change_class_trust_release_blocked', $payload['status']);
        $this->assertContains('clean_streak_below_floor', $payload['blockers']);
        $this->assertContains('admission_still_requires_review', $payload['blockers']);
        $this->assertSame(0, data_get($payload, 'assessments.after.snapshot.clean_streak'));
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, data_get($payload, 'assessments.after.snapshot.earned_autonomy'));
    }

    public function test_sensitive_kernel_like_class_never_releases_even_with_clean_refs(): void
    {
        $exit = Artisan::call('atlas:governance:change-class-trust-release-gate', [
            '--fixture' => 'blocked-sensitive',
            '--strict' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('constitutional_kernel', $payload['change_class']);
        $this->assertContains('blocked_class_pattern:constitutional_kernel', $payload['blockers']);
        $this->assertSame(3, data_get($payload, 'assessments.after.snapshot.clean_streak'));
        $this->assertSame(PolicyCanon::AUTONOMY_SUGGEST, data_get($payload, 'assessments.after.snapshot.earned_autonomy'));
        $this->assertNotSame('allow_autonomous', data_get($payload, 'assessments.after.admission.decision'));
    }

    public function test_live_empty_ladder_blocks_but_seeded_refs_can_certify_without_provider_or_merge_gate_changes(): void
    {
        $log = storage_path('framework/testing/l6-14-change-class-trust.jsonl');
        File::delete($log);

        $emptyExit = Artisan::call('atlas:governance:change-class-trust-release-gate', [
            '--fixture' => 'live',
            '--log' => $log,
            '--strict' => true,
            '--json' => true,
        ]);
        $empty = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $emptyExit);
        $this->assertContains('clean_streak_below_floor', $empty['blockers']);

        $seededExit = Artisan::call('atlas:governance:change-class-trust-release-gate', [
            '--fixture' => 'live',
            '--log' => $log,
            '--seed-ref' => ['receipt-a', 'receipt-b', 'receipt-c'],
            '--strict' => true,
            '--json' => true,
        ]);
        $seeded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $seededExit, json_encode($seeded['blockers']));
        $this->assertSame('change_class_trust_release_ready', $seeded['status']);
        $this->assertSame(3, data_get($seeded, 'assessments.after.snapshot.clean_streak'));
        $this->assertFalse((bool) data_get($seeded, 'claim_policy.provider_calls_made'));
        $this->assertFalse((bool) data_get($seeded, 'claim_policy.merge_gate_changed'));
        $this->assertFileExists($log);
    }

    public function test_command_writes_receipt_and_schedule_contains_l6_14_gate(): void
    {
        $receipt = storage_path('framework/testing/change-class-trust-release-gate.json');
        File::delete($receipt);

        $exit = Artisan::call('atlas:governance:change-class-trust-release-gate', [
            '--fixture' => 'mature',
            '--receipt' => $receipt,
            '--write-receipt' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertFileExists($receipt);
        $this->assertSame($receipt, $payload['receipt_path']);

        $scheduleExit = Artisan::call('schedule:list');
        $schedule = Artisan::output();

        $this->assertSame(0, $scheduleExit, $schedule);
        $this->assertStringContainsString('atlas:governance:change-class-trust-release-gate --write-receipt --json', $schedule);
    }
}
