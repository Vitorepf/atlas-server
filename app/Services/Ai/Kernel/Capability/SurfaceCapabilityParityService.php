<?php

namespace App\Services\Ai\Kernel\Capability;

use App\Services\Ai\Kernel\Surface\SurfaceCapability;
use App\Services\Ai\Surface\SurfaceAdapterRegistry;
use InvalidArgumentException;

class SurfaceCapabilityParityService
{
    /**
     * @param  array<string,array<int,string>>|null  $surfaceAdapterMap
     * @param  array<string,array<string,array<int,string>>>|null  $capabilityAdapterRequirements
     */
    public function __construct(
        private readonly AtlasCapabilityRegistry $capabilities,
        private readonly SurfaceAdapterRegistry $surfaceAdapters,
        private readonly ?array $surfaceAdapterMap = null,
        private readonly ?array $capabilityAdapterRequirements = null,
    ) {}

    /**
     * @return array{ok:bool,checked:int,errors:array<int,string>,skipped:array<int,string>,mapped_adapters:array<int,string>,unmapped_adapters:array<int,string>,input_boundary_contract:array<string,mixed>}
     */
    public function complianceReport(): array
    {
        $errors = [];
        $skipped = [];
        $checked = 0;
        $surfaceMap = $this->surfaceMap();
        $requirements = $this->requirements();
        $mappedAdapters = $this->mappedAdapterIds($surfaceMap);
        $unmappedAdapters = array_values(array_diff($this->surfaceAdapters->surfaceIds(), $mappedAdapters));

        foreach ($unmappedAdapters as $adapterId) {
            $errors[] = "Surface adapter {$adapterId} is registered but missing from capability parity map.";
        }

        foreach ($this->capabilities->surfaces() as $kernelSurfaceId => $surface) {
            $adapterIds = $surfaceMap[$kernelSurfaceId] ?? [];
            if ($adapterIds === []) {
                $skipped[] = "Surface {$kernelSurfaceId} has no operational adapter parity map.";

                continue;
            }

            foreach ($this->capabilities->surfaceCapabilities($kernelSurfaceId) as $capabilityId) {
                $surfaceRequirements = $requirements[$capabilityId] ?? [];
                $requiredAdapterCapabilities = $surfaceRequirements[$kernelSurfaceId] ?? $surfaceRequirements['*'] ?? [];
                if ($requiredAdapterCapabilities === []) {
                    $skipped[] = "Capability {$capabilityId} on {$kernelSurfaceId} has no adapter parity rule.";

                    continue;
                }

                foreach ($adapterIds as $adapterId) {
                    $checked++;

                    try {
                        $adapter = $this->surfaceAdapters->get($adapterId);
                    } catch (InvalidArgumentException) {
                        $errors[] = "Kernel surface {$kernelSurfaceId} maps to missing surface adapter {$adapterId}.";

                        continue;
                    }

                    $supported = $adapter->supportedCapabilities();
                    if (array_intersect($requiredAdapterCapabilities, $supported) === []) {
                        $errors[] = "Kernel capability {$capabilityId} on {$kernelSurfaceId} requires adapter {$adapter->surfaceId()} to support one of [".implode(', ', $requiredAdapterCapabilities).'].';
                    }
                }
            }
        }

        return [
            'ok' => $errors === [] && $skipped === [],
            'checked' => $checked,
            'errors' => array_values(array_unique($errors)),
            'skipped' => array_values(array_unique($skipped)),
            'mapped_adapters' => $mappedAdapters,
            'unmapped_adapters' => $unmappedAdapters,
            'input_boundary_contract' => $this->inputBoundaryContract(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function inputBoundaryContract(): array
    {
        return [
            'schema_version' => 'atlas.input.surface_capability_boundary.v1',
            'owner' => 'atlas_input',
            'boundary' => 'surface_adapters_expose_kernel_input_capabilities_only',
            'surface_specific_input_capabilities_allowed' => false,
            'adapter_parity_required' => true,
            'required_kernel_capabilities' => [
                'atlas.input.text',
                'atlas.input.image_paste',
                'atlas.input.file_attachment',
                'atlas.input.voice_audio',
            ],
            'forbidden_patterns' => [
                'surface_only_image_paste',
                'ask_only_attachment_flow',
                'provider_direct_attachment_bypass',
                'domain_owned_attachment_parser',
            ],
        ];
    }

    /**
     * @param  array<string,array<int,string>>  $surfaceMap
     * @return array<int,string>
     */
    private function mappedAdapterIds(array $surfaceMap): array
    {
        $adapterIds = [];

        foreach ($surfaceMap as $ids) {
            foreach ($ids as $id) {
                if (is_string($id) && trim($id) !== '') {
                    $adapterIds[] = trim($id);
                }
            }
        }

        return array_values(array_unique($adapterIds));
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function surfaceMap(): array
    {
        return $this->surfaceAdapterMap ?? [
            'atlas_cli' => ['atlas_cli_dev', 'atlas_cli_chat', 'atlas_cli_forge'],
            'atlas_app' => ['atlas_app', 'atlas_code'],
            'atlas_api' => ['atlas_api_interaction'],
            'atlas_worker' => ['atlas_worker'],
            'atlas_mcp_readonly' => ['atlas_mcp_readonly'],
            'atlas_vault' => ['atlas_vault'],
            'atlas_voice' => ['voice_realtime'],
        ];
    }

    /**
     * @return array<string,array<string,array<int,string>>>
     */
    private function requirements(): array
    {
        return $this->capabilityAdapterRequirements ?? [
            'atlas.input.text' => [
                '*' => [SurfaceCapability::TEXT],
            ],
            'atlas.input.image_paste' => [
                'atlas_cli' => [SurfaceCapability::IMAGE_PASTE],
                'atlas_app' => [SurfaceCapability::IMAGE_PASTE, SurfaceCapability::IMAGE_UPLOADS, SurfaceCapability::ATTACHMENTS],
                'atlas_api' => [SurfaceCapability::IMAGE_PASTE, SurfaceCapability::IMAGE_UPLOADS, SurfaceCapability::ATTACHMENTS],
            ],
            'atlas.input.voice_audio' => [
                'atlas_voice' => [SurfaceCapability::VOICE_AUDIO],
            ],
            'atlas.input.file_attachment' => [
                'atlas_cli' => [SurfaceCapability::FILES, SurfaceCapability::ATTACHMENTS],
                'atlas_app' => [SurfaceCapability::ATTACHMENTS],
                'atlas_api' => [SurfaceCapability::ATTACHMENTS],
                'atlas_vault' => [SurfaceCapability::FILES],
            ],
            'atlas.memory.recall' => [
                '*' => [SurfaceCapability::MEMORY_RECALL],
            ],
            'atlas.context.compose' => [
                '*' => [SurfaceCapability::CONTEXT_COMPOSE],
            ],
            'atlas.tools.runtime' => [
                '*' => [SurfaceCapability::TOOLS_RUNTIME],
            ],
            'atlas.human_knowledge.vault' => [
                '*' => [SurfaceCapability::HUMAN_KNOWLEDGE_WORKSPACE],
            ],
            'atlas.human_knowledge.managed_note_projection' => [
                '*' => [SurfaceCapability::MANAGED_NOTE_PROJECTION],
            ],
        ];
    }
}
