<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AaeosDeferredPhaseDispatcherService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

final class AaeosDeferredPhaseDispatcherServiceTest extends TestCase
{
    private string $queuePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queuePath = sys_get_temp_dir().'/atlas-aaeos-deferred-'.uniqid('', true).'.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_file($this->queuePath)) {
            @unlink($this->queuePath);
        }
        parent::tearDown();
    }

    public function test_enqueues_only_envelopes_marked_deferred(): void
    {
        $svc = $this->makeService();

        $result = $svc->enqueueFromFacadeResult(
            envelopes: [
                ['phase_out' => 'placement', 'outputs' => ['gate_status' => 'passed']],
                ['phase_out' => 'topology', 'outputs' => ['aawr_invocation' => 'deferred']],
                ['phase_out' => 'receipt', 'outputs' => ['decision_receipt_v2_invocation' => 'deferred']],
            ],
            queuePath: $this->queuePath,
        );

        self::assertSame(2, $result['enqueued_count']);
        self::assertSame(2, $svc->pendingCount($this->queuePath));
    }

    public function test_claim_returns_at_most_max_records_and_removes_them(): void
    {
        $svc = $this->makeService();
        $svc->enqueueFromFacadeResult(
            envelopes: [
                ['phase_out' => 'topology', 'outputs' => ['aawr_invocation' => 'deferred']],
                ['phase_out' => 'spec', 'outputs' => ['spec_invocation' => 'deferred']],
                ['phase_out' => 'tasks', 'outputs' => ['task_pack_invocation' => 'deferred']],
            ],
            queuePath: $this->queuePath,
        );

        $first = $svc->claim(max: 2, queuePath: $this->queuePath);

        self::assertCount(2, $first);
        self::assertSame(1, $svc->pendingCount($this->queuePath));

        $second = $svc->claim(max: 10, queuePath: $this->queuePath);

        self::assertCount(1, $second);
        self::assertSame(0, $svc->pendingCount($this->queuePath));
    }

    public function test_pending_count_zero_when_queue_does_not_exist(): void
    {
        $svc = $this->makeService();

        self::assertSame(0, $svc->pendingCount($this->queuePath));
        self::assertSame([], $svc->claim(10, $this->queuePath));
    }

    public function test_skips_envelope_without_deferred_marker(): void
    {
        $svc = $this->makeService();

        $result = $svc->enqueueFromFacadeResult(
            envelopes: [['phase_out' => 'placement', 'outputs' => []]],
            queuePath: $this->queuePath,
        );

        self::assertSame(0, $result['enqueued_count']);
    }

    private function makeService(): AaeosDeferredPhaseDispatcherService
    {
        return new AaeosDeferredPhaseDispatcherService(
            cache: new Repository(new ArrayStore()),
        );
    }
}
