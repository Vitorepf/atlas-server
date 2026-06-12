<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\MinimaxFirst;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Programming\AtlasDev\MinimaxFirst\AtlasCodexPlannerService;
use PHPUnit\Framework\TestCase;

final class AtlasCodexPlannerServiceTest extends TestCase
{
    public function test_planning_prompt_carries_detail_anchor_and_quality_guardrails(): void
    {
        $service = new AtlasCodexPlannerService();
        $method = new \ReflectionMethod($service, 'buildPlanningPrompt');

        $prompt = $method->invoke($service, [
            'title' => 'Wire bounded policy signal',
            'detail' => 'Wire only aPerClassChangedFileCeilingSignalWiring() and prove it with the focused test.',
            'target_method' => 'aPerClassChangedFileCeilingSignalWiring',
            'surgical_anchor' => 'file:app/Policy.php; target_method:aPerClassChangedFileCeilingSignalWiring',
        ], [
            'app/Policy.php',
            'tests/Unit/PolicyTest.php',
        ], [
            'php artisan test tests/Unit/PolicyTest.php',
        ]);

        $this->assertStringContainsString('Wire only aPerClassChangedFileCeilingSignalWiring()', $prompt);
        $this->assertStringContainsString('target_method=aPerClassChangedFileCeilingSignalWiring', $prompt);
        $this->assertStringContainsString('surgical_anchor=file:app/Policy.php; target_method:aPerClassChangedFileCeilingSignalWiring', $prompt);
        $this->assertStringContainsString('Focused validation command: php artisan test tests/Unit/PolicyTest.php', $prompt);
        $this->assertStringContainsString('preserve existing methods/tests', $prompt);
        $this->assertStringContainsString('no large test deletion', $prompt);
        $this->assertStringContainsString('Do not run shell commands. Do not execute tests. Do not edit files.', $prompt);
        $this->assertStringNotContainsString("Must pass: php artisan test\n", $prompt);
    }

    public function test_planning_prompt_never_falls_back_to_broad_php_artisan_test(): void
    {
        $service = new AtlasCodexPlannerService();
        $method = new \ReflectionMethod($service, 'buildPlanningPrompt');

        $prompt = $method->invoke($service, [
            'title' => 'Plan bounded slice',
            'detail' => 'Return a scoped implementation plan.',
        ], [
            'app/Policy.php',
        ], [
            'git diff --check',
        ]);

        $this->assertStringContainsString('Focused validation command: focused validation command not declared', $prompt);
        $this->assertStringNotContainsString('Must pass: php artisan test', $prompt);
        $this->assertStringNotContainsString('Focused validation command: php artisan test', $prompt);
    }

    public function test_plan_routes_through_ai_provider_manager_codex_driver_with_read_only_job_contract(): void
    {
        $capturedJob = null;
        $capturedPrompt = null;
        $provider = $this->codexProvider(
            result: new AiProviderResult(
                ok: true,
                output: '{"file":"app/Policy.php","method":"apply","signature":"apply(): void","logic":"narrow fix","constraints":["read-only plan"]}',
                command: ['codex'],
                exitCode: 0,
                durationMs: 5,
                stdout: '',
                stderr: '',
            ),
            capturedJob: $capturedJob,
            capturedPrompt: $capturedPrompt,
        );
        $requested = [];

        $plan = (new AtlasCodexPlannerService($this->managerFor($provider, $requested)))->plan(
            finding: [
                'title' => 'Wire bounded policy signal',
                'detail' => 'Return a scoped implementation plan.',
            ],
            allowedFiles: ['app/Policy.php'],
            validationCommands: ['php artisan test tests/Unit/PolicyTest.php'],
            repoRoot: '/repo/root',
        );

        $this->assertSame(['codex_cli'], $requested);
        $this->assertSame('app/Policy.php', $plan['file'] ?? null);
        $this->assertSame('apply', $plan['method'] ?? null);
        $this->assertInstanceOf(AiJob::class, $capturedJob);
        $this->assertStringContainsString('Planning only', (string) $capturedPrompt);
        $this->assertStringContainsString('Do not run shell commands', (string) $capturedPrompt);
        $this->assertSame('atlas_dev_codex_planner', $capturedJob->kind);
        $this->assertSame('codex_cli', $capturedJob->provider);
        $this->assertSame(AtlasCodexPlannerService::TIMEOUT_SECONDS, $capturedJob->timeout_seconds);
        $this->assertSame('/repo/root', data_get($capturedJob->payload, 'workspace'));
        $this->assertSame('read', data_get($capturedJob->payload, 'tool_permissions.mode'));
        $this->assertSame('/repo/root', data_get($capturedJob->payload, 'tool_permissions.workspace'));
        $this->assertSame('read-only', data_get($capturedJob->payload, 'tool_permissions.codex_sandbox'));
        $this->assertSame('AiProviderManager::get(codex_cli)->run', data_get($capturedJob->payload, 's50_spine_contract.invocation'));
        $this->assertTrue((bool) data_get($capturedJob->metadata, 's50_spine_migration'));
    }

    public function test_plan_returns_null_when_codex_provider_fails(): void
    {
        $requested = [];
        $plan = (new AtlasCodexPlannerService($this->managerFor(
            $this->codexProvider(new AiProviderResult(
                ok: false,
                output: '',
                command: ['codex'],
                exitCode: 1,
                durationMs: 5,
                stdout: '',
                stderr: 'provider failed',
                errorCode: 'provider_failed',
            )),
            $requested,
        )))->plan(
            finding: ['title' => 'Plan bounded slice'],
            allowedFiles: ['app/Policy.php'],
            validationCommands: [],
            repoRoot: '/repo/root',
        );

        $this->assertSame(['codex_cli'], $requested);
        $this->assertNull($plan);
    }

    public function test_configured_uses_provider_health(): void
    {
        $online = new AtlasCodexPlannerService($this->managerFor($this->codexProvider(healthStatus: 'online')));
        $offline = new AtlasCodexPlannerService($this->managerFor($this->codexProvider(healthStatus: 'offline')));

        $this->assertSame(['available' => true, 'blocker' => null], $online->configured());
        $this->assertSame(['available' => false, 'blocker' => 'codex_provider_offline'], $offline->configured());
    }

    private function managerFor(AiProvider $provider, array &$requested = []): AiProviderManager
    {
        return new class($provider, $requested) extends AiProviderManager {
            /**
             * @param list<string|null> $requested
             */
            public function __construct(
                private readonly AiProvider $provider,
                private array &$requested,
            ) {}

            public function get(?string $provider = null): AiProvider
            {
                $this->requested[] = $provider;

                return $this->provider;
            }

            public function keys(): array
            {
                return ['codex_cli'];
            }
        };
    }

    private function codexProvider(
        ?AiProviderResult $result = null,
        string $healthStatus = 'online',
        ?AiJob &$capturedJob = null,
        ?string &$capturedPrompt = null,
    ): AiProvider {
        return new class($result, $healthStatus, $capturedJob, $capturedPrompt) implements AiProvider {
            public function __construct(
                private readonly ?AiProviderResult $result,
                private readonly string $healthStatus,
                private ?AiJob &$capturedJob,
                private ?string &$capturedPrompt,
            ) {}

            public function key(): string
            {
                return 'codex_cli';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                $this->capturedJob = $job;
                $this->capturedPrompt = $prompt;

                return $this->result ?? new AiProviderResult(
                    ok: true,
                    output: '',
                    command: ['codex'],
                    exitCode: 0,
                    durationMs: 1,
                    stdout: '',
                    stderr: '',
                );
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                return $this->run($job, $prompt);
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck('codex_cli', $this->healthStatus, 'test provider');
            }
        };
    }
}
