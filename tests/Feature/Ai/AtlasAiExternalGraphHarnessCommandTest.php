<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiExternalGraphHarnessCommandTest extends TestCase
{
    public function test_command_outputs_read_only_contract(): void
    {
        $exit = Artisan::call('atlas:ai:external-graph-harness', [
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.external_graph_harness.report.v1', data_get($payload, 'external_graph_harness.schema_version'));
        $this->assertSame('implemented_read_only_contract', data_get($payload, 'external_graph_harness.contract.status'));
        $this->assertFalse(data_get($payload, 'external_graph_harness.contract.guardrails.provider_calls_enabled'));
        $this->assertFalse(data_get($payload, 'external_graph_harness.contract.guardrails.writes_memory_registry'));
        $this->assertNull(data_get($payload, 'external_graph_harness.candidate_validation'));
    }

    public function test_command_validates_candidate_file_without_writes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'atlas-external-graph-');
        file_put_contents($file, json_encode($this->candidate(), JSON_THROW_ON_ERROR));

        $exit = Artisan::call('atlas:ai:external-graph-harness', [
            '--candidate-file' => $file,
            '--json' => true,
        ]);

        @unlink($file);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('accepted_read_only_candidate', data_get($payload, 'external_graph_harness.candidate_validation.status'));
        $this->assertSame(1, data_get($payload, 'external_graph_harness.candidate_validation.node_count'));
        $this->assertFalse(data_get($payload, 'external_graph_harness.candidate_validation.guardrails.writes_constelacao'));
    }

    /**
     * @return array<string,mixed>
     */
    private function candidate(): array
    {
        return [
            'schema_version' => 'atlas.external_graph_candidate.v1',
            'source_tool' => 'graphify',
            'source_tool_version' => '0.7.11',
            'source_archive_hash' => str_repeat('b', 64),
            'scan_root' => 'docs/engineering-knowledge-base',
            'generated_at' => '2026-05-09T12:00:00Z',
            'privacy_class' => 'engineering_internal',
            'review_state' => 'candidate',
            'nodes' => [
                [
                    'id' => 'doc:external_graph_harness',
                    'label' => 'External Graph Harness',
                    'kind' => 'doc',
                    'source_refs' => [
                        ['path' => 'docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md'],
                    ],
                ],
            ],
            'edges' => [],
        ];
    }
}
