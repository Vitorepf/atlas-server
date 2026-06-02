<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasAiRuntimeSettings;
use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\ClaudeCliProvider;
use App\Services\Ai\CodexCliProvider;
use App\Services\Ai\GeminiCliProvider;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\HermesCliProvider;
use App\Services\Ai\JarvisMlxProvider;
use App\Services\Ai\MinimaxM27CliProvider;
use Tests\TestCase;

final class StubAdmlForProviderManager extends AtlasDecideMetaLearningService
{
    private ?array $route;

    public function __construct(?array $route = null)
    {
        $this->route = $route;
    }

    public function activeRouteFor(string $taskCategory, string $role, ?string $framework = null): ?array
    {
        return $this->route;
    }
}

class AiProviderManagerTest extends TestCase
{
    public function test_default_provider_comes_from_runtime_settings(): void
    {
        $claude = $this->createMock(ClaudeCliProvider::class);
        $codex = $this->createMock(CodexCliProvider::class);
        $gemini = $this->createMock(GeminiCliProvider::class);
        $jarvis = $this->createMock(JarvisMlxProvider::class);
        $hermes = $this->createMock(HermesCliProvider::class);
        $minimax = $this->createMock(MinimaxM27CliProvider::class);
        $settings = $this->createMock(AtlasAiRuntimeSettings::class);
        $settings->method('defaultProvider')->willReturn('gemini_cli');

        $manager = new AiProviderManager($claude, $codex, $gemini, $jarvis, $hermes, $minimax, $settings);

        $this->assertSame($gemini, $manager->get());
        $this->assertSame($codex, $manager->get('codex_cli'));
        $this->assertSame($jarvis, $manager->get('jarvis_mlx'));
        $this->assertSame($hermes, $manager->get('hermes_cli'));
        $this->assertSame($minimax, $manager->get('minimax_m27_cli'));
        $this->assertContains('hermes_cli', $manager->keys());
        $this->assertContains('minimax_m27_cli', $manager->keys());
    }

    /**
     * @return array{0:AiProviderManager,1:array{claude:ClaudeCliProvider,codex:CodexCliProvider,gemini:GeminiCliProvider,jarvis:JarvisMlxProvider,hermes:HermesCliProvider,minimax:MinimaxM27CliProvider}}
     */
    private function buildManager(string $default = 'claude_cli'): array
    {
        $claude = $this->createMock(ClaudeCliProvider::class);
        $codex = $this->createMock(CodexCliProvider::class);
        $gemini = $this->createMock(GeminiCliProvider::class);
        $jarvis = $this->createMock(JarvisMlxProvider::class);
        $hermes = $this->createMock(HermesCliProvider::class);
        $minimax = $this->createMock(MinimaxM27CliProvider::class);
        $settings = $this->createMock(AtlasAiRuntimeSettings::class);
        $settings->method('defaultProvider')->willReturn($default);

        return [
            new AiProviderManager($claude, $codex, $gemini, $jarvis, $hermes, $minimax, $settings),
            ['claude' => $claude, 'codex' => $codex, 'gemini' => $gemini, 'jarvis' => $jarvis, 'hermes' => $hermes, 'minimax' => $minimax],
        ];
    }

    private function buildConsultation(?array $activeRoute): AtlasDecideGatewayConsultationService
    {
        $u = uniqid('', true);
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting(sys_get_temp_dir()."/atlas_apm_kernel_{$u}.jsonl");
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting(sys_get_temp_dir()."/atlas_apm_admission_{$u}.jsonl");
        $adml = new StubAdmlForProviderManager($activeRoute);

        $svc = new AtlasDecideGatewayConsultationService($adml, $kernel, $admission);
        $svc->setLogPathForTesting(sys_get_temp_dir()."/atlas_apm_consult_{$u}.jsonl");

        return $svc;
    }

    public function test_get_recommended_without_consultation_falls_back_to_default(): void
    {
        [$manager, $providers] = $this->buildManager('claude_cli');
        $result = $manager->getRecommended('code_generation', 'primary');

        $this->assertSame($providers['claude'], $result['provider']);
        $this->assertSame('claude_cli', $result['key']);
        $this->assertFalse($result['consulted']);
        $this->assertSame('no_consultation', $result['verdict']);
        $this->assertNull($result['consultation']);
    }

    public function test_get_recommended_follows_learned_route_when_provider_known(): void
    {
        [$manager, $providers] = $this->buildManager('claude_cli');
        $manager->setGatewayConsultation($this->buildConsultation([
            'provider' => 'codex_cli',
            'model' => 'gpt-5-codex',
            'mode' => 'active',
        ]));

        $result = $manager->getRecommended('code_generation', 'primary', 'laravel', 'public');

        $this->assertSame($providers['codex'], $result['provider']);
        $this->assertSame('codex_cli', $result['key']);
        $this->assertTrue($result['consulted']);
        $this->assertSame(
            AtlasDecideGatewayConsultationService::VERDICT_FOLLOW_LEARNED,
            $result['verdict']
        );
    }

    public function test_get_recommended_falls_back_when_learned_provider_unknown(): void
    {
        [$manager, $providers] = $this->buildManager('claude_cli');
        $manager->setGatewayConsultation($this->buildConsultation([
            'provider' => 'mystery_provider',
            'mode' => 'active',
        ]));

        $result = $manager->getRecommended('code_generation', 'primary');

        $this->assertSame($providers['claude'], $result['provider']);
        $this->assertSame('claude_cli', $result['key']);
        $this->assertTrue($result['consulted']);
    }

    public function test_get_recommended_falls_back_when_no_active_route(): void
    {
        [$manager, $providers] = $this->buildManager('gemini_cli');
        $manager->setGatewayConsultation($this->buildConsultation(null));

        $result = $manager->getRecommended('code_generation', 'primary');

        $this->assertSame($providers['gemini'], $result['provider']);
        $this->assertSame('gemini_cli', $result['key']);
        $this->assertSame(
            AtlasDecideGatewayConsultationService::VERDICT_FREE_TO_CHOOSE,
            $result['verdict']
        );
    }

    public function test_get_recommended_blocks_on_sensitive_privacy(): void
    {
        [$manager, $providers] = $this->buildManager('claude_cli');
        $manager->setGatewayConsultation($this->buildConsultation([
            'provider' => 'codex_cli',
            'mode' => 'active',
        ]));

        $result = $manager->getRecommended('code_generation', 'primary', null, 'sensitive');

        // Sensitive/secret/cyber privacy classes block routing — manager falls
        // back to default rather than follow the learned route.
        $this->assertSame($providers['claude'], $result['provider']);
        $this->assertSame('claude_cli', $result['key']);
    }
}
