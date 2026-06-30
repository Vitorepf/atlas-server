<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Retry;

use App\Services\Ai\SelfConstruction\Maestro\Retry\AtlasMaestroGiveBackReshapeStrategy;
use Tests\TestCase;

final class AtlasMaestroGiveBackReshapeStrategyTest extends TestCase
{
    public function test_reshape_drops_forbidden_and_adds_anchor(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Maestro/Forbidden.php',
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
                'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
            ],
            'forbidden_hits' => [
                'app/Services/Ai/SelfConstruction/Maestro/Forbidden.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php',
                'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
            ],
            'scope_in_mismatches' => [],
            'missing_symbol_traces' => [[
                'symbol' => 'AnchorSymbol',
                'anchor_file' => 'app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php',
            ]],
        ]);

        $this->assertNotNull($proposal);
        $payload = $proposal->toArray();

        $this->assertSame([
            'app/Services/Ai/SelfConstruction/Maestro/Retry/Anchor.php',
            'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
            'tests/Unit/Ai/SelfConstruction/Maestro/Retry/ExistingTest.php',
        ], $payload['allowed_files']);
        $this->assertSame('high', $payload['confidence']);
        $this->assertFalse($payload['empty']);
    }

    public function test_test_only_anchor_produces_empty_proposal_with_pair_incomplete_reason(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => ['tests/Unit/Ai/SelfConstruction/FooTest.php'],
            'scope_in' => ['tests/Unit/Ai/SelfConstruction/FooTest.php'],
            'missing_symbol_traces' => [['anchor_file' => 'tests/Unit/Ai/SelfConstruction/FooTest.php']],
        ]);

        $payload = $proposal->toArray();
        $this->assertSame([], $payload['allowed_files']);
        $this->assertTrue($payload['empty']);
        $this->assertContains('impl_test_pair_incomplete', $payload['rationale']);
    }

    public function test_impl_only_files_produce_empty_proposal_with_pair_incomplete_reason(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/Foo.php'],
            'missing_symbol_traces' => [['anchor_file' => 'app/Services/Ai/SelfConstruction/Foo.php']],
        ]);

        $payload = $proposal->toArray();
        $this->assertSame([], $payload['allowed_files']);
        $this->assertTrue($payload['empty']);
        $this->assertContains('impl_test_pair_incomplete', $payload['rationale']);
    }

    public function test_parent_traversal_in_anchor_produces_empty_proposal(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => ['app/Services/Ai/../Secret.php'],
            'scope_in' => [],
            'missing_symbol_traces' => [['anchor_file' => 'app/Services/Ai/../Secret.php']],
        ]);

        $payload = $proposal->toArray();
        $this->assertSame([], $payload['allowed_files']);
        $this->assertTrue($payload['empty']);
        $this->assertContains('parent_traversal_rejected', $payload['rationale']);
    }

    public function test_petreo_anchor_is_never_returned_in_allowed_files(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Cortex/PetreoOrgan.php',
                'tests/Unit/PetreoOrganTest.php',
            ],
            'petreo_files' => ['app/Services/Ai/SelfConstruction/Cortex/PetreoOrgan.php'],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Cortex/PetreoOrgan.php',
                'tests/Unit/PetreoOrganTest.php',
            ],
            'missing_symbol_traces' => [['anchor_file' => 'app/Services/Ai/SelfConstruction/Cortex/PetreoOrgan.php']],
        ]);

        $payload = $proposal->toArray();
        $this->assertSame([], $payload['allowed_files']);
        $this->assertTrue($payload['empty']);
        $this->assertContains('forbidden_or_petreo_anchor_rejected', $payload['rationale']);
    }

    public function test_reshape_refuses_when_no_anchor_evidence(): void
    {
        $proposal = (new AtlasMaestroGiveBackReshapeStrategy)->propose([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
            ],
            'forbidden_hits' => [],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/Maestro/Retry/Existing.php',
            ],
            'scope_in_mismatches' => [],
            'missing_symbol_traces' => [],
        ]);

        $this->assertNotNull($proposal);
        $payload = $proposal->toArray();

        $this->assertSame([], $payload['allowed_files']);
        $this->assertSame('none', $payload['confidence']);
        $this->assertTrue($payload['empty']);
    }
}
