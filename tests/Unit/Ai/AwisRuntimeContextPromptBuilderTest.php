<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\AiIntentRouter;
use App\Services\Ai\AiPromptBuilder;
use App\Services\Ai\AiSkillStore;
use App\Services\Ai\Search\SessionSearchService;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use Tests\TestCase;

class AwisRuntimeContextPromptBuilderTest extends TestCase
{
    public function test_awis_runtime_context_projects_provider_safe_workspace_memory_into_prompt(): void
    {
        $section = $this->awisRuntimeContextSection([
            'payload' => [
                'awis_runtime_context' => [
                    'schema_version' => 'atlas.awis.runtime_context_hint.v1',
                    'workspace' => [
                        'key' => 'atlas',
                        'name' => 'Atlas',
                        'root_path_known' => true,
                    ],
                    'never_start_cold' => true,
                    'load_first' => [
                        'session-gold:Space forte',
                        '/Users/vitorepf/private/should-not-leak',
                    ],
                    'use_as_summary' => [
                        'Spaces organizam conversas; comparação abre sessões lado a lado.',
                    ],
                    'validate_with' => [
                        'npm run atlas-ai:test',
                    ],
                    'avoid_loading' => [
                        'raw_conversation',
                    ],
                    'working_set' => [
                        'files' => [
                            'apps/desktop/src/surfaces/atlas-ai/useAtlasAi.ts',
                        ],
                        'docs' => [
                            'docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md',
                        ],
                        'commands' => [
                            'npx tsc -b',
                        ],
                    ],
                    'evidence_gate' => [
                        'verify_before_trust' => [
                            'rodar teste antes de promover memória',
                        ],
                        'human_boundary' => [
                            'decisão de produto fica com operador',
                            'operator_input',
                        ],
                    ],
                    'space_context' => [
                        'active_spaces' => [
                            'AWIS cérebro vivo',
                        ],
                        'strongest_spaces' => [
                            'Fluxo Atlas AI',
                        ],
                        'load_first' => [
                            'Space pack:AWIS cérebro vivo',
                        ],
                        'carry_forward' => [
                            'decisão:Space organiza contexto',
                        ],
                        'validate_before_use' => [
                            'revalidar Space pack',
                        ],
                        'human_boundary' => [
                            'humano confirma risco do Space',
                        ],
                        'artifact_refs' => [
                            'artifact-awis-123',
                        ],
                    ],
                    'artifact_context' => [
                        'replay_ready' => true,
                        'latest_artifact_hash' => 'artifact-awis-123',
                        'load_order' => [
                            'artifact:startup snapshot',
                        ],
                        'validate_with' => [
                            'artifact:revalidar snapshot',
                        ],
                        'reusable_patterns' => [
                            'não nascer frio',
                        ],
                        'strongest_spaces' => [
                            'AWIS cérebro vivo',
                        ],
                        'warnings' => [
                            'artifact antigo:revalidar',
                        ],
                    ],
                    'next_session' => [
                        'first_load' => [
                            'carregar Space pack ativo',
                        ],
                        'validate_with' => [
                            'comparar com Evidence Ledger',
                        ],
                        'promote_when' => [
                            'teste verde e operador confirma',
                        ],
                        'demote_when' => [
                            'falhou validação',
                        ],
                    ],
                    'continue_learning' => [
                        'record_outcome' => true,
                        'update_memory' => true,
                        'update_space_pack' => true,
                        'preserve_artifact_after_success' => true,
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('# Atlas Workspace Intelligence System', $section);
        $this->assertStringContainsString('Workspace: Atlas', $section);
        $this->assertStringContainsString('Nunca iniciar frio: sim', $section);
        $this->assertStringContainsString('Carregar primeiro:', $section);
        $this->assertStringContainsString('session-gold:Space forte', $section);
        $this->assertStringContainsString('Resumo ouro:', $section);
        $this->assertStringContainsString('Spaces organizam conversas', $section);
        $this->assertStringContainsString('Validar com:', $section);
        $this->assertStringContainsString('npm run atlas-ai:test', $section);
        $this->assertStringContainsString('Working set provável:', $section);
        $this->assertStringContainsString('apps/desktop/src/surfaces/atlas-ai/useAtlasAi.ts', $section);
        $this->assertStringContainsString('Evidence gate:', $section);
        $this->assertStringContainsString('Spaces vivos:', $section);
        $this->assertStringContainsString('Space ativo: AWIS cérebro vivo', $section);
        $this->assertStringContainsString('carregar: Space pack:AWIS cérebro vivo', $section);
        $this->assertStringContainsString('artifact: artifact-awis-123', $section);
        $this->assertStringContainsString('Artifacts reutilizáveis:', $section);
        $this->assertStringContainsString('artifact recente: artifact-awis-123', $section);
        $this->assertStringContainsString('padrão reutilizável: não nascer frio', $section);
        $this->assertStringContainsString('Space preservado: AWIS cérebro vivo', $section);
        $this->assertStringContainsString('Próxima sessão · carregar:', $section);
        $this->assertStringContainsString('Aprendizado contínuo:', $section);
        $this->assertStringContainsString('registrar resultado real', $section);
        $this->assertStringContainsString('atualizar memória AWIS', $section);
        $this->assertStringContainsString('atualizar Space pack', $section);
        $this->assertStringContainsString('preservar artifact após sucesso', $section);
        $this->assertStringNotContainsString('/Users/', $section);
        $this->assertStringNotContainsString('raw_conversation', $section);
        $this->assertStringNotContainsString('operator_input', $section);
        $this->assertStringNotContainsString('response_text', $section);
    }

    public function test_awis_runtime_context_ignores_missing_or_wrong_schema(): void
    {
        $this->assertSame('', $this->awisRuntimeContextSection(['payload' => []]));
        $this->assertSame('', $this->awisRuntimeContextSection([
            'payload' => [
                'awis_runtime_context' => [
                    'schema_version' => 'atlas.awis.runtime_context_hint.v0',
                    'never_start_cold' => true,
                ],
            ],
        ]));
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function awisRuntimeContextSection(array $options): string
    {
        $builder = new AiPromptBuilder(
            $this->createMock(AiSkillStore::class),
            $this->createMock(AiIntentRouter::class),
            $this->createMock(AiContextPackBuilder::class),
            $this->createMock(SkillDiscoveryService::class),
            $this->createMock(SkillBundleStore::class),
            $this->createMock(SessionSearchService::class),
        );

        $method = new \ReflectionMethod(AiPromptBuilder::class, 'awisRuntimeContextPromptSection');
        $method->setAccessible(true);

        return (string) $method->invoke($builder, $options);
    }
}
