<?php

namespace App\Services\Ai\ToolRuntime;

use App\Models\AiToolDefinition;
use App\Models\AiToolValidationRun;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class ToolValidationService
{
    /**
     * Validate a tool against a (possibly null) fixture set. Returns a record
     * indicating passed/failed/blocked/skipped — Meta 5 implements schema-level
     * validation only; real execution-time fixtures are future work.
     *
     * @param  array<string,mixed>  $args
     */
    public function validate(AiToolDefinition $tool, string $type, array $args = []): AiToolValidationRun
    {
        if (! in_array($type, ['schema', 'smoke', 'fixture'], true)) {
            throw ToolRuntimeException::invalidEnum('validation_type', $type, ['schema', 'smoke', 'fixture']);
        }

        $fixtureRef = (string) ($args['input_fixture_ref'] ?? '');
        $status = $this->resolveStatus($tool, $type, $args);

        $hash = MissionCanonicalHash::sha256([
            'tool_id' => $tool->tool_id,
            'validation_type' => $type,
            'fixture' => $fixtureRef,
            'status' => $status,
        ]);

        return AiToolValidationRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'tool_definition_id' => $tool->id,
            'validation_type' => $type,
            'status' => $status,
            'input_fixture_ref' => $fixtureRef !== '' ? $fixtureRef : null,
            'output_ref' => $args['output_ref'] ?? null,
            'evidence_refs' => $args['evidence_refs'] ?? null,
            'validation_hash' => $hash,
        ]);
    }

    /**
     * @param  array<string,mixed>  $args
     */
    private function resolveStatus(AiToolDefinition $tool, string $type, array $args): string
    {
        if (! $this->schemaIsValid($tool->input_schema) || ! $this->schemaIsValid($tool->output_schema)) {
            return 'failed';
        }

        if ($type === 'schema') {
            return 'passed';
        }

        if ($type === 'fixture' && ($args['input_fixture_ref'] ?? '') === '') {
            return 'skipped';
        }

        if (ToolRuntimeCanon::isHighRiskAuthority($tool->authority_group)) {
            return 'blocked';
        }

        return 'passed';
    }

    /**
     * @param  array<string,mixed>|null  $schema
     */
    private function schemaIsValid(?array $schema): bool
    {
        if ($schema === null || $schema === []) {
            return false;
        }
        if (! array_key_exists('type', $schema)) {
            return false;
        }

        return true;
    }
}
