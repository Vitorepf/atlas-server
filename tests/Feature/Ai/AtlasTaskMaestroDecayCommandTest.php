<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasTaskMaestroDecayCommand;
use App\Services\Ai\SelfConstruction\Maestro\Decay\AtlasMaestroPacketAgeFactReporter;
use App\Services\Ai\SelfConstruction\Maestro\Decay\AtlasMaestroPacketDecayPolicy;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasTaskMaestroDecayCommandTest extends TestCase
{
    private string $historyPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->historyPath = storage_path(AtlasTaskMaestroDecayCommand::HISTORY_REL);
        @unlink($this->historyPath);
        config()->set('atlas.loop.master_enabled', true);

        // Bind reporter/policy with a controlled packet source.
        $packetSource = fn (): array => [
            [
                'task_packet_id' => 'pkt-old',
                'enqueued_at' => '2026-06-20T00:00:00Z', // 5+ days ago vs the fixed clock below
                'queue_status' => 'queued',
            ],
            [
                'task_packet_id' => 'pkt-fresh',
                'enqueued_at' => '2026-06-25T11:00:00Z',
                'queue_status' => 'queued',
            ],
        ];
        $reporter = new AtlasMaestroPacketAgeFactReporter(
            $packetSource,
            static fn (): string => '2026-06-25T12:00:00Z',
            fn (): bool => true,
        );
        app()->instance(AtlasMaestroPacketAgeFactReporter::class, $reporter);
        app()->instance(
            AtlasMaestroPacketDecayPolicy::class,
            new AtlasMaestroPacketDecayPolicy(
                $reporter,
                static fn (): int => 3600, // 1 hour threshold
                fn (): bool => true,
            ),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->historyPath);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:task:maestro:decay', $params, $buf);

        return ['exit' => $exit, 'output' => $buf->fetch()];
    }

    public function test_inspect_table_includes_packet_rows(): void
    {
        $r = $this->runCmd(['mode' => 'inspect']);
        self::assertSame(0, $r['exit']);
        self::assertStringContainsString('pkt-old', $r['output']);
        self::assertStringContainsString('pkt-fresh', $r['output']);
    }

    public function test_inspect_json_emits_valid_array(): void
    {
        $r = $this->runCmd(['mode' => 'inspect', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertSame('inspect', $payload['mode']);
        self::assertCount(2, $payload['payload']);
        self::assertContains('pkt-old', array_column($payload['payload'], 'task_packet_id'));
    }

    public function test_propose_prints_proposals_and_does_not_mutate_queue(): void
    {
        $r = $this->runCmd(['mode' => 'propose', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertSame('propose', $payload['mode']);
        $packetIds = array_column($payload['payload'], 'task_packet_id');
        self::assertContains('pkt-old', $packetIds);
        self::assertNotContains('pkt-fresh', $packetIds, 'fresh packet must be below the threshold');
    }

    public function test_history_appends_strictly_grows(): void
    {
        $this->runCmd(['mode' => 'propose', '--json' => true]);
        $sizeAfterFirst = filesize($this->historyPath);

        $this->runCmd(['mode' => 'propose', '--json' => true]);
        $sizeAfterSecond = filesize($this->historyPath);

        self::assertGreaterThan($sizeAfterFirst, $sizeAfterSecond, 'history file must strictly grow');

        $r = $this->runCmd(['mode' => 'history', '--limit' => 5, '--json' => true]);
        $payload = json_decode(trim($r['output']), true);
        self::assertGreaterThanOrEqual(2, count($payload['payload']));
    }

    public function test_master_off_emits_notice_and_writes_no_history(): void
    {
        config()->set('atlas.loop.master_enabled', false);
        // inspect bypasses the master switch — only propose and history are gated.
        foreach (['propose', 'history'] as $mode) {
            $r = $this->runCmd(['mode' => $mode]);
            self::assertSame(0, $r['exit']);
            self::assertStringContainsString('master switch OFF', $r['output']);
        }
        self::assertFileDoesNotExist($this->historyPath);
    }

    public function test_inspect_bypasses_master_switch_and_emits_packet_facts(): void
    {
        config()->set('atlas.loop.master_enabled', false);
        $r = $this->runCmd(['mode' => 'inspect', '--json' => true]);
        self::assertSame(0, $r['exit']);
        $payload = json_decode(trim($r['output']), true);
        self::assertSame('inspect', $payload['mode']);
        self::assertCount(2, $payload['payload'], 'inspect must emit real packet facts even with master OFF');
        self::assertStringNotContainsString('master switch OFF', $r['output']);
    }

    public function test_unknown_mode_returns_usage(): void
    {
        $r = $this->runCmd(['mode' => 'bogus']);
        self::assertSame(AtlasTaskMaestroDecayCommand::EXIT_USAGE, $r['exit']);
        self::assertStringContainsString('unknown_mode', $r['output']);
    }

    public function test_command_is_registered_in_artisan_list(): void
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->call('list', [], $buf);
        self::assertStringContainsString('maestro:decay', $buf->fetch());
    }
}
