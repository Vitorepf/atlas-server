<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNodeInterfaceContract;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 6 — the human-frozen node-interface contract reader: deterministic goal-hash, loads a frozen
 * fixture, normalizes FQNs (leading-slash stripped), and degrades to null (no false-reject) on absent /
 * malformed / empty fixtures.
 */
final class AtlasLoopNodeInterfaceContractReaderTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-ifacecontract-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            (new Process(['rm', '-rf', $this->dir]))->run();
        }
        parent::tearDown();
    }

    public function test_goal_hash_is_deterministic_and_normalizes_case_and_whitespace(): void
    {
        $r = new AtlasLoopNodeInterfaceContract($this->dir);
        $this->assertSame($r->goalHash('Extract Foo'), $r->goalHash('  extract foo  '));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $r->goalHash('Extract Foo'));
    }

    public function test_loads_and_normalizes_a_frozen_contract(): void
    {
        $r = new AtlasLoopNodeInterfaceContract($this->dir);
        $goal = 'Extract Foo';
        file_put_contents($r->fixturePath($goal), json_encode(['files' => [
            '/app/Foo.php' => [
                'fqn' => '\\App\\Foo',
                'required_public_methods' => ['a', 'b'],
                'implements' => ['\\App\\Contracts\\Bar'],
                'forbidden_imports' => ['App\\Hub'],
            ],
        ]]));

        $loaded = $r->load($goal);

        $this->assertNotNull($loaded);
        $this->assertArrayHasKey('app/Foo.php', $loaded, 'file key leading slash stripped');
        $this->assertSame('App\\Foo', $loaded['app/Foo.php']['fqn'], 'fqn leading slash stripped');
        $this->assertSame(['a', 'b'], $loaded['app/Foo.php']['required_public_methods']);
        $this->assertSame(['App\\Contracts\\Bar'], $loaded['app/Foo.php']['implements']);
        $this->assertSame(['App\\Hub'], $loaded['app/Foo.php']['forbidden_imports']);
    }

    public function test_absent_malformed_and_empty_contracts_degrade_to_null(): void
    {
        $r = new AtlasLoopNodeInterfaceContract($this->dir);

        $this->assertNull($r->load('never-frozen'));

        file_put_contents($r->fixturePath('broken'), 'not json {{{');
        $this->assertNull($r->load('broken'));

        file_put_contents($r->fixturePath('empty'), json_encode(['files' => []]));
        $this->assertNull($r->load('empty'), 'empty files map => null (no check, no false-reject)');
    }
}
