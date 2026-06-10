<?php

namespace App\Services\Ai\Programming\Kernel;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\ToolRuntime\ToolDefinitionRegistryService;
use App\Services\Ai\ToolRuntime\ToolInvocationService;
use Illuminate\Contracts\Container\Container;
use Throwable;

class ProgrammingToolBridge
{
    public function __construct(private readonly Container $container) {}

    public function bridgeAvailable(): bool
    {
        return DatabaseTableAvailability::all(['ai_tool_definitions', 'ai_tool_invocations']);
    }

    /**
     * Request a programming-related tool through Atlas Tool Runtime (Meta 5).
     * Tolerant: if the Tool Runtime is unavailable, emits a local advisory
     * decision instead of invoking the tool.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function requestTool(string $toolId, AiMission $mission, array $input = [], array $context = []): array
    {
        $advisoryHashInput = [
            'tool_id' => $toolId,
            'mission_id' => $mission->id,
            'input_keys' => array_keys($input),
        ];

        if (! $this->bridgeAvailable()) {
            return [
                'decision' => 'advisory_use_existing',
                'source' => 'programming_tool_fallback',
                'reason' => 'tool_runtime_unavailable',
                'tool_id' => $toolId,
                'invocation_id' => null,
                'advisory_hash' => MissionCanonicalHash::sha256($advisoryHashInput),
            ];
        }

        try {
            $registry = $this->container->make(ToolDefinitionRegistryService::class);
            $tool = $registry->findByToolId($toolId);
            if ($tool === null) {
                return [
                    'decision' => 'tool_not_registered',
                    'source' => 'programming_tool_fallback',
                    'reason' => "tool [{$toolId}] not present in Tool Registry; run atlas:ai:tool-runtime --action=seed-defaults",
                    'tool_id' => $toolId,
                    'invocation_id' => null,
                    'advisory_hash' => MissionCanonicalHash::sha256($advisoryHashInput),
                ];
            }

            $invocation = $this->container->make(ToolInvocationService::class)->invoke($tool, $input, [
                'mission_id' => $mission->id,
                'work_order_id' => $context['work_order_id'] ?? null,
                'source' => 'programming_adapter',
            ]);

            return [
                'decision' => $invocation->invocation_status === 'succeeded' ? 'allow' : $invocation->invocation_status,
                'source' => 'atlas_tool_runtime',
                'reason' => "tool_runtime:{$invocation->invocation_status}",
                'tool_id' => $tool->tool_id,
                'invocation_id' => (string) $invocation->id,
                'invocation_status' => $invocation->invocation_status,
                'input_hash' => $invocation->input_hash,
                'output_hash' => $invocation->output_hash,
            ];
        } catch (Throwable $e) {
            return [
                'decision' => 'advisory_use_existing',
                'source' => 'programming_tool_fallback',
                'reason' => 'tool_runtime_exception:'.$e->getMessage(),
                'tool_id' => $toolId,
                'invocation_id' => null,
                'advisory_hash' => MissionCanonicalHash::sha256($advisoryHashInput),
            ];
        }
    }
}
