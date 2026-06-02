<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Evidence;

use App\Services\Ai\Evidence\AuditCausalChainClassifier;
use PHPUnit\Framework\TestCase;

final class AuditCausalChainClassifierTest extends TestCase
{
    private AuditCausalChainClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AuditCausalChainClassifier();
    }

    public function testEmptyTimelineIsEmptyWithNullDanglingIndex(): void
    {
        $result = $this->classifier->classify([]);

        $this->assertSame('empty', $result['status']);
        $this->assertNull($result['first_dangling_index']);
        $this->assertSame(0, $result['seen_ids']);
        $this->assertSame('no_events', $result['reason']);
    }

    public function testEventReferencingUnknownEarlierIdIsDanglingAtThatIndex(): void
    {
        $result = $this->classifier->classify([
            ['uuid' => 'evt-root', 'correlation_id' => 'corr-1'],
            ['uuid' => 'evt-child', 'correlation_id' => 'corr-1', 'causation_id' => 'evt-root'],
            ['uuid' => 'evt-broken', 'correlation_id' => 'corr-1', 'causation_id' => 'evt-missing'],
        ]);

        $this->assertSame('dangling_causation', $result['status']);
        // The unresolved link is the third event -> 0-based index 2, proving the
        // index is computed from position rather than a canned constant.
        $this->assertSame(2, $result['first_dangling_index']);
        $this->assertSame('causation_id_unresolved_at_index_2', $result['reason']);
    }

    public function testAllRootsWithoutCausationAreOrphanRoot(): void
    {
        $result = $this->classifier->classify([
            ['uuid' => 'evt-a', 'correlation_id' => 'corr-a'],
            ['uuid' => 'evt-b', 'correlation_id' => 'corr-b'],
            ['uuid' => 'evt-c'],
        ]);

        $this->assertSame('orphan_root', $result['status']);
        $this->assertNull($result['first_dangling_index']);
        $this->assertSame('no_causation_links', $result['reason']);
    }

    public function testSelfReferentialCausationIsDangling(): void
    {
        $result = $this->classifier->classify([
            ['uuid' => 'evt-root', 'correlation_id' => 'corr-root'],
            ['uuid' => 'evt-self', 'correlation_id' => 'corr-self', 'causation_id' => 'evt-self'],
        ]);

        // The own uuid is folded into the seen-set only AFTER evaluation, so a
        // causation_id pointing at the event itself cannot resolve to an earlier
        // event and is therefore dangling at its own index.
        $this->assertSame('dangling_causation', $result['status']);
        $this->assertSame(1, $result['first_dangling_index']);
        $this->assertSame('causation_id_unresolved_at_index_1', $result['reason']);
    }

    public function testFullyLinkedChainIsCausal(): void
    {
        $result = $this->classifier->classify([
            ['uuid' => 'evt-1', 'correlation_id' => 'corr-1'],
            ['uuid' => 'evt-2', 'correlation_id' => 'corr-1', 'causation_id' => 'evt-1'],
            ['uuid' => 'evt-3', 'correlation_id' => 'corr-1', 'causation_id' => 'evt-2'],
        ]);

        $this->assertSame('causal', $result['status']);
        $this->assertNull($result['first_dangling_index']);
        $this->assertSame('all_links_resolved', $result['reason']);
        // Three distinct uuids plus one shared correlation_id = four seen ids.
        $this->assertSame(4, $result['seen_ids']);
    }

    public function testCausationCanResolveViaEarlierCorrelationId(): void
    {
        $result = $this->classifier->classify([
            ['uuid' => 'evt-root', 'correlation_id' => 'flow-99'],
            ['uuid' => 'evt-follow', 'correlation_id' => 'flow-2', 'causation_id' => 'flow-99'],
        ]);

        // The link targets the prior event's correlation_id rather than its
        // uuid, which still resolves to a strictly-earlier identifier.
        $this->assertSame('causal', $result['status']);
        $this->assertNull($result['first_dangling_index']);
    }

    public function testFirstDanglingIndexIsZeroBasedWhenFirstEventLinks(): void
    {
        $result = $this->classifier->classify([
            ['uuid' => 'evt-lead', 'correlation_id' => 'corr-lead', 'causation_id' => 'evt-never-seen'],
            ['uuid' => 'evt-trail', 'correlation_id' => 'corr-trail'],
        ]);

        // A leading event that already carries an unresolved causation_id dangles
        // at 0-based index 0.
        $this->assertSame('dangling_causation', $result['status']);
        $this->assertSame(0, $result['first_dangling_index']);
        $this->assertSame('causation_id_unresolved_at_index_0', $result['reason']);
    }

    public function testOnlyFirstDanglingLinkIsReported(): void
    {
        $result = $this->classifier->classify([
            ['uuid' => 'evt-a', 'correlation_id' => 'corr-a'],
            ['uuid' => 'evt-b', 'correlation_id' => 'corr-b', 'causation_id' => 'ghost-x'],
            ['uuid' => 'evt-c', 'correlation_id' => 'corr-c', 'causation_id' => 'ghost-y'],
        ]);

        $this->assertSame('dangling_causation', $result['status']);
        $this->assertSame(1, $result['first_dangling_index']);
        // Only the first event (uuid + correlation_id) was folded in before the
        // dangling link was detected.
        $this->assertSame(2, $result['seen_ids']);
    }

    public function testBlankCausationIdIsTreatedAsLegalRoot(): void
    {
        $result = $this->classifier->classify([
            ['uuid' => 'evt-a', 'correlation_id' => 'corr-a', 'causation_id' => '   '],
            ['uuid' => 'evt-b', 'correlation_id' => 'corr-b', 'causation_id' => 'evt-a'],
        ]);

        // A whitespace-only causation_id is normalized to absent, so the first
        // event is a root and the second resolves cleanly.
        $this->assertSame('causal', $result['status']);
        $this->assertNull($result['first_dangling_index']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $events = [
            ['uuid' => 'evt-1', 'correlation_id' => 'corr-1'],
            ['uuid' => 'evt-2', 'correlation_id' => 'corr-1', 'causation_id' => 'evt-1'],
        ];

        $first = $this->classifier->classify($events);
        $second = $this->classifier->classify($events);

        $this->assertSame($first, $second);
    }
}
