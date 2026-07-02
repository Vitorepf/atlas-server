<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferObservationStore;
use Tests\TestCase;

final class AtlasSelfConstructionLearningTransferObservationStoreTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-lt-observations-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function store(): AtlasSelfConstructionLearningTransferObservationStore
    {
        return new AtlasSelfConstructionLearningTransferObservationStore($this->path);
    }

    /** @return array<string,mixed> */
    private function observation(string $packet, string $agent = 'agent-a', string $outcome = 'give_back'): array
    {
        return [
            'lesson_key' => 'lk-1',
            'class' => 'scope_gap',
            'scope_dirs' => ['app/Services/Ai/Area'],
            'task_packet_id' => $packet,
            'agent_id' => $agent,
            'outcome' => $outcome,
            'evidence_refs' => ['task_packet:'.$packet],
        ];
    }

    public function test_records_and_dedupes_on_packet_agent_outcome(): void
    {
        $store = $this->store();

        self::assertSame('recorded', $store->record($this->observation('pkt-1'))['status']);
        self::assertSame('already_recorded', $store->record($this->observation('pkt-1'))['status']);
        self::assertSame('recorded', $store->record($this->observation('pkt-2'))['status']);

        self::assertCount(2, $store->observationsFor('lk-1'));
    }

    public function test_incomplete_observation_is_skipped(): void
    {
        self::assertSame(
            'skipped_incomplete_observation',
            $this->store()->record(['lesson_key' => '', 'task_packet_id' => 'p', 'outcome' => 'give_back'])['status'],
        );
    }

    public function test_retired_lesson_key_yields_no_observations(): void
    {
        $store = $this->store();
        $store->record($this->observation('pkt-1'));
        $store->record($this->observation('pkt-2', 'agent-b'));

        $store->retire('lk-1');

        self::assertSame([], $store->observationsFor('lk-1'));
    }

    public function test_stale_observations_are_ignored_on_read(): void
    {
        $store = $this->store();
        $store->record($this->observation('pkt-1'));

        // Negative age puts the cutoff in the future: every row reads stale.
        self::assertSame([], $store->observationsFor('lk-1', maxAgeDays: -1));
        self::assertCount(1, $store->observationsFor('lk-1', maxAgeDays: 1));
    }

    public function test_give_back_class_histogram_for_intersecting_scope(): void
    {
        $store = $this->store();
        $store->record($this->observation('pkt-1'));
        $store->record($this->observation('pkt-2', 'agent-b'));
        $store->record($this->observation('pkt-3', 'agent-c', 'resolved')); // not give_back — excluded

        $classes = $store->giveBackClassesForScope(['app/Services/Ai/Area']);
        self::assertSame(['scope_gap' => 2], $classes);

        self::assertSame([], $store->giveBackClassesForScope(['app/Other']));
    }
}
