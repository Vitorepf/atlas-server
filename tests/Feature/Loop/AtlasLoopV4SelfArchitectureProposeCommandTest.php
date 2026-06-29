<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4SelfArchitectureProposer;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the V4 self-architecture proposer is live at the operator surface: a valid architect proposal for an
 * on-topology, non-forbidden target is emitted; a constitutionally forbidden target is refused; with no
 * architect seam it refuses no_architect.
 */
final class AtlasLoopV4SelfArchitectureProposeCommandTest extends TestCase
{
    private string $input = '';

    private const SAFE_TARGET = 'app/Services/Ai/Marketing/Foo.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-v4-arch-'.bin2hex(random_bytes(5)).'.json';
        // topology carries a safe target AND a constitutionally-forbidden one (the harness guard itself).
        file_put_contents($this->input, (string) json_encode([
            'topology' => [self::SAFE_TARGET, AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS[0]],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function bindArchitect(callable $architect): void
    {
        $this->app->instance(
            AtlasLoopV4SelfArchitectureProposer::class,
            new AtlasLoopV4SelfArchitectureProposer($architect),
        );
    }

    private function propose(): array
    {
        $exit = Artisan::call('atlas:loop:v4-self-architecture-propose', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_valid_on_topology_proposal_is_emitted(): void
    {
        $this->bindArchitect(fn (array $topology, array $forbidden): array => [
            'kind' => 'add_seam',
            'target_path' => self::SAFE_TARGET,
            'rationale' => 'Introduce a constructor seam so the marketing scope is unit-testable in isolation.',
        ]);

        ['exit' => $exit, 'd' => $d] = $this->propose();

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.v4_self_architecture.v1', $d['schema']);
        $this->assertTrue($d['proposed'], (string) json_encode($d));
        $this->assertSame('add_seam', $d['kind']);
        $this->assertSame(self::SAFE_TARGET, $d['target_path']);
        $this->assertNull($d['refuse_reason']);
    }

    public function test_constitutionally_forbidden_target_is_refused(): void
    {
        $this->bindArchitect(fn (array $topology, array $forbidden): array => [
            'kind' => 'tighten_gate',
            'target_path' => AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS[0],
            'rationale' => 'Attempt to alter a cert organ — this must be refused by the constitution guard.',
        ]);

        ['d' => $d] = $this->propose();

        $this->assertFalse($d['proposed']);
        $this->assertSame('constitution_forbids_target', $d['refuse_reason'], (string) json_encode($d));
    }

    public function test_no_architect_seam_refuses(): void
    {
        // do not bind ⇒ autowired proposer has a null architect ⇒ refuse no_architect
        ['exit' => $exit, 'd' => $d] = $this->propose();

        $this->assertSame(0, $exit);
        $this->assertFalse($d['proposed']);
        $this->assertSame('no_architect', $d['refuse_reason']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:v4-self-architecture-propose', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
