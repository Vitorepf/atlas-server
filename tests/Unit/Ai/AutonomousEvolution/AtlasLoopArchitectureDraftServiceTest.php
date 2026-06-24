<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopArchitectureDraftService;
use Tests\TestCase;

/**
 * Proves the ARQUITETAR draft service: flag-OFF null no-op; §9 fence (no writer ⇒ no_writer); writer≠judge
 * cross-model separation (distinct provider keys); ANY critic refutation ⇒ refused envelope, no draft.
 */
final class AtlasLoopArchitectureDraftServiceTest extends TestCase
{
    /** @return array<string,mixed> */
    private function decision(): array
    {
        return ['selected_objective' => 'wire App\\Foo\\Bar', 'cited_symbols' => ['App\\Foo\\Bar']];
    }

    private function writer(): \Closure
    {
        return static fn (string $objective, array $cited, array $orientation): array => [
            'proposed_files' => ['app/Foo/Bar.php', 'app/Foo/Consumer.php'],
            'proposed_seams' => ['Consumer::__construct'],
            'provider' => 'hermes_writer',
        ];
    }

    private function cleanCritic(): \Closure
    {
        return static fn (string $objective, array $draft, array $orientation): array => [
            'refutations' => [],
            'provider' => 'hermes_critic',
        ];
    }

    private function refutingCritic(): \Closure
    {
        return static fn (string $objective, array $draft, array $orientation): array => [
            'refutations' => ['proposed file app/Foo/Consumer.php is not an inventory member'],
            'provider' => 'hermes_critic',
        ];
    }

    public function test_flag_off_is_null_no_op(): void
    {
        config(['atlas.loop.architecture_draft_enabled' => false]);

        $svc = new AtlasLoopArchitectureDraftService($this->writer(), $this->cleanCritic());
        $this->assertNull($svc->draft($this->decision(), []));
    }

    public function test_no_writer_fails_closed(): void
    {
        config(['atlas.loop.architecture_draft_enabled' => true]);

        $svc = new AtlasLoopArchitectureDraftService(null, null);
        $result = $svc->draft($this->decision(), []);

        $this->assertFalse($result['drafted']);
        $this->assertSame('no_writer', $result['reason']);
    }

    public function test_clean_draft_carries_distinct_writer_and_critic_providers(): void
    {
        config(['atlas.loop.architecture_draft_enabled' => true]);

        $svc = new AtlasLoopArchitectureDraftService($this->writer(), $this->cleanCritic());
        $env = $svc->draft($this->decision(), []);

        $this->assertSame('atlas.loop.architecture_draft.v1', $env['schema_version']);
        $this->assertTrue($env['drafted']);
        $this->assertSame('wire App\\Foo\\Bar', $env['objective']);
        $this->assertSame(['app/Foo/Bar.php', 'app/Foo/Consumer.php'], $env['proposed_files']);
        $this->assertSame([], $env['critic_refutations']);
        $this->assertSame('hermes_writer', $env['writer_provider']);
        $this->assertSame('hermes_critic', $env['critic_provider']);
        $this->assertNotSame($env['writer_provider'], $env['critic_provider'], 'cross-model: writer and critic are distinct providers');
    }

    public function test_any_critic_refutation_refuses_the_draft(): void
    {
        config(['atlas.loop.architecture_draft_enabled' => true]);

        $svc = new AtlasLoopArchitectureDraftService($this->writer(), $this->refutingCritic());
        $result = $svc->draft($this->decision(), []);

        $this->assertFalse($result['drafted']);
        $this->assertSame('critic_refuted', $result['reason']);
        $this->assertNotEmpty($result['refutations']);
        $this->assertArrayNotHasKey('proposed_files', $result, 'a refused draft carries no structural proposal');
    }
}
