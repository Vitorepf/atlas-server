<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Provider;

use App\Support\AtlasSecurity;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Production Claude CLI transport for Atlas Dev.
 *
 * Uses argv arrays + stdin only. No shell command is ever assembled. The
 * provider/model lock is enforced by the upstream SonnetClaudeCliAdapter; this
 * gateway reports the observed transport result back to that adapter.
 */
final class SymfonyClaudeCliGateway implements ClaudeCliGateway
{
    /** @var callable|null */
    private $processFactory = null;

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function dispatch(ClaudeCliRequest $request): ClaudeCliResponse
    {
        $argv = $this->argv($request);
        $this->assertSafeArgv($argv);
        $this->assertWorkspace($request->workspace);

        $started = hrtime(true);
        $process = $this->makeProcess($argv, $request->workspace, $request->timeoutSeconds);
        $process->setInput($request->promptProjection->renderedPromptText);

        try {
            $process->run();
            $exitCode = $process->getExitCode() ?? 1;
            $stdout = (string) $process->getOutput();
            $stderr = (string) $process->getErrorOutput();
        } catch (ProcessTimedOutException $e) {
            $exitCode = 124;
            $stdout = (string) $process->getOutput();
            $stderr = $e->getMessage()."\n".(string) $process->getErrorOutput();
            try {
                $process->stop(2);
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            $exitCode = 1;
            $stdout = '';
            $stderr = $e->getMessage();
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        return new ClaudeCliResponse(
            actualProvider: SonnetClaudeCliAdapter::PROVIDER,
            actualModelFamily: SonnetClaudeCliAdapter::MODEL_FAMILY,
            exitCode: $exitCode,
            stdout: $this->extractAssistantText(AtlasSecurity::redactString($stdout)),
            stderr: AtlasSecurity::redactString($stderr),
            durationMs: $durationMs,
            tokensIn: null,
            tokensOut: null,
            costEstimateUsd: null,
        );
    }

    public function setProcessFactory(?callable $factory): void
    {
        $this->processFactory = $factory;
    }

    /**
     * @return list<string>
     */
    private function argv(ClaudeCliRequest $request): array
    {
        $provider = (array) $this->config->get('atlas.ai.providers.claude_cli', []);
        $binary = (string) ($provider['binary'] ?? 'claude');
        $args = $provider['args'] ?? ['-p', '--output-format', 'stream-json', '--verbose', '--no-session-persistence'];
        $args = is_array($args) ? array_values(array_filter($args, 'is_string')) : [];

        if (! in_array('-p', $args, true) && ! in_array('--print', $args, true)) {
            array_unshift($args, '-p');
        }

        $model = $provider['model'] ?? null;
        if (is_string($model) && trim($model) !== '' && ! $this->containsOption($args, '--model')) {
            $args[] = '--model';
            $args[] = trim($model);
        }

        return array_values(array_merge([$binary], $args));
    }

    /**
     * @param  list<string>  $argv
     */
    private function assertSafeArgv(array $argv): void
    {
        $binary = $argv[0] ?? '';
        $basename = basename(str_replace('\\', '/', $binary));
        if (! in_array($basename, ['claude', 'claude-code', 'claude.exe'], true)) {
            throw new InvalidArgumentException("SymfonyClaudeCliGateway: binary '{$basename}' is not allowed.");
        }

        foreach ($argv as $arg) {
            if ($arg === '' || preg_match('/[|;&`$<>]|\$\(|\$\{|\R/', $arg) === 1) {
                throw new InvalidArgumentException('SymfonyClaudeCliGateway: unsafe argv token rejected.');
            }
        }
    }

    private function assertWorkspace(string $workspace): void
    {
        if (! is_dir($workspace)) {
            throw new InvalidArgumentException("SymfonyClaudeCliGateway: workspace '{$workspace}' does not exist.");
        }
    }

    private function containsOption(array $args, string $option): bool
    {
        return in_array($option, $args, true)
            || count(array_filter($args, static fn (string $arg): bool => str_starts_with($arg, $option.'='))) > 0;
    }

    private function makeProcess(array $argv, string $cwd, int $timeoutSeconds): Process
    {
        if ($this->processFactory !== null) {
            $process = call_user_func($this->processFactory, $argv, $cwd, $timeoutSeconds);
            if ($process instanceof Process) {
                return $process;
            }
        }

        return new Process($argv, $cwd, $this->providerEnv(), null, (float) $timeoutSeconds);
    }

    /**
     * Claude Code stores operator auth and session metadata under HOME. Some
     * PHP runtimes (notably built-in server / FPM pools launched from stripped
     * service managers) do not pass the same env as the interactive shell, so
     * make the provider env explicit while still inheriting everything else.
     *
     * @return array<string, string>
     */
    private function providerEnv(): array
    {
        $env = [];

        $home = getenv('HOME');
        if (! is_string($home) || trim($home) === '') {
            $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? null;
        }
        if ((! is_string($home) || trim($home) === '') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_getuid());
            if (is_array($user) && is_string($user['dir'] ?? null)) {
                $home = $user['dir'];
            }
        }
        if (is_string($home) && trim($home) !== '') {
            $env['HOME'] = $home;
        }

        $path = getenv('PATH');
        if (! is_string($path) || trim($path) === '') {
            $path = $_SERVER['PATH'] ?? $_ENV['PATH'] ?? null;
        }
        if (is_string($path) && trim($path) !== '') {
            $env['PATH'] = $path;
        }

        return $env;
    }

    private function extractAssistantText(string $stdout): string
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            return '';
        }

        $single = json_decode($trimmed, true);
        if (is_array($single)) {
            $text = $this->extractTextFromClaudePayload($single);
            if ($text !== '') {
                return $text;
            }
        }

        $result = '';
        $tokens = '';
        foreach (preg_split('/\R/', $trimmed) ?: [] as $line) {
            $event = json_decode(trim($line), true);
            if (! is_array($event)) {
                continue;
            }
            $text = $this->extractTextFromClaudePayload($event);
            if ($text === '') {
                continue;
            }
            if (($event['type'] ?? null) === 'result' || isset($event['result'])) {
                $result = $text;
            } else {
                $tokens .= $text;
            }
        }

        return trim($result !== '' ? $result : ($tokens !== '' ? $tokens : $stdout));
    }

    private function extractTextFromClaudePayload(array $payload): string
    {
        foreach (['result', 'text', 'content'] as $key) {
            if (is_string($payload[$key] ?? null)) {
                return (string) $payload[$key];
            }
        }

        $message = $payload['message'] ?? null;
        if (is_array($message)) {
            $text = $this->extractTextFromClaudePayload($message);
            if ($text !== '') {
                return $text;
            }
        }

        $content = $payload['content'] ?? null;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $item) {
                if (is_array($item) && is_string($item['text'] ?? null)) {
                    $parts[] = $item['text'];
                }
            }
            if ($parts !== []) {
                return implode('', $parts);
            }
        }

        $delta = $payload['delta'] ?? null;
        if (is_array($delta) && is_string($delta['text'] ?? null)) {
            return $delta['text'];
        }

        return '';
    }
}
