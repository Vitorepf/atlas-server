<?php

namespace Tests\Unit\Ai\Hermes;

use App\Services\Ai\Hermes\HermesMissionCapabilitiesFactory;
use Tests\TestCase;

class HermesMissionCapabilitiesFactoryTest extends TestCase
{
    private function factory(): HermesMissionCapabilitiesFactory
    {
        return app(HermesMissionCapabilitiesFactory::class);
    }

    public function test_build_defaults_all_keys_empty_or_false_with_no_payload(): void
    {
        $capabilities = $this->factory()->build([], []);

        $this->assertSame('atlas.hermes.mission_capabilities.v1', $capabilities['schema_version']);
        $this->assertSame([], $capabilities['toolsets']);
        $this->assertSame([], $capabilities['skills']);
        $this->assertSame([], $capabilities['mcp']);
        $this->assertSame([], $capabilities['delegation']);
        $this->assertSame([], $capabilities['hooks']);
        $this->assertFalse($capabilities['checkpoints']);
        $this->assertSame([], $capabilities['context_references']);
        $this->assertFalse($capabilities['browser']);
        $this->assertFalse($capabilities['code_execution']);
        $this->assertFalse($capabilities['vision']);
        $this->assertFalse($capabilities['image_gen']);
        $this->assertFalse($capabilities['voice']);
        $this->assertSame([], $capabilities['requested_capability_ids']);
    }

    public function test_legacy_toolsets_csv_folds_into_toolsets_and_requested_ids(): void
    {
        $capabilities = $this->factory()->build([
            'hermes' => [
                'toolsets' => 'browser, code_execution , browser',
            ],
        ], []);

        $this->assertSame(['browser', 'code_execution'], $capabilities['toolsets']);
        $this->assertContains('toolset:browser', $capabilities['requested_capability_ids']);
        $this->assertContains('toolset:code_execution', $capabilities['requested_capability_ids']);
    }

    public function test_legacy_csv_and_structured_toolsets_merge_and_dedupe(): void
    {
        $capabilities = $this->factory()->build([
            'hermes' => [
                'toolsets' => 'browser,files',
                'capabilities' => [
                    'toolsets' => ['files', 'shell'],
                ],
            ],
        ], []);

        $this->assertSame(['files', 'shell', 'browser'], $capabilities['toolsets']);
    }

    public function test_structured_capabilities_build_requested_ids_with_canonical_class_prefixes(): void
    {
        $capabilities = $this->factory()->build([
            'hermes' => [
                'capabilities' => [
                    'toolsets' => ['browser'],
                    'skills' => ['pdf'],
                    'mcp' => ['github'],
                    'hooks' => ['pre_tool_call' => true],
                    'delegation' => ['supported' => true],
                    'checkpoints' => true,
                    'context_references' => [
                        ['type' => 'file', 'value' => '/repo/docs/spec.md', 'line_range' => '1-40'],
                    ],
                ],
            ],
        ], []);

        $ids = $capabilities['requested_capability_ids'];

        $this->assertContains('toolset:browser', $ids);
        $this->assertContains('skill:pdf', $ids);
        $this->assertContains('mcp_server:github', $ids);
        $this->assertContains('hook:pre_tool_call', $ids);
        $this->assertContains('delegation:supported', $ids);
        $this->assertContains('flag:checkpoints', $ids);
        $this->assertContains('context_ref:/repo/docs/spec.md', $ids);

        $this->assertTrue($capabilities['checkpoints']);
        $this->assertSame('1-40', $capabilities['context_references'][0]['line_range']);
        $this->assertSame('file', $capabilities['context_references'][0]['type']);
        $this->assertTrue($capabilities['delegation']['supported']);
        $this->assertSame(['pre_tool_call' => true], $capabilities['hooks']);
    }

    public function test_high_level_feature_flags_produce_feature_and_toolset_ids(): void
    {
        $capabilities = $this->factory()->build([
            'hermes' => [
                'capabilities' => [
                    'browser' => true,
                    'code_execution' => true,
                    'vision' => true,
                    'image_gen' => true,
                    'voice' => true,
                ],
            ],
        ], []);

        $ids = $capabilities['requested_capability_ids'];

        $this->assertContains('toolset:browser', $ids);
        $this->assertContains('toolset:code_execution', $ids);
        $this->assertContains('feature:vision', $ids);
        $this->assertContains('feature:image_gen', $ids);
        $this->assertContains('feature:voice', $ids);

        // ids are de-duplicated even though browser appears via flag + feature folding.
        $this->assertSame(array_values(array_unique($ids)), $ids);
    }

    public function test_string_context_reference_defaults_to_file_type(): void
    {
        $capabilities = $this->factory()->build([
            'hermes' => [
                'capabilities' => [
                    'context_references' => ['/repo/AGENTS.md'],
                ],
            ],
        ], []);

        $this->assertSame('file', $capabilities['context_references'][0]['type']);
        $this->assertSame('/repo/AGENTS.md', $capabilities['context_references'][0]['value']);
        $this->assertContains('context_ref:/repo/AGENTS.md', $capabilities['requested_capability_ids']);
    }
}
