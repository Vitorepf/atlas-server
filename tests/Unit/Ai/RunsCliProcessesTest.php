<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\Concerns\RunsCliProcesses;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RunsCliProcessesTest extends TestCase
{
    public function test_reap_cli_process_collects_exited_child(): void
    {
        $runner = new class
        {
            use RunsCliProcesses {
                reapCliProcess as public;
            }
        };

        $process = new Process([PHP_BINARY, '-r', 'usleep(200000);']);
        $process->setTimeout(5);
        $process->start();
        $pid = $process->getPid();

        $this->assertIsInt($pid);
        usleep(500000);

        $runner->reapCliProcess($process);

        $ps = new Process(['ps', '-o', 'stat=', '-p', (string) $pid]);
        $ps->run();

        $this->assertSame('', trim($ps->getOutput()));
    }

    public function test_early_cli_failure_stops_provider_process_before_timeout(): void
    {
        $runner = new class
        {
            use RunsCliProcesses {
                runProcess as public;
            }
        };

        $result = $runner->runProcess([
            PHP_BINARY,
            '-r',
            'fwrite(STDERR, "Opening authentication page in your browser\n"); sleep(10);',
        ], '', 10, base_path());

        $this->assertFalse($result->ok);
        $this->assertSame('auth_expired', $result->errorCode);
        $this->assertLessThan(5000, $result->durationMs);
    }

    public function test_timeout_path_returns_control_after_reaping_provider_process(): void
    {
        $runner = new class
        {
            use RunsCliProcesses {
                runProcess as public;
            }
        };

        $result = $runner->runProcess([
            PHP_BINARY,
            '-r',
            'sleep(10);',
        ], '', 1, base_path());

        $this->assertFalse($result->ok);
        $this->assertSame('timeout', $result->errorCode);
        $this->assertLessThan(5000, $result->durationMs);
    }

    public function test_streaming_callback_exception_reaps_provider_process_before_rethrow(): void
    {
        $runner = new class
        {
            use RunsCliProcesses {
                runProcessStreaming as public;
            }
        };
        $marker = 'atlas-reap-test-'.bin2hex(random_bytes(4));

        try {
            $runner->runProcessStreaming([
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "'.$marker.'\n"); sleep(10);',
            ], '', 10, base_path(), static function (array $event): void {
                if (($event['type'] ?? null) === 'stdout') {
                    throw new RuntimeException('stream callback failed');
                }
            });

            $this->fail('Expected stream callback exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('stream callback failed', $exception->getMessage());
        }

        $ps = new Process(['ps', 'axo', 'command=']);
        $ps->run();

        $this->assertStringNotContainsString($marker, $ps->getOutput());
    }
}
