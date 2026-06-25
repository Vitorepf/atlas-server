<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\WireFormat\AtlasLoopInterPrimitiveMessageNegotiationOutcome;
use App\Services\Ai\AutonomousEvolution\WireFormat\AtlasLoopInterPrimitiveMessageSchemaRegistry;
use App\Services\Ai\AutonomousEvolution\WireFormat\AtlasLoopInterPrimitiveMessageVersionNegotiator;
use Tests\TestCase;

final class AtlasLoopInterPrimitiveMessageVersionNegotiatorTest extends TestCase
{
    /**
     * @param  array<string, list<int>>  $declared family => declared versions
     */
    private function negotiator(array $declared): AtlasLoopInterPrimitiveMessageVersionNegotiator
    {
        return new AtlasLoopInterPrimitiveMessageVersionNegotiator(
            new AtlasLoopInterPrimitiveMessageSchemaRegistry(),
            static fn (string $family): array => $declared[$family] ?? [],
        );
    }

    public function test_highest_common_version_is_chosen(): void
    {
        $negotiator = $this->negotiator(['loop.cortex.snapshot' => [1, 2, 3]]);
        $outcome = $negotiator->negotiate('loop.cortex.snapshot', [3, 2, 1], [2, 1]);

        $this->assertSame(2, $outcome->chosenVersion);
        $this->assertSame(AtlasLoopInterPrimitiveMessageNegotiationOutcome::REASON_HIGHEST_COMMON, $outcome->reason);
    }

    public function test_no_common_version_returns_null_with_reason(): void
    {
        $negotiator = $this->negotiator(['loop.cortex.snapshot' => [1, 2, 3, 4]]);
        $outcome = $negotiator->negotiate('loop.cortex.snapshot', [4, 3], [2, 1]);

        $this->assertNull($outcome->chosenVersion);
        $this->assertSame(AtlasLoopInterPrimitiveMessageNegotiationOutcome::REASON_NO_COMMON_VERSION, $outcome->reason);
    }

    public function test_phantom_source_version_is_rejected_fail_closed(): void
    {
        $negotiator = $this->negotiator(['loop.cortex.snapshot' => [1, 2]]);
        $outcome = $negotiator->negotiate('loop.cortex.snapshot', [99, 2], [2, 1]);

        $this->assertNull($outcome->chosenVersion);
        $this->assertSame(AtlasLoopInterPrimitiveMessageNegotiationOutcome::REASON_PHANTOM_VERSION, $outcome->reason);
        $this->assertSame('source', $outcome->offendingSide);
    }

    public function test_phantom_target_version_is_rejected_fail_closed(): void
    {
        $negotiator = $this->negotiator(['loop.cortex.snapshot' => [1, 2]]);
        $outcome = $negotiator->negotiate('loop.cortex.snapshot', [2, 1], [2, 88]);

        $this->assertNull($outcome->chosenVersion);
        $this->assertSame(AtlasLoopInterPrimitiveMessageNegotiationOutcome::REASON_PHANTOM_VERSION, $outcome->reason);
        $this->assertSame('target', $outcome->offendingSide);
    }

    public function test_unknown_family_returns_null_with_reason(): void
    {
        $negotiator = $this->negotiator([]);
        $outcome = $negotiator->negotiate('not.a.family', [1], [1]);

        $this->assertNull($outcome->chosenVersion);
        $this->assertSame(AtlasLoopInterPrimitiveMessageNegotiationOutcome::REASON_UNKNOWN_FAMILY, $outcome->reason);
    }

    public function test_outcome_captures_verbatim_inputs_byte_stable(): void
    {
        $negotiator = $this->negotiator(['loop.cortex.snapshot' => [1, 2, 3]]);
        $a = $negotiator->negotiate('loop.cortex.snapshot', [3, 2, 1], [2, 1]);
        $b = $negotiator->negotiate('loop.cortex.snapshot', [3, 2, 1], [2, 1]);

        $this->assertSame([3, 2, 1], $a->sourceVersions);
        $this->assertSame([2, 1], $a->targetVersions);
        $this->assertSame(json_encode($a->toArray()), json_encode($b->toArray()), 'outcome byte-shape must be byte-stable');
    }

    public function test_default_resolver_uses_registry(): void
    {
        // The real registry declares loop.cortex.snapshot.v1 only.
        $negotiator = new AtlasLoopInterPrimitiveMessageVersionNegotiator(new AtlasLoopInterPrimitiveMessageSchemaRegistry());
        $outcome = $negotiator->negotiate('loop.cortex.snapshot', [1], [1]);
        $this->assertSame(1, $outcome->chosenVersion);
        $this->assertSame(AtlasLoopInterPrimitiveMessageNegotiationOutcome::REASON_HIGHEST_COMMON, $outcome->reason);
    }
}
