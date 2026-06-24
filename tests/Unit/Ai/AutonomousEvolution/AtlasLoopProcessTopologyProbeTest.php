<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopProcessTopologyProbe;
use Tests\TestCase;

final class AtlasLoopProcessTopologyProbeTest extends TestCase
{
    public function test_fixture_payload_classifies_every_loop_role_and_builds_tree(): void
    {
        $snapshot = $this->probe()->snapshot();
        $roles = array_column($snapshot['processes'], 'role');

        $this->assertSame('atlas.loop.process_topology.v1', $snapshot['schema_version']);
        $this->assertSame('2026-06-24T16:55:00+00:00', $snapshot['captured_at_ts']);
        $this->assertSame('atlas-test-host', $snapshot['host']);
        foreach (['supervisor', 'grind', 'hermes', 'watchdog', 'keepalive', 'hung-guard'] as $role) {
            $this->assertContains($role, $roles);
        }
        $this->assertSame([101, 102], $snapshot['tree']['100']);
        $this->assertSame([103], $snapshot['tree']['102']);

        $grinds = array_values(array_filter($snapshot['processes'], static fn (array $row): bool => $row['role'] === 'grind'));
        $this->assertCount(2, $grinds, 'ps parsing finds run-scenario grinds even though a grind-task pgrep would miss them.');
        $this->assertSame('cid-123', $grinds[0]['campaign_id_or_null']);
    }

    public function test_output_is_byte_identical_for_same_fixture_replay(): void
    {
        $first = $this->probe()->snapshot();
        $second = $this->probe()->snapshot();

        $this->assertSame(
            json_encode($first, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode($second, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_probe_uses_ps_topology_command_never_pgrep_counting(): void
    {
        $probe = $this->probe();

        $this->assertSame('ps -axo pid=,ppid=,etime=,etimes=,pcpu=,pmem=,command=', $probe->psCommand());
        $this->assertStringNotContainsString('pgrep -fc', $probe->psCommand());

        $source = (string) file_get_contents(app_path('Services/Ai/AutonomousEvolution/Resilience/AtlasLoopProcessTopologyProbe.php'));
        $this->assertStringNotContainsString('pgrep -fc', $source);
    }

    private function probe(): AtlasLoopProcessTopologyProbe
    {
        return new AtlasLoopProcessTopologyProbe(
            $this->fixturePs(),
            'atlas-test-host',
            static fn (): string => '2026-06-24T16:55:00+00:00',
        );
    }

    private function fixturePs(): string
    {
        return <<<'PS'
          100     1   01:00:00 3600   1.1  2.2 /opt/homebrew/bin/php artisan atlas:loop:campaign --campaign-id=cid-123 --workers=4
          101   100      02:10  130   8.0  3.1 /opt/homebrew/bin/php artisan atlas:loop:run-scenario --campaign-id=cid-123 --scenario=1
          102   100      02:08  128   7.5  3.0 /opt/homebrew/bin/php artisan atlas:loop:run-scenario --campaign-id cid-123 --scenario=2
          103   102      01:50  110  25.0 12.0 hermes chat --json --provider minimax
          200     1      10:00  600   0.1  0.2 /bin/bash bin/atlas-loop-watchdog.sh cid-123
          201     1      04:00  240   0.1  0.2 /opt/homebrew/bin/php artisan atlas:loop:keepalive --campaign-id=cid-123
          202     1      03:30  210   0.1  0.2 /opt/homebrew/bin/php artisan atlas:loop:hung-supervisor-guard --campaign-id=cid-123
          300     1      00:01    1   0.0  0.1 /usr/bin/grep atlas:loop
        PS;
    }
}
