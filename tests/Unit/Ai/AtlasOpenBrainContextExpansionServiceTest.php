<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasOpenBrainContextExpansionService;
use App\Services\Ai\AtlasOpenBrainMcpService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasOpenBrainContextExpansionServiceTest extends TestCase
{
    public function test_expands_evidence_replay_handle_with_provider_safe_ranked_refs(): void
    {
        $objective = 'corrigir bug no repo com teste falhando e evidence replay';

        $payload = app(AtlasOpenBrainContextExpansionService::class)->expand([
            'handle' => 'expand:evidence_replay',
            'objective' => $objective,
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'max_refs' => 4,
        ]);

        $this->assertSame(AtlasOpenBrainContextExpansionService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertContains($payload['status'], ['ready', 'degraded']);
        $this->assertSame('ranking_filtered_source_refs', $payload['mode']);
        $this->assertSame('evidence_replay', data_get($payload, 'handle.source_type'));
        $this->assertSame('targeted_context_expansion', data_get($payload, 'handle.quality_gate_hint'));
        $this->assertGreaterThan(0, data_get($payload, 'expansion.selected_ref_count'));
        $this->assertTrue(data_get($payload, 'policy.provider_safe_only'));
        $this->assertFalse(data_get($payload, 'policy.raw_text_exposed'));
        $this->assertFalse(data_get($payload, 'policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'policy.writes'));

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($objective, $json);

        foreach (data_get($payload, 'expansion.selected_refs') as $ref) {
            $this->assertSame('evidence_replay', $ref['source_type']);
            $this->assertArrayHasKey('source_ref_hash', $ref);
            $this->assertArrayNotHasKey('source_ref', $ref);
            $this->assertNotEmpty($ref['reasons']);
        }
    }

    public function test_recheck_canonical_doc_returns_compact_pack_with_objective_redacted(): void
    {
        $objective = 'objetivo sensivel para conferir canonical docs';

        $payload = app(AtlasOpenBrainContextExpansionService::class)->expand([
            'handle' => 'recheck:canonical_doc',
            'objective' => $objective,
            'workspace' => base_path(),
            'budget' => 1800,
        ]);

        $this->assertContains($payload['status'], ['ready', 'degraded']);
        $this->assertSame('canonical_doc_recheck_pack', $payload['mode']);
        $this->assertSame('canonical_doc', data_get($payload, 'handle.source_type'));
        $this->assertSame('required_source_recheck_before_implementation', data_get($payload, 'handle.quality_gate_hint'));
        $this->assertContains('canonical_doc_recheck_required_before_implementation', $payload['warnings']);
        $this->assertIsArray(data_get($payload, 'expansion.sources_present'));
        $this->assertArrayHasKey('budget', $payload['expansion']);
        $this->assertStringContainsString('[objective redacted]', data_get($payload, 'expansion.markdown_excerpt'));
        $this->assertStringNotContainsString($objective, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertFalse(data_get($payload, 'policy.raw_docs_dumped'));
        $this->assertFalse(data_get($payload, 'policy.raw_tests_dumped'));
    }

    public function test_unsupported_handle_fails_safe_without_provider_or_writes(): void
    {
        $payload = app(AtlasOpenBrainContextExpansionService::class)->expand([
            'handle' => 'expand:unknown_source',
            'objective' => 'qualquer tarefa',
        ]);

        $this->assertSame('unsupported', $payload['status']);
        $this->assertContains('unsupported_source_type', $payload['warnings']);
        $this->assertFalse(data_get($payload, 'policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'policy.writes'));
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:open-brain:expand-context', [
            'handle' => 'expand:evidence_replay',
            'objective' => ['debug repo with tests'],
            '--task-type' => 'debug',
            '--domain' => 'developer',
            '--max-refs' => 4,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasOpenBrainContextExpansionService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('evidence_replay', data_get($payload, 'handle.source_type'));
        $this->assertArrayHasKey('expansion_hash', $payload);
    }

    public function test_mcp_tool_expands_context_handle(): void
    {
        $service = app(AtlasOpenBrainMcpService::class);

        $list = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);
        $this->assertContains('atlas_context_expand', array_column(data_get($list, 'result.tools'), 'name'));

        $response = $service->handleJsonRpc([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'atlas_context_expand',
                'arguments' => [
                    'handle' => 'expand:evidence_replay',
                    'objective' => 'debug repo with tests',
                    'task_type' => 'debug',
                    'domain' => 'developer',
                    'max_refs' => 4,
                ],
            ],
        ]);
        $structured = data_get($response, 'result.structuredContent');

        $this->assertTrue($structured['ok']);
        $this->assertSame('atlas_context_expand', $structured['tool']);
        $this->assertSame(AtlasOpenBrainContextExpansionService::SCHEMA_VERSION, data_get($structured, 'context_expansion.schema_version'));
        $this->assertSame('evidence_replay', data_get($structured, 'context_expansion.handle.source_type'));
        $this->assertFalse(data_get($structured, 'context_expansion.policy.providers_invoked'));
        $this->assertFalse(data_get($structured, 'context_expansion.policy.writes'));
    }
}
