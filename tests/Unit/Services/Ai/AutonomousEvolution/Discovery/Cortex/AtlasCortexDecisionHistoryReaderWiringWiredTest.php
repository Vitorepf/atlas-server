<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\Discovery\Cortex;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexDecisionHistoryReader;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * brain-orphan-20a4401e6998 — prove AtlasCortexDecisionHistoryReader is wired into the live
 * `atlas:loop:cortex:intent history` CLI action.
 */
class AtlasCortexDecisionHistoryReaderWiringWiredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Stub the reader with a fake `git log` runner so the test is hermetic.
        app()->instance(
            AtlasCortexDecisionHistoryReader::class,
            new AtlasCortexDecisionHistoryReader(
                runner: static function (array $cmd): array {
                    // Emulate `git log --follow --format=%H%x1f%s%x1f%b%x1f%at -- <path>`.
                    return [
                        'exit_code' => 0,
                        'output' => "abc123\x1ffix:something\x1fbody\x1f1700000000\n".
                            "def456\x1ffeat:add\x1fbody\x1f1700000100\n",
                    ];
                },
            ),
        );
    }

    public function test_cli_history_action_invokes_the_reader(): void
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:cortex:intent', [
            'action' => 'history',
            '--file' => base_path('app/Console/Commands/AtlasLoopCortexIntentCommand.php'),
            '--json' => true,
        ], $buf);

        self::assertSame(0, $exit);
        $output = trim($buf->fetch());
        $payload = json_decode($output, true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('commit_count', $payload);
        self::assertArrayHasKey('decisions', $payload);
        self::assertSame(2, $payload['commit_count']);
    }

    public function test_cli_history_action_without_file_emits_usage_error(): void
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:loop:cortex:intent', [
            'action' => 'history',
        ], $buf);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('usage_error', $buf->fetch());
    }

    public function test_command_source_imports_and_invokes_the_reader(): void
    {
        $source = (string) file_get_contents(
            base_path('app/Console/Commands/AtlasLoopCortexIntentCommand.php')
        );
        self::assertStringContainsString(AtlasCortexDecisionHistoryReader::class, $source);
        self::assertStringContainsString('$reader->read(', $source);
        self::assertStringContainsString("'history'", $source);
    }
}
