<?php

namespace Tests\Unit\Ai\Surface;

use App\Services\Ai\Kernel\Envelope\KernelInput;
use App\Services\Ai\Kernel\Surface\SurfaceAdapter;
use App\Services\Ai\Kernel\Surface\SurfaceAttachmentKind;
use App\Services\Ai\Kernel\Surface\SurfaceCapability;
use App\Services\Ai\Kernel\Surface\SurfaceDomainFlowHintKey;
use App\Services\Ai\Kernel\Surface\SurfaceHintKey;
use App\Services\Ai\Surface\Adapters\AtlasApiInteractionSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasAppSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasCodeSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasCliChatSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasCliDevSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasCliForgeSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasMcpReadonlySurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasVaultSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasVoiceRealtimeSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasWorkerSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\BaseSurfaceAdapter;
use App\Services\Ai\Surface\SurfaceAdapterRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SurfaceAdaptersTest extends TestCase
{
    /**
     * @return array<string,array{0:SurfaceAdapter,1:string}>
     */
    public static function adapters(): array
    {
        return [
            'cli dev' => [new AtlasCliDevSurfaceAdapter, 'atlas_cli_dev'],
            'cli chat' => [new AtlasCliChatSurfaceAdapter, 'atlas_cli_chat'],
            'cli forge' => [new AtlasCliForgeSurfaceAdapter, 'atlas_cli_forge'],
            'atlas code' => [new AtlasCodeSurfaceAdapter, 'atlas_code'],
            'api interaction' => [new AtlasApiInteractionSurfaceAdapter, 'atlas_api_interaction'],
            'app' => [new AtlasAppSurfaceAdapter, 'atlas_app'],
            'worker' => [new AtlasWorkerSurfaceAdapter, 'atlas_worker'],
            'mcp readonly' => [new AtlasMcpReadonlySurfaceAdapter, 'atlas_mcp_readonly'],
            'atlas vault' => [new AtlasVaultSurfaceAdapter, 'atlas_vault'],
            'voice realtime' => [new AtlasVoiceRealtimeSurfaceAdapter, 'voice_realtime'],
        ];
    }

    #[DataProvider('adapters')]
    public function test_surface_id_is_stable(SurfaceAdapter $adapter, string $expectedSurfaceId): void
    {
        $this->assertSame($expectedSurfaceId, $adapter->surfaceId());
        $this->assertSame($expectedSurfaceId, $adapter->surfaceId());
    }

    #[DataProvider('adapters')]
    public function test_supported_capabilities_are_not_empty(SurfaceAdapter $adapter, string $expectedSurfaceId): void
    {
        $this->assertSame($expectedSurfaceId, $adapter->surfaceId());
        $capabilities = $adapter->supportedCapabilities();

        $this->assertNotEmpty($capabilities);
        $this->assertSame($capabilities, array_values(array_unique($capabilities)));

        foreach ($capabilities as $capability) {
            $this->assertContains($capability, SurfaceCapability::all());
        }

        if ($adapter->surfaceId() !== 'atlas_mcp_readonly') {
            $this->assertContains(SurfaceCapability::TEXT, $capabilities);
        }
    }

    #[DataProvider('adapters')]
    public function test_normalize_input_preserves_text_hints_attachments_and_locale(SurfaceAdapter $adapter, string $expectedSurfaceId): void
    {
        $this->assertSame($expectedSurfaceId, $adapter->surfaceId());
        $input = $adapter->normalizeInput([
            'input_text' => 'Revise este fluxo sem executar nada.',
            'locale' => 'pt-BR',
            'attachments' => [
                ['id' => 'att-1', 'type' => 'text/plain', 'name' => 'notes.txt'],
            ],
            'image_attachments' => [
                ['id' => 'img-1', 'mime_type' => 'image/png'],
            ],
            'hints' => [
                'risk' => 'low',
            ],
            'domain_id' => 'programming',
            'flow_id' => 'programming.review',
            'routing_task' => 'review',
        ]);

        $this->assertInstanceOf(KernelInput::class, $input);
        $this->assertSame('text', $input->primaryType);
        $this->assertSame('Revise este fluxo sem executar nada.', $input->primaryText);
        $this->assertSame('pt-BR', $input->locale);
        $this->assertSame('low', $input->hints['risk']);
        $this->assertSame($adapter->surfaceId(), $input->hints[SurfaceHintKey::SURFACE_ID]);
        $this->assertSame($adapter->supportedCapabilities(), $input->hints[SurfaceHintKey::SURFACE_CAPABILITIES]);
        $this->assertSame('programming', $input->hints[SurfaceHintKey::DOMAIN_ID]);
        $this->assertSame('programming.review', $input->hints[SurfaceHintKey::FLOW_ID]);
        $this->assertSame('review', $input->hints[SurfaceHintKey::ROUTING_TASK]);
        $this->assertCount(2, $input->attachments);
        $this->assertSame('att-1', $input->attachments[0]['id']);
        $this->assertSame('attachment', $input->attachments[0]['surface_attachment_kind']);
        $this->assertSame('attachments', $input->attachments[0]['surface_attachment_source']);
        $this->assertSame('image', $input->attachments[1]['surface_attachment_kind']);
        $this->assertSame('image_attachments', $input->attachments[1]['surface_attachment_source']);
        $this->assertNotSame('', $input->inputHash);
    }

    #[DataProvider('adapters')]
    public function test_normalize_input_marks_attachment_kind_and_source(SurfaceAdapter $adapter, string $expectedSurfaceId): void
    {
        $this->assertSame($expectedSurfaceId, $adapter->surfaceId());

        $input = $adapter->normalizeInput([
            'text' => 'Analise os anexos.',
            'attachments' => [
                [
                    'id' => 'generic-1',
                    'type' => 'application/json',
                    'surface_attachment_kind' => 'client_supplied_value',
                ],
            ],
            'images' => [
                ['id' => 'image-1', 'mime_type' => 'image/png'],
            ],
            'audio_attachments' => [
                ['id' => 'audio-1', 'mime_type' => 'audio/wav'],
            ],
            'files' => [
                ['id' => 'file-1', 'path' => '/tmp/input.txt'],
            ],
        ]);

        $this->assertCount(4, $input->attachments);
        $this->assertSame('application/json', $input->attachments[0]['type']);
        $this->assertSame(SurfaceAttachmentKind::ATTACHMENT, $input->attachments[0]['surface_attachment_kind']);
        $this->assertSame('attachments', $input->attachments[0]['surface_attachment_source']);

        $this->assertSame('image-1', $input->attachments[1]['id']);
        $this->assertSame(SurfaceAttachmentKind::IMAGE, $input->attachments[1]['surface_attachment_kind']);
        $this->assertSame('images', $input->attachments[1]['surface_attachment_source']);

        $this->assertSame('audio-1', $input->attachments[2]['id']);
        $this->assertSame(SurfaceAttachmentKind::AUDIO, $input->attachments[2]['surface_attachment_kind']);
        $this->assertSame('audio_attachments', $input->attachments[2]['surface_attachment_source']);

        $this->assertSame('file-1', $input->attachments[3]['id']);
        $this->assertSame(SurfaceAttachmentKind::FILE, $input->attachments[3]['surface_attachment_kind']);
        $this->assertSame('files', $input->attachments[3]['surface_attachment_source']);
    }

    #[DataProvider('adapters')]
    public function test_render_output_is_stable_array(SurfaceAdapter $adapter, string $expectedSurfaceId): void
    {
        $this->assertSame($expectedSurfaceId, $adapter->surfaceId());
        $rendered = $adapter->renderOutput(
            ['status' => 'ok', 'text' => 'normalizado', 'metadata' => ['trace_id' => 'trace-1']],
            ['request_id' => 'req-1'],
        );

        $this->assertSame(1, $rendered['schema_version']);
        $this->assertSame($adapter->surfaceId(), $rendered['surface_id']);
        $this->assertSame('ok', $rendered['status']);
        $this->assertSame('normalizado', $rendered['text']);
        $this->assertSame(['trace_id' => 'trace-1'], $rendered['metadata']['kernel_metadata']);
        $this->assertSame(['request_id' => 'req-1'], $rendered['metadata']['context']);
        $this->assertSame($adapter->supportedCapabilities(), $rendered['metadata']['surface_capabilities']);
    }

    #[DataProvider('adapters')]
    public function test_compliance_report_is_ok_for_valid_adapter(SurfaceAdapter $adapter, string $expectedSurfaceId): void
    {
        $this->assertSame($expectedSurfaceId, $adapter->surfaceId());
        $report = $adapter->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertSame([], $report['errors']);
    }

    #[DataProvider('adapters')]
    public function test_adapters_do_not_mutate_payload_or_keep_state_between_calls(SurfaceAdapter $adapter, string $expectedSurfaceId): void
    {
        $this->assertSame($expectedSurfaceId, $adapter->surfaceId());
        $payload = [
            'text' => 'Sem side effects.',
            'locale' => 'pt-BR',
            'attachments' => [
                ['id' => 'att-1', 'name' => 'a.txt'],
            ],
            'hints' => ['mode' => 'probe'],
        ];
        $before = $payload;

        $first = $adapter->normalizeInput($payload);
        $second = $adapter->normalizeInput($payload);

        $this->assertSame($before, $payload);
        $this->assertSame($first->inputHash, $second->inputHash);
        $this->assertSame($first->attachments, $second->attachments);
        $this->assertSame($first->hints, $second->hints);
    }

    public function test_api_and_app_adapters_support_domain_flow_selection(): void
    {
        $this->assertContains(SurfaceCapability::DOMAIN_FLOW_SELECTION, (new AtlasApiInteractionSurfaceAdapter)->supportedCapabilities());
        $this->assertContains(SurfaceCapability::DOMAIN_FLOW_SELECTION, (new AtlasAppSurfaceAdapter)->supportedCapabilities());
    }

    public function test_cli_chat_adapter_does_not_accept_explicit_domain_flow_selection(): void
    {
        $adapter = new AtlasCliChatSurfaceAdapter;
        $hints = $adapter->supportedDomainFlowHints();

        $this->assertNotContains(SurfaceCapability::DOMAIN_FLOW_SELECTION, $adapter->supportedCapabilities());
        $this->assertFalse($hints[SurfaceDomainFlowHintKey::ACCEPTS_EXPLICIT_DOMAIN_FLOW_SELECTION]);
    }

    public function test_domain_flow_capable_adapters_expose_selection_hints(): void
    {
        foreach ([new AtlasCliDevSurfaceAdapter, new AtlasCliForgeSurfaceAdapter, new AtlasCodeSurfaceAdapter, new AtlasApiInteractionSurfaceAdapter, new AtlasAppSurfaceAdapter, new AtlasVoiceRealtimeSurfaceAdapter] as $adapter) {
            $hints = $adapter->supportedDomainFlowHints();

            $this->assertSame($adapter->surfaceId(), $hints[SurfaceDomainFlowHintKey::SURFACE_ID]);
            $this->assertTrue($hints[SurfaceDomainFlowHintKey::ACCEPTS_EXPLICIT_DOMAIN_FLOW_SELECTION]);
            $this->assertArrayHasKey(SurfaceDomainFlowHintKey::DEFAULT_FLOW_ID, $hints);
        }

        $forgeHints = (new AtlasCliForgeSurfaceAdapter)->supportedDomainFlowHints();
        $this->assertSame('programming.forge', $forgeHints[SurfaceDomainFlowHintKey::DEFAULT_FLOW_ID]);
        $this->assertSame('programming.forge', $forgeHints[SurfaceDomainFlowHintKey::TASK_FLOW_MAP]['heavy']);
        $this->assertContains('programming.forge', $forgeHints[SurfaceDomainFlowHintKey::SUPPORTED_FLOW_IDS]);

        $atlasCodeHints = (new AtlasCodeSurfaceAdapter)->supportedDomainFlowHints();
        $this->assertSame('programming.forge', $atlasCodeHints[SurfaceDomainFlowHintKey::DEFAULT_FLOW_ID]);
        $this->assertSame('programming.forge', $atlasCodeHints[SurfaceDomainFlowHintKey::TASK_FLOW_MAP]['direct']);
        $this->assertSame(['programming.forge'], $atlasCodeHints[SurfaceDomainFlowHintKey::SUPPORTED_FLOW_IDS]);

        $voiceHints = (new AtlasVoiceRealtimeSurfaceAdapter)->supportedDomainFlowHints();
        $this->assertSame('general.answer', $voiceHints[SurfaceDomainFlowHintKey::DEFAULT_FLOW_ID]);
        $this->assertSame('programming.repair', $voiceHints[SurfaceDomainFlowHintKey::TASK_FLOW_MAP]['debug']);
        $this->assertContains('marketing.copywriting', $voiceHints[SurfaceDomainFlowHintKey::SUPPORTED_FLOW_IDS]);
    }

    public function test_cli_programming_and_chat_surfaces_support_image_paste(): void
    {
        $this->assertContains(SurfaceCapability::IMAGE_PASTE, (new AtlasCliDevSurfaceAdapter)->supportedCapabilities());
        $this->assertContains(SurfaceCapability::IMAGE_PASTE, (new AtlasCliChatSurfaceAdapter)->supportedCapabilities());
        $this->assertContains(SurfaceCapability::IMAGE_PASTE, (new AtlasCliForgeSurfaceAdapter)->supportedCapabilities());
        $this->assertContains(SurfaceCapability::IMAGE_PASTE, (new AtlasCodeSurfaceAdapter)->supportedCapabilities());
    }

    public function test_surface_adapters_declare_memory_context_and_tool_runtime_parity(): void
    {
        foreach ([new AtlasCliDevSurfaceAdapter, new AtlasCliChatSurfaceAdapter, new AtlasCliForgeSurfaceAdapter, new AtlasCodeSurfaceAdapter, new AtlasApiInteractionSurfaceAdapter, new AtlasWorkerSurfaceAdapter] as $adapter) {
            $this->assertContains(SurfaceCapability::MEMORY_RECALL, $adapter->supportedCapabilities());
            $this->assertContains(SurfaceCapability::CONTEXT_COMPOSE, $adapter->supportedCapabilities());
            $this->assertContains(SurfaceCapability::TOOLS_RUNTIME, $adapter->supportedCapabilities());
        }

        $app = new AtlasAppSurfaceAdapter;

        $this->assertContains(SurfaceCapability::MEMORY_RECALL, $app->supportedCapabilities());
        $this->assertContains(SurfaceCapability::CONTEXT_COMPOSE, $app->supportedCapabilities());
        $this->assertNotContains(SurfaceCapability::TOOLS_RUNTIME, $app->supportedCapabilities());

        $mcp = new AtlasMcpReadonlySurfaceAdapter;

        $this->assertContains(SurfaceCapability::MEMORY_RECALL, $mcp->supportedCapabilities());
        $this->assertContains(SurfaceCapability::CONTEXT_COMPOSE, $mcp->supportedCapabilities());
        $this->assertNotContains(SurfaceCapability::TOOLS_RUNTIME, $mcp->supportedCapabilities());
        $this->assertNotContains(SurfaceCapability::TEXT, $mcp->supportedCapabilities());

        $vault = new AtlasVaultSurfaceAdapter;

        $this->assertContains(SurfaceCapability::TEXT, $vault->supportedCapabilities());
        $this->assertContains(SurfaceCapability::FILES, $vault->supportedCapabilities());
        $this->assertContains(SurfaceCapability::HUMAN_KNOWLEDGE_WORKSPACE, $vault->supportedCapabilities());
        $this->assertContains(SurfaceCapability::MANAGED_NOTE_PROJECTION, $vault->supportedCapabilities());
        $this->assertNotContains(SurfaceCapability::MEMORY_RECALL, $vault->supportedCapabilities());
        $this->assertNotContains(SurfaceCapability::CONTEXT_COMPOSE, $vault->supportedCapabilities());
        $this->assertNotContains(SurfaceCapability::TOOLS_RUNTIME, $vault->supportedCapabilities());
    }

    public function test_voice_realtime_surface_declares_voice_audio_without_tool_execution(): void
    {
        $voice = new AtlasVoiceRealtimeSurfaceAdapter;

        $this->assertContains(SurfaceCapability::TEXT, $voice->supportedCapabilities());
        $this->assertContains(SurfaceCapability::VOICE_AUDIO, $voice->supportedCapabilities());
        $this->assertContains(SurfaceCapability::MEMORY_RECALL, $voice->supportedCapabilities());
        $this->assertContains(SurfaceCapability::CONTEXT_COMPOSE, $voice->supportedCapabilities());
        $this->assertContains(SurfaceCapability::DOMAIN_FLOW_SELECTION, $voice->supportedCapabilities());
        $this->assertNotContains(SurfaceCapability::TOOLS_RUNTIME, $voice->supportedCapabilities());

        $report = $voice->complianceReport();
        $rendered = $voice->renderOutput(['status' => 'ok', 'text' => 'voice loop ready']);

        $this->assertTrue($report['ok']);
        $this->assertSame([], $report['errors']);
        $this->assertSame('voice_realtime', $rendered['surface_id']);
        $this->assertContains(SurfaceCapability::VOICE_AUDIO, data_get($rendered, 'metadata.surface_capabilities'));
        $this->assertNotContains(SurfaceCapability::TOOLS_RUNTIME, data_get($rendered, 'metadata.surface_capabilities'));
    }

    public function test_unknown_surface_capability_fails_compliance(): void
    {
        $adapter = new class extends BaseSurfaceAdapter
        {
            protected const SURFACE_ID = 'test_unknown_capability_surface';

            protected function capabilities(): array
            {
                return [
                    SurfaceCapability::TEXT,
                    'totally_unknown_capability',
                ];
            }
        };

        $report = $adapter->complianceReport();

        $this->assertFalse($report['ok']);
        $this->assertContains('unsupported_capability:totally_unknown_capability', $report['errors']);
    }

    public function test_invalid_domain_flow_hints_fail_compliance(): void
    {
        $adapter = new class extends BaseSurfaceAdapter
        {
            protected const SURFACE_ID = 'test_invalid_domain_flow_hints';

            protected function capabilities(): array
            {
                return [
                    SurfaceCapability::TEXT,
                    SurfaceCapability::DOMAIN_FLOW_SELECTION,
                ];
            }

            protected function domainFlowHints(): array
            {
                return [
                    'unknown_hint_key' => true,
                    SurfaceDomainFlowHintKey::DEFAULT_FLOW_ID => 'programming.forge',
                    SurfaceDomainFlowHintKey::SUPPORTED_FLOW_IDS => ['programming.dev'],
                    SurfaceDomainFlowHintKey::TASK_FLOW_MAP => [
                        'heavy' => 'programming.forge',
                    ],
                    SurfaceDomainFlowHintKey::PREFER_DEFAULT_FLOW => 'yes',
                ];
            }
        };

        $report = $adapter->complianceReport();

        $this->assertFalse($report['ok']);
        $this->assertContains('domain_flow_hints_unknown_key:unknown_hint_key', $report['errors']);
        $this->assertContains('domain_flow_hints_prefer_default_flow_not_bool', $report['errors']);
        $this->assertContains('domain_flow_hints_default_flow_not_supported', $report['errors']);
        $this->assertContains('domain_flow_hints_task_flow_not_supported', $report['errors']);
    }

    public function test_surface_hint_key_vocabulary_contains_adapter_hint_keys(): void
    {
        $adapter = new class extends BaseSurfaceAdapter
        {
            protected const SURFACE_ID = 'test_hint_key_surface';

            protected function capabilities(): array
            {
                return [SurfaceCapability::TEXT];
            }

            /**
             * @return array<int,string>
             */
            public function exposedHintKeys(): array
            {
                return $this->hintKeys();
            }
        };

        foreach ($adapter->exposedHintKeys() as $key) {
            $this->assertTrue(SurfaceHintKey::isKnown($key), "Unknown surface hint key [{$key}].");
        }

        $this->assertTrue(SurfaceHintKey::isKnown(SurfaceHintKey::SURFACE_ID));
        $this->assertTrue(SurfaceHintKey::isKnown(SurfaceHintKey::SURFACE_CAPABILITIES));
    }

    public function test_registry_exposes_default_adapters_by_stable_surface_id(): void
    {
        $registry = app(SurfaceAdapterRegistry::class);

        $this->assertSame([
            'atlas_cli_dev',
            'atlas_cli_chat',
            'atlas_cli_forge',
            'atlas_code',
            'atlas_api_interaction',
            'atlas_app',
            'atlas_worker',
            'atlas_mcp_readonly',
            'atlas_vault',
            'voice_realtime',
        ], $registry->surfaceIds());

        $this->assertSame([
            'atlas_api' => 'atlas_api_interaction',
            'atlas_cli' => 'atlas_cli_dev',
            'atlas_dev' => 'atlas_cli_dev',
            'atlas_forge' => 'atlas_cli_forge',
            'atlas_desktop_code' => 'atlas_code',
            'atlas_code_scor_1' => 'atlas_code',
            'code' => 'atlas_code',
            'atlas_fix' => 'atlas_cli_dev',
            'atlas_continue' => 'atlas_cli_dev',
            'atlas_ask' => 'atlas_cli_chat',
            'atlas_chat' => 'atlas_cli_chat',
            'atlas_cli_fix' => 'atlas_cli_dev',
            'atlas_cli_continue' => 'atlas_cli_dev',
            'atlas_cli_ask' => 'atlas_cli_chat',
            'obsidian' => 'atlas_vault',
            'atlas_obsidian' => 'atlas_vault',
            'atlasvault' => 'atlas_vault',
            'atlas_voice' => 'voice_realtime',
            'voice' => 'voice_realtime',
            'voice_realtime_mobile' => 'voice_realtime',
        ], $registry->aliases());

        $this->assertSame('atlas_cli_dev', $registry->canonicalSurfaceId('atlas_cli'));
        $this->assertSame('atlas_cli_chat', $registry->canonicalSurfaceId('atlas_ask'));
        $this->assertSame('atlas_cli_chat', $registry->canonicalSurfaceId('atlas_chat'));
        $this->assertSame('atlas_cli_dev', $registry->canonicalSurfaceId('atlas_fix'));
        $this->assertSame('atlas_cli_dev', $registry->canonicalSurfaceId('atlas_continue'));
        $this->assertSame('atlas_cli_forge', $registry->canonicalSurfaceId('atlas_forge'));
        $this->assertSame('atlas_code', $registry->canonicalSurfaceId('atlas_desktop_code'));
        $this->assertSame('atlas_code', $registry->canonicalSurfaceId('atlas_code_scor_1'));
        $this->assertSame('atlas_code', $registry->canonicalSurfaceId('code'));
        $this->assertSame('atlas_api_interaction', $registry->canonicalSurfaceId('atlas_api'));
        $this->assertSame('atlas_vault', $registry->canonicalSurfaceId('obsidian'));
        $this->assertSame('atlas_vault', $registry->canonicalSurfaceId('atlasvault'));
        $this->assertSame('atlas_app', $registry->canonicalSurfaceId('atlas_app'));
        $this->assertSame('voice_realtime', $registry->canonicalSurfaceId('voice'));
        $this->assertSame('voice_realtime', $registry->canonicalSurfaceId('atlas_voice'));
        $this->assertSame('atlas_cli_dev', $registry->get('atlas_cli')->surfaceId());
        $this->assertSame('atlas_cli_chat', $registry->get('atlas_ask')->surfaceId());
        $this->assertSame('atlas_cli_dev', $registry->get('atlas_cli_continue')->surfaceId());
        $this->assertSame('atlas_code', $registry->get('atlas_desktop_code')->surfaceId());
        $this->assertSame('atlas_api_interaction', $registry->get('atlas_api')->surfaceId());
        $this->assertSame('atlas_worker', $registry->get('atlas_worker')->surfaceId());
        $this->assertSame('atlas_mcp_readonly', $registry->get('atlas_mcp_readonly')->surfaceId());
        $this->assertSame('atlas_vault', $registry->get('obsidian')->surfaceId());
        $this->assertSame('voice_realtime', $registry->get('voice')->surfaceId());

        foreach ($registry->surfaceIds() as $surfaceId) {
            $adapter = $registry->get($surfaceId);

            $this->assertInstanceOf(SurfaceAdapter::class, $adapter);
            $this->assertSame($surfaceId, $adapter->surfaceId());
        }

        $this->expectException(InvalidArgumentException::class);
        $registry->get('unknown_surface');
    }

    public function test_registry_compliance_report_aggregates_adapter_reports(): void
    {
        $report = app(SurfaceAdapterRegistry::class)->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertSame([], $report['errors']);
        $this->assertSame(10, $report['count']);
        $this->assertSame('atlas_cli_chat', $report['aliases']['atlas_ask']);
        $this->assertSame('atlas_cli_dev', $report['aliases']['atlas_cli_continue']);
        $this->assertSame('atlas_code', $report['aliases']['atlas_desktop_code']);
        $this->assertSame('atlas_vault', $report['aliases']['obsidian']);
        $this->assertSame('voice_realtime', $report['aliases']['voice']);
        $this->assertSame([
            'atlas_cli_dev',
            'atlas_cli_chat',
            'atlas_cli_forge',
            'atlas_code',
            'atlas_api_interaction',
            'atlas_app',
            'atlas_worker',
            'atlas_mcp_readonly',
            'atlas_vault',
            'voice_realtime',
        ], $report['surfaces']);
    }
}
