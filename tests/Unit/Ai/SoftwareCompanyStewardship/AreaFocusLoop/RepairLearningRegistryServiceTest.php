<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\RepairLearningRegistryService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class RepairLearningRegistryServiceTest extends TestCase
{
    private string $tmp;

    private RepairLearningRegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_repair_registry_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
        $this->registry = new RepairLearningRegistryService();
        $this->registry->setStorageRootForTesting($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_recall_is_empty_for_a_cold_registry(): void
    {
        $recall = $this->registry->recallForTaskClass('area', 'focus', 'bug');
        $this->assertSame(0, $recall['total_blocked_occurrences']);
        $this->assertSame([], $recall['blockers']);
        $this->assertNull($this->registry->repairHintForTaskClass('area', 'focus', 'bug'));
    }

    public function test_blockers_compound_and_rank_deterministically_by_occurrences(): void
    {
        $this->registry->setNowForTesting(new DateTimeImmutable('2026-01-01T00:00:00Z', new DateTimeZone('UTC')));
        $this->registry->recordBlockedCycle('area', 'focus', 'bug', ['validation_failed', 'scope_violation'], ['finding_id' => 'f1']);
        $this->registry->setNowForTesting(new DateTimeImmutable('2026-01-02T00:00:00Z', new DateTimeZone('UTC')));
        $this->registry->recordBlockedCycle('area', 'focus', 'bug', ['validation_failed'], ['finding_id' => 'f2']);

        $recall = $this->registry->recallForTaskClass('area', 'focus', 'bug');
        $this->assertSame(3, $recall['total_blocked_occurrences']);
        // validation_failed (2) ranks ahead of scope_violation (1).
        $this->assertSame('validation_failed', $recall['blockers'][0]['blocker']);
        $this->assertSame(2, $recall['blockers'][0]['occurrences']);
        $this->assertSame('f2', $recall['blockers'][0]['last_finding_id']);
        $this->assertSame('scope_violation', $recall['blockers'][1]['blocker']);
    }

    public function test_task_classes_are_isolated(): void
    {
        $this->registry->recordBlockedCycle('area', 'focus', 'bug', ['validation_failed']);
        $this->registry->recordBlockedCycle('area', 'focus', 'test', ['flaky_test']);

        $this->assertSame(1, $this->registry->recallForTaskClass('area', 'focus', 'bug')['total_blocked_occurrences']);
        $this->assertSame(1, $this->registry->recallForTaskClass('area', 'focus', 'test')['total_blocked_occurrences']);
        $this->assertSame(0, $this->registry->recallForTaskClass('area', 'focus', 'cleanup')['total_blocked_occurrences']);
    }

    public function test_repair_hint_caps_to_top_n_and_normalizes_class(): void
    {
        $this->registry->recordBlockedCycle('area', 'focus', '  BUG  ', ['a', 'b', 'c', 'd']);
        $hint = $this->registry->repairHintForTaskClass('area', 'focus', 'bug', 2);
        $this->assertNotNull($hint);
        $this->assertSame('bug', $hint['task_class']);
        $this->assertCount(2, $hint['top_prior_blockers']);
        $this->assertSame(4, $hint['prior_blocked_occurrences']);
    }

    public function test_blank_blockers_and_empty_class_are_normalized(): void
    {
        $written = $this->registry->recordBlockedCycle('area', 'focus', '', ['', '  ', 'real_blocker', 'real_blocker']);
        // Empty/dup blockers dropped; one row written under the default class.
        $this->assertCount(1, $written);
        $this->assertSame(RepairLearningRegistryService::DEFAULT_TASK_CLASS, $written[0]['task_class']);
    }
}
