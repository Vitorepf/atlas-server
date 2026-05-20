<?php

namespace Tests\Unit\Ai\PersistentContext;

use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\AiIntentRouter;
use App\Services\Ai\AiPromptBuilder;
use App\Services\Ai\AiSkillStore;
use App\Services\Ai\Search\SessionSearchService;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use Tests\TestCase;

class PersistentContextPromptBuilderTest extends TestCase
{
    public function test_persistent_context_runtime_projects_provider_handoff_into_prompt(): void
    {
        $section = $this->persistentContextSection([
            'payload' => [
                'persistent_context' => [
                    'schema_version' => 'atlas.persistent_context.runtime.v1',
                    'status' => 'ready',
                    'context_pack_hash' => str_repeat('a', 64),
                    'must_know_ledger_hash' => str_repeat('b', 64),
                    'sufficiency' => [
                        'status' => 'sufficient',
                        'blockers' => [],
                    ],
                    'provider_handoff' => [
                        'schema_version' => 'atlas.persistent_context.provider_handoff.v1',
                        'context_pack_hash' => str_repeat('c', 64),
                        'execution_allowed' => true,
                        'read_first' => [
                            'docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md',
                        ],
                        'required_before_execution' => [
                            'read_context_pack',
                            'preserve_must_know_ledger',
                        ],
                    ],
                    'must_know_ledger' => [
                        'items' => [
                            [
                                'kind' => 'invariant',
                                'digest' => 'provider sessions must never start without APCR context pack',
                            ],
                            [
                                'kind' => 'decision',
                                'digest' => 'Atlas Dev must use Hyperflow before provider execution',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('# Atlas Persistent Context Runtime', $section);
        $this->assertStringContainsString('Execution allowed: yes', $section);
        $this->assertStringContainsString('Read-first refs:', $section);
        $this->assertStringContainsString('atlas-ai-session-bootstrap.md', $section);
        $this->assertStringContainsString('Must-know ledger:', $section);
        $this->assertStringContainsString('provider sessions must never start without APCR context pack', $section);
        $this->assertStringContainsString('Antes de executar:', $section);
        $this->assertStringContainsString('preserve_must_know_ledger', $section);
    }

    public function test_persistent_context_runtime_projects_blocked_sufficiency_into_prompt(): void
    {
        $section = $this->persistentContextSection([
            'payload' => [
                'persistent_context' => [
                    'schema_version' => 'atlas.persistent_context.runtime.v1',
                    'status' => 'blocked',
                    'context_pack_hash' => str_repeat('a', 64),
                    'must_know_ledger_hash' => str_repeat('b', 64),
                    'sufficiency' => [
                        'status' => 'blocked',
                        'blockers' => ['missing_context_refs'],
                    ],
                    'provider_handoff' => [
                        'context_pack_hash' => str_repeat('c', 64),
                        'execution_allowed' => false,
                    ],
                    'must_know_ledger' => [
                        'items' => [],
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('Execution allowed: no', $section);
        $this->assertStringContainsString('Blockers de contexto:', $section);
        $this->assertStringContainsString('missing_context_refs', $section);
        $this->assertStringContainsString('declare o bloqueio antes de executar', $section);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function persistentContextSection(array $options): string
    {
        $builder = new AiPromptBuilder(
            $this->createMock(AiSkillStore::class),
            $this->createMock(AiIntentRouter::class),
            $this->createMock(AiContextPackBuilder::class),
            $this->createMock(SkillDiscoveryService::class),
            $this->createMock(SkillBundleStore::class),
            $this->createMock(SessionSearchService::class),
        );

        $method = new \ReflectionMethod(AiPromptBuilder::class, 'persistentContextPromptSection');
        $method->setAccessible(true);

        return (string) $method->invoke($builder, $options);
    }
}
