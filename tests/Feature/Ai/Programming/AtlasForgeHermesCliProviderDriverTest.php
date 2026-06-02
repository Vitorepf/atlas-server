<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Programming\AtlasForgeHermesCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use Tests\TestCase;

/**
 * ADDITIVE coverage for the Hermes (`hermes_cli`) Forge provider-invocation
 * driver. Mirrors the setup of {@see AtlasForgeRealProviderDriversTest}: it
 * binds a FAKE `hermes_cli` provider into the AiProviderManager so the router
 * is exercised end-to-end with ZERO real tokens.
 *
 * Proves:
 *   - the router now treats hermes_cli as canonical (supports/hasRuntimeDriver);
 *   - isConfigured + driverStatus reflect the (fake) configured provider;
 *   - invoke()/driverInvoke() route to the fake provider and map its
 *     AiProviderResult into the canonical Forge result shape (provider_called
 *     true on a fake success, mapped exit_code/duration/hashes/changed_files);
 *   - existing canonical drivers (claude/codex/gemini/cursor/minimax/...) remain
 *     registered and unchanged (additive guarantee);
 *   - when no hermes provider resolves, the driver fails CLOSED (no call).
 */
class AtlasForgeHermesCliProviderDriverTest extends TestCase
{
    /**
     * Bind a fake `hermes_cli` provider on a manager instance, then register
     * that SAME manager instance in the container so the router's lazily
     * resolved adapter sees the fake (AiProviderManager is not a singleton by
     * default). Returns the fake so assertions can read what it captured.
     */
    private function bindFakeHermes(AiProviderResult $result, ?\Closure $onRun = null): FakeHermesProvider
    {
        $fake = new FakeHermesProvider($result, $onRun);

        /** @var AiProviderManager $manager */
        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', $fake);
        $this->app->instance(AiProviderManager::class, $manager);

        return $fake;
    }

    private function okResult(): AiProviderResult
    {
        return new AiProviderResult(
            ok: true,
            output: 'hermes executive runtime: patch applied',
            command: ['hermes', 'chat', '--quiet', '--query', '[prompt:redacted]'],
            exitCode: 0,
            durationMs: 4242,
            stdout: "no_patch_needed: true\nreason: fake hermes run\n",
            stderr: '',
            errorCode: null,
            errorMessage: null,
            metadata: [
                'hermes_result_packet' => [
                    'changed_files' => ['app/Example.php'],
                ],
            ],
        );
    }

    public function test_router_registers_hermes_cli_as_canonical_additively(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);

        $this->assertTrue($router->supports('hermes_cli'), 'hermes_cli must be canonical');
        $this->assertTrue($router->hasRuntimeDriver('hermes_cli'), 'hermes_cli must have a runtime driver');
        $this->assertContains('hermes_cli', AtlasForgeProviderInvocationDriverRouter::CANONICAL_DRIVERS);

        // Additive guarantee: every previously-registered driver still present.
        foreach (['atlas-local', 'claude_cli', 'codex_cli', 'gemini_cli', 'antigravity_sdk', 'cursor_sdk', 'cursor_cli', 'minimax_m27', 'minimax_m27_cli'] as $existing) {
            $this->assertTrue($router->supports($existing), "existing driver {$existing} must remain canonical");
        }
    }

    public function test_driver_status_lists_hermes_cli_and_stays_read_only(): void
    {
        $this->bindFakeHermes($this->okResult());
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);

        $status = $router->driverStatus();
        $providers = array_map(static fn (array $d): string => (string) $d['provider'], $status['drivers']);
        $this->assertContains('hermes_cli', $providers, 'router status must list hermes_cli');
        // Existing drivers still listed.
        foreach (['atlas-local', 'claude_cli', 'codex_cli', 'gemini_cli', 'cursor_cli'] as $existing) {
            $this->assertContains($existing, $providers, "status must still list {$existing}");
        }

        $hermes = $router->driverStatus('hermes_cli');
        $this->assertSame('atlas.forge.provider_driver_config_status.v1', $hermes['schema_version']);
        $this->assertSame('hermes_cli', $hermes['provider']);
        $this->assertTrue((bool) $hermes['configured'], 'fake hermes provider resolves → configured');
        $this->assertTrue($router->isConfigured('hermes_cli'));
        $this->assertContains('hermes_cli', $router->configuredDrivers());
    }

    public function test_invoke_routes_to_hermes_and_maps_result(): void
    {
        $fake = $this->bindFakeHermes($this->okResult());
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);

        $result = $router->invoke('hermes_cli', 'hermes_cli_default', [
            'schema_version' => 'atlas.forge.provider_invocation_prompt.v1',
            'rendered_prompt_text' => 'implement the thing',
        ], [
            'cwd' => sys_get_temp_dir(),
            'obra_id' => 'smoke-obra',
            'role' => 'primary_builder',
            'dispatch_id' => 'dispatch-1',
        ]);

        $this->assertSame(1, $fake->calls, 'invoke must reach the fake hermes provider exactly once');
        $this->assertSame('hermes_cli', $result['provider']);
        $this->assertTrue((bool) $result['provider_called'], 'fake success → provider_called=true');
        $this->assertTrue((bool) $result['external_provider_call']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(4242, $result['duration_ms']);
        $this->assertNull($result['blocker']);
        $this->assertSame(['app/Example.php'], $result['changed_files']);
        $this->assertSame(64, strlen((string) $result['stdout_hash']));
    }

    public function test_invoke_pins_workspace_into_hermes_job_payload(): void
    {
        $workspace = sys_get_temp_dir();
        $fake = $this->bindFakeHermes($this->okResult());
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);

        $router->invoke('hermes_cli', null, ['rendered_prompt_text' => 'x'], ['cwd' => $workspace]);

        // workdirForJob() reads tool_permissions.workspace || workspace; pin both.
        $this->assertSame($workspace, data_get($fake->lastJob?->payload, 'workspace'));
        $this->assertSame($workspace, data_get($fake->lastJob?->payload, 'tool_permissions.workspace'));
        // 'danger' (not 'write') so HermesCliProvider passes --yolo and Hermes edits
        // autonomously — with 'write' Hermes answers but never mutates the worktree.
        $this->assertSame('danger', data_get($fake->lastJob?->payload, 'tool_permissions.mode'));
        // Atlas hands Hermes its own default sentinel, never a non-Hermes model.
        $this->assertSame('hermes_cli_default', $fake->lastJob?->model);
    }

    public function test_invoke_derives_changed_files_from_workspace_git_diff(): void
    {
        // A real git workspace with a tracked edit + an untracked add. The fake's
        // metadata claims changed_files=['app/Example.php'], so a passing assertion
        // proves the driver derives changed_files from the workspace `git diff`/
        // `git ls-files` (the mutating-provider contract) and that git takes
        // precedence over stale metadata.
        $workspace = sys_get_temp_dir().'/atlas-forge-hermes-git-'.bin2hex(random_bytes(4));
        mkdir($workspace.'/src', 0o755, true);
        file_put_contents($workspace.'/src/Kept.php', "<?php\n// baseline\n");
        foreach ([
            ['git', 'init', '-q'],
            ['git', 'add', '-A'],
            ['git', '-c', 'user.email=t@t', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'baseline'],
        ] as $argv) {
            (new \Symfony\Component\Process\Process($argv, $workspace))->run();
        }
        file_put_contents($workspace.'/src/Kept.php', "<?php\n// edited by hermes\n");
        file_put_contents($workspace.'/src/New.php', "<?php\n// new file\n");

        $fake = $this->bindFakeHermes($this->okResult());
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);

        $result = $router->invoke('hermes_cli', 'hermes_cli_default', ['rendered_prompt_text' => 'edit'], ['cwd' => $workspace]);

        $changed = $result['changed_files'];
        sort($changed);
        $this->assertSame(['src/Kept.php', 'src/New.php'], $changed, 'changed_files must come from the workspace git diff');

        (new \Symfony\Component\Process\Process(['rm', '-rf', $workspace]))->run();
    }

    public function test_driver_invoke_returns_full_schema_and_maps_failure(): void
    {
        $failure = new AiProviderResult(
            ok: false,
            output: '',
            command: ['hermes', 'chat', '--quiet'],
            exitCode: 1,
            durationMs: 12,
            stdout: '',
            stderr: 'hermes runtime rejected the mission',
            errorCode: 'hermes_runtime_rejected',
            errorMessage: 'rejected',
        );
        $fake = $this->bindFakeHermes($failure);
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);

        $result = $router->driverInvoke('hermes_cli', [
            'provider' => 'hermes_cli',
            'model' => 'hermes_cli_default',
            'prompt' => ['rendered_prompt_text' => 'do it'],
            'cwd' => sys_get_temp_dir(),
            'timeout_seconds' => 30,
        ]);

        $this->assertSame(1, $fake->calls);
        $this->assertSame('atlas.forge.provider_driver_result.v1', $result['schema_version']);
        $this->assertSame('hermes_cli', $result['provider']);
        $this->assertTrue((bool) $result['provider_called'], 'a non-ok result still means the provider WAS called');
        $this->assertSame(1, $result['exit_code']);
        $this->assertContains('hermes_runtime_rejected', $result['blockers']);
        $this->assertSame('hermes_runtime_rejected', $result['failure_type']);
    }

    public function test_invoke_fails_closed_when_no_hermes_provider_resolves(): void
    {
        // Bind a manager whose hermes_cli factory throws → adapter cannot resolve.
        /** @var AiProviderManager $manager */
        $manager = app(AiProviderManager::class);
        $manager->registerDriver('hermes_cli', static function (): AiProvider {
            throw new \RuntimeException('hermes runtime not installed on this host');
        });
        $this->app->instance(AiProviderManager::class, $manager);

        $router = app(AtlasForgeProviderInvocationDriverRouter::class);

        $this->assertFalse($router->isConfigured('hermes_cli'), 'unresolvable provider → not configured');

        $result = $router->invoke('hermes_cli', 'hermes_cli_default', ['rendered_prompt_text' => 'x'], [
            'cwd' => sys_get_temp_dir(),
        ]);

        $this->assertFalse((bool) $result['provider_called'], 'no provider → no external call');
        $this->assertFalse((bool) $result['external_provider_call']);
        $this->assertNotNull($result['blocker']);
    }
}

/**
 * Minimal in-memory {@see AiProvider} test double for `hermes_cli`. Captures
 * the job + prompt it was handed and returns a canned AiProviderResult. NEVER
 * spawns a process or spends a token.
 */
final class FakeHermesProvider implements AiProvider
{
    public int $calls = 0;

    public ?AiJob $lastJob = null;

    public ?string $lastPrompt = null;

    public function __construct(
        private readonly AiProviderResult $result,
        private readonly ?\Closure $onRun = null,
    ) {}

    public function key(): string
    {
        return 'hermes_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        $this->calls++;
        $this->lastJob = $job;
        $this->lastPrompt = $prompt;
        if ($this->onRun !== null) {
            ($this->onRun)($job, $prompt);
        }

        return $this->result;
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        return $this->run($job, $prompt);
    }

    public function health(): AiProviderHealthCheck
    {
        return new AiProviderHealthCheck(
            provider: 'hermes_cli',
            status: 'ok',
            message: 'fake',
        );
    }
}
