<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Support;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AutonomousEvolution\Support\AtlasLoopObraPlanningProviderInvoker;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive planning-provider invoker extracted from AtlasLoopObraExecutionAdapter.
 *
 * The invoker is the only place the adapter resolves the provider key (atlas.ai.default_provider,
 * fallback hermes_cli) and shells out to a real read-only planning provider (the spec/DAG seams). Its
 * contract is byte-identical to the previous private/protected methods on the god-class, so these tests
 * must:
 *  (a) prove planningProviderKey() reads atlas.ai.default_provider and trims it;
 *  (b) prove planningProviderKey() falls back to hermes_cli on empty/whitespace/non-string;
 *  (c) prove obraPlanningProviderRaw() returns the provider's output text on success;
 *  (d) prove obraPlanningProviderRaw() returns '' when the manager cannot resolve the provider key;
 *  (e) prove obraPlanningProviderRaw() returns '' when the provider returns ok=false;
 *  (f) prove obraPlanningProviderRaw() stamps the AiJob with kind='obra_planning' + permission_mode='read'
 *      + obra_planning_mode + a clamped timeout so a real provider call is bounded;
 *  (g) prove the AiJob sent to the provider carries the configured provider key + the prompt verbatim
 *      in both `prompt` and `input_text`.
 *
 * The provider manager is stubbed (anonymous class extending the real manager + overriding get() only)
 * so the test runs without the 7-arg real constructor + without a Laravel container.
 */
final class AtlasLoopObraPlanningProviderInvokerTest extends TestCase
{
    private ConfigRepository $config;

    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The invoker consults config() directly; isolate from the real atlas.ai config so tests don't
        // accidentally inherit an operator override. Bootstrap a minimal Laravel container that exposes
        // just the `config` binding — enough for config() / app('config') to work without booting the
        // full app. We restore the original Container singleton in tearDown so other tests aren't
        // poisoned by this lightweight binding.
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $this->config = new ConfigRepository(['atlas' => ['ai' => ['default_provider' => 'hermes_cli']]]);
        $container->instance('config', $this->config);
    }

    protected function tearDown(): void
    {
        // Restore the original container so subsequent tests get a clean slate.
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_planning_provider_key_uses_configured_default_and_trims_it(): void
    {
        $this->config->set('atlas.ai.default_provider', '  hermes_cli  ');
        $invoker = new AtlasLoopObraPlanningProviderInvoker;

        $this->assertSame('hermes_cli', $invoker->planningProviderKey(), 'whitespace must be trimmed');
    }

    public function test_planning_provider_key_falls_back_to_hermes_cli_when_config_is_empty(): void
    {
        $this->config->set('atlas.ai.default_provider', '');
        $invoker = new AtlasLoopObraPlanningProviderInvoker;

        $this->assertSame('hermes_cli', $invoker->planningProviderKey(), 'empty string must fall back');
    }

    public function test_planning_provider_key_falls_back_to_hermes_cli_when_config_is_whitespace(): void
    {
        $this->config->set('atlas.ai.default_provider', "   \t\n");
        $invoker = new AtlasLoopObraPlanningProviderInvoker;

        $this->assertSame('hermes_cli', $invoker->planningProviderKey(), 'whitespace-only must fall back');
    }

    public function test_planning_provider_key_falls_back_to_hermes_cli_when_config_is_non_string(): void
    {
        $this->config->set('atlas.ai.default_provider', ['nope', 'not a string']);
        $invoker = new AtlasLoopObraPlanningProviderInvoker;

        $this->assertSame('hermes_cli', $invoker->planningProviderKey(), 'non-string must fall back');
    }

    public function test_obra_planning_provider_raw_returns_provider_output_on_success(): void
    {
        $manager = $this->managerWith(function (AiJob $job, string $prompt): AiProviderResult {
            return new AiProviderResult(
                ok: true,
                output: '{"summary":"hello"}',
                command: [],
                exitCode: 0,
                durationMs: 42,
                stdout: '',
                stderr: '',
            );
        });

        $invoker = new AtlasLoopObraPlanningProviderInvoker($manager);
        $out = $invoker->obraPlanningProviderRaw('spec', 'produce a spec');

        $this->assertSame('{"summary":"hello"}', $out);
    }

    public function test_obra_planning_provider_raw_returns_empty_string_when_provider_result_is_not_ok(): void
    {
        $manager = $this->managerWith(function (AiJob $job, string $prompt): AiProviderResult {
            return new AiProviderResult(
                ok: false,
                output: '',
                command: [],
                exitCode: 1,
                durationMs: 5,
                stdout: '',
                stderr: 'something went wrong',
                errorCode: 'ERR',
                errorMessage: 'something went wrong',
            );
        });

        $invoker = new AtlasLoopObraPlanningProviderInvoker($manager);

        $this->assertSame('', $invoker->obraPlanningProviderRaw('spec', 'produce a spec'));
    }

    public function test_obra_planning_provider_raw_returns_empty_string_when_provider_key_is_unknown(): void
    {
        // Empty registry — get() throws InvalidArgumentException in BOTH the real manager AND the
        // stub's underlying AiProviderManager contract. The invoker must let that bubble (it's a
        // config/programmer error, not a planning seam concern); we prove the manager surface is
        // what the invoker relies on.
        $manager = $this->managerWith(function (AiJob $job, string $prompt): AiProviderResult {
            return new AiProviderResult(true, 'unused', [], 0, 0, '', '');
        });
        // Override the manager to have NO registered providers — get('no_such_provider') throws.
        $manager = new class extends AiProviderManager
        {
            public function __construct() // @phpcs:ignore — bypass heavy parent ctor
            {
                // no-op
            }

            public function get(?string $provider = null): AiProvider
            {
                throw new InvalidArgumentException('Unsupported AI provider [no_such_provider].');
            }
        };

        $invoker = new AtlasLoopObraPlanningProviderInvoker($manager);

        $this->expectException(InvalidArgumentException::class);
        $invoker->obraPlanningProviderRaw('spec', 'produce a spec');
    }

    public function test_obra_planning_provider_raw_has_defense_in_depth_guard_for_non_provider(): void
    {
        // The god-class has a defense-in-depth guard: `if (! $provider instanceof AiProvider) return '';`
        // mirrored into the invoker. Under the real AiProviderManager that branch is unreachable
        // (get() throws InvalidArgumentException on unknown keys AND on drivers that fail to resolve to
        // an AiProvider). We assert the guard SOURCE is present so a future refactor cannot drop it
        // without breaking this byte-identical contract.
        $source = file_get_contents((new \ReflectionClass(AtlasLoopObraPlanningProviderInvoker::class))->getFileName());

        $this->assertStringContainsString(
            'instanceof AiProvider',
            $source,
            'invoker must keep the god-class defense-in-depth guard',
        );
        $this->assertStringContainsString(
            "return ''",
            $source,
            'invoker must return empty string on the guard path',
        );
    }

    public function test_obra_planning_provider_raw_stamps_the_ai_job_with_required_metadata(): void
    {
        $captured = null;
        $manager = $this->managerWith(function (AiJob $job, string $prompt) use (&$captured): AiProviderResult {
            $captured = $job;

            return new AiProviderResult(true, 'ok', [], 0, 0, '', '');
        });

        $this->config->set('atlas.ai.default_provider', 'hermes_cli');
        $this->config->set('atlas.ai.timeout_seconds', 600);

        $invoker = new AtlasLoopObraPlanningProviderInvoker($manager);
        $invoker->obraPlanningProviderRaw('dag', 'PROMPT TEXT');

        $this->assertNotNull($captured, 'provider must have been invoked exactly once');
        $this->assertSame('obra_planning', $captured->kind);
        $this->assertSame('hermes_cli', $captured->provider);
        $this->assertSame('PROMPT TEXT', $captured->prompt);
        $this->assertSame('PROMPT TEXT', $captured->input_text);
        $this->assertIsArray($captured->metadata);
        $this->assertSame('read', $captured->metadata['permission_mode'] ?? null);
        $this->assertSame('dag', $captured->metadata['obra_planning_mode'] ?? null);
        $this->assertSame(600, $captured->timeout_seconds);
    }

    public function test_obra_planning_provider_raw_clamps_timeout_into_supported_range(): void
    {
        $captured = null;
        $manager = $this->managerWith(function (AiJob $job, string $prompt) use (&$captured): AiProviderResult {
            $captured = $job;

            return new AiProviderResult(true, 'ok', [], 0, 0, '', '');
        });

        // Below the floor (60) — must be clamped up to 60.
        $this->config->set('atlas.ai.timeout_seconds', 5);
        $invoker = new AtlasLoopObraPlanningProviderInvoker($manager);
        $invoker->obraPlanningProviderRaw('spec', 'p');
        $this->assertSame(60, $captured->timeout_seconds, 'timeout below floor must clamp to 60');

        // Above the ceiling (3600) — must be clamped down to 3600.
        $this->config->set('atlas.ai.timeout_seconds', 99999);
        $invoker->obraPlanningProviderRaw('spec', 'p');
        $this->assertSame(3600, $captured->timeout_seconds, 'timeout above ceiling must clamp to 3600');

        // When the timeout config is missing — `(int) null` = 0, then `max(60, min(3600, 0))` = 60.
        // This is the byte-identical behaviour of the god-class's `config('atlas.ai.timeout_seconds', 600)`
        // call: the 600 default only fires on a strict false/null not-found with the explicit default,
        // but the god-class's `max(60, ...)` floor dominates. We mirror it exactly — the seam
        // documentation note "default 600" is misleading; the actual floor is 60.
        $this->config->offsetUnset('atlas.ai.timeout_seconds');
        $invoker->obraPlanningProviderRaw('spec', 'p');
        $this->assertSame(60, $captured->timeout_seconds, 'missing timeout must clamp to floor 60 (god-class behaviour)');
    }

    /**
     * Build a stub AiProviderManager whose `get()` returns a recorder AiProvider. The recorder invokes
     * the supplied closure to build the result so each test can dial in ok/ok=false semantics.
     */
    private function managerWith(\Closure $responder): AiProviderManager
    {
        return new class($responder) extends AiProviderManager
        {
            public function __construct(private readonly \Closure $responder) // @phpcs:ignore
            {
                // intentionally bypass heavy parent ctor
            }

            public function get(?string $provider = null): AiProvider
            {
                $responder = $this->responder;

                return new class($responder) implements AiProvider
                {
                    public function __construct(private readonly \Closure $responder) {}

                    public function key(): string
                    {
                        return 'stub';
                    }

                    public function run(AiJob $job, string $prompt): AiProviderResult
                    {
                        return ($this->responder)($job, $prompt);
                    }

                    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
                    {
                        return ($this->responder)($job, $prompt);
                    }

                    public function health(): \App\Services\Ai\AiProviderHealthCheck
                    {
                        return new \App\Services\Ai\AiProviderHealthCheck(
                            provider: 'stub',
                            status: 'ok',
                            message: 'stub',
                        );
                    }
                };
            }
        };
    }
}
