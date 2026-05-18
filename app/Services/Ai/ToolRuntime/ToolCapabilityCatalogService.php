<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolCapability;
use App\Models\AiToolDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ToolCapabilityCatalogService
{
    public const REQUIRED_FIELDS = [
        'capability_id',
        'input_schema',
        'output_schema',
        'required_policy_gates',
        'required_evidence',
    ];

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function register(AiToolDefinition $tool, array $attributes): AiToolCapability
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null) {
                throw ToolRuntimeException::missingField($tool->tool_id, "capability.{$field}");
            }
        }

        $capabilityId = (string) $attributes['capability_id'];
        if ($tool->capabilities()->where('capability_id', $capabilityId)->exists()) {
            throw ToolRuntimeException::capabilityExists($tool->tool_id, $capabilityId);
        }

        $maturityLevel = (int) ($attributes['maturity_level'] ?? 1);

        return AiToolCapability::query()->create([
            'uuid' => (string) Str::uuid(),
            'capability_id' => $capabilityId,
            'tool_definition_id' => $tool->id,
            'name' => (string) ($attributes['name'] ?? Str::headline($capabilityId)),
            'description' => $attributes['description'] ?? null,
            'input_schema' => $attributes['input_schema'],
            'output_schema' => $attributes['output_schema'],
            'required_policy_gates' => $attributes['required_policy_gates'],
            'required_evidence' => $attributes['required_evidence'],
            'maturity_level' => $maturityLevel,
            'status' => (string) ($attributes['status'] ?? 'available'),
        ]);
    }

    /**
     * @return Collection<int,AiToolCapability>
     */
    public function listForTool(AiToolDefinition $tool): Collection
    {
        return $tool->capabilities()->orderBy('capability_id')->get();
    }

    public function findByCapabilityId(string $capabilityId): ?AiToolCapability
    {
        return AiToolCapability::query()->where('capability_id', $capabilityId)->first();
    }
}
