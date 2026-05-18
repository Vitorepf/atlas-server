<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolDefinition;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ToolDefinitionRegistryService
{
    public const REQUIRED_FIELDS = [
        'tool_id',
        'name',
        'tool_type',
        'authority_group',
        'input_schema',
        'output_schema',
        'side_effects',
        'evidence_emitted',
    ];

    public function __construct(private readonly ToolCapabilityCatalogService $catalog) {}

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function register(array $attributes): AiToolDefinition
    {
        $toolId = (string) ($attributes['tool_id'] ?? '');
        if ($toolId === '') {
            throw ToolRuntimeException::missingField('?', 'tool_id');
        }
        if (! preg_match('/^[a-z0-9][a-z0-9_.\-]{1,119}$/', $toolId)) {
            throw new \InvalidArgumentException("tool_id [{$toolId}] must match a-z, 0-9, _ . -");
        }
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === null) {
                throw ToolRuntimeException::missingField($toolId, $field);
            }
        }

        $toolType = (string) $attributes['tool_type'];
        if (! in_array($toolType, ToolRuntimeCanon::TOOL_TYPES, true)) {
            throw ToolRuntimeException::invalidEnum('tool_type', $toolType, ToolRuntimeCanon::TOOL_TYPES);
        }
        $authority = (string) $attributes['authority_group'];
        if (! in_array($authority, ToolRuntimeCanon::AUTHORITY_GROUPS, true)) {
            throw ToolRuntimeException::invalidEnum('authority_group', $authority, ToolRuntimeCanon::AUTHORITY_GROUPS);
        }
        $risk = (string) ($attributes['risk_level'] ?? 'low');
        if (! in_array($risk, ToolRuntimeCanon::RISK_LEVELS, true)) {
            throw ToolRuntimeException::invalidEnum('risk_level', $risk, ToolRuntimeCanon::RISK_LEVELS);
        }
        $status = (string) ($attributes['status'] ?? 'active');
        if (! in_array($status, ToolRuntimeCanon::TOOL_STATUS, true)) {
            throw ToolRuntimeException::invalidEnum('status', $status, ToolRuntimeCanon::TOOL_STATUS);
        }

        if (AiToolDefinition::query()->where('tool_id', $toolId)->exists()) {
            throw ToolRuntimeException::duplicateTool($toolId);
        }

        $capabilities = (array) ($attributes['capabilities'] ?? []);

        return DB::transaction(function () use ($attributes, $toolId, $toolType, $authority, $risk, $status, $capabilities): AiToolDefinition {
            $tool = AiToolDefinition::query()->create([
                'uuid' => (string) Str::uuid(),
                'tool_id' => $toolId,
                'name' => (string) $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'tool_type' => $toolType,
                'authority_group' => $authority,
                'risk_level' => $risk,
                'input_schema' => $attributes['input_schema'],
                'output_schema' => $attributes['output_schema'],
                'auth_requirements' => $attributes['auth_requirements'] ?? null,
                'cost_profile' => $attributes['cost_profile'] ?? null,
                'side_effects' => $attributes['side_effects'],
                'evidence_emitted' => $attributes['evidence_emitted'],
                'health_status' => 'unknown',
                'status' => $status,
            ]);

            foreach ($capabilities as $capability) {
                $this->catalog->register($tool, $capability);
            }

            return $tool->refresh();
        });
    }

    public function findByToolId(string $toolId): ?AiToolDefinition
    {
        return AiToolDefinition::query()->where('tool_id', $toolId)->first();
    }

    /**
     * @return Collection<int,AiToolDefinition>
     */
    public function all(): Collection
    {
        return AiToolDefinition::query()->orderBy('tool_id')->get();
    }

    /**
     * @param  array<int,array<string,mixed>>  $tools
     * @return array<string,mixed>
     */
    public function seedDefaults(array $tools): array
    {
        $created = [];
        $skipped = [];
        foreach ($tools as $attributes) {
            $toolId = (string) ($attributes['tool_id'] ?? '');
            if ($toolId === '') {
                $skipped[] = ['tool_id' => '?', 'reason' => 'missing tool_id'];

                continue;
            }
            if (AiToolDefinition::query()->where('tool_id', $toolId)->exists()) {
                $skipped[] = ['tool_id' => $toolId, 'reason' => 'already_seeded'];

                continue;
            }
            $tool = $this->register($attributes);
            $created[] = [
                'tool_id' => $tool->tool_id,
                'authority_group' => $tool->authority_group,
                'risk_level' => $tool->risk_level,
                'capabilities' => $tool->capabilities()->count(),
            ];
        }

        return [
            'ok' => true,
            'summary' => [
                'created' => count($created),
                'skipped' => count($skipped),
                'total_after' => AiToolDefinition::query()->count(),
            ],
            'created' => $created,
            'skipped' => $skipped,
            'registry_hash' => MissionCanonicalHash::sha256(AiToolDefinition::query()->orderBy('tool_id')->pluck('tool_id')->all()),
        ];
    }
}
