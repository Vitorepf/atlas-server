<?php

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class DevTaskPacketRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.dev.task_packet.v1';

    /**
     * @param  array<string,mixed>  $input
     */
    public function build(array $input): array
    {
        $runId = $this->string($input['run_id'] ?? null) ?? 'dev-run-'.Str::uuid()->toString();
        $taskId = $this->string($input['task_id'] ?? null) ?? 'dev-task-'.substr(MissionCanonicalHash::sha256($input), 0, 12);
        $riskBand = $this->normalizeRisk($this->string($input['risk_band'] ?? $input['risk'] ?? null));
        $taskClass = $this->normalizeTaskClass($this->string($input['task_class'] ?? $input['task'] ?? null), $riskBand);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'task_id' => $taskId,
            'objective' => $this->string($input['objective'] ?? $input['intent'] ?? null) ?? 'Atlas Dev task',
            'task_class' => $taskClass,
            'risk_band' => $riskBand,
            'workspace_slug' => $this->string($input['workspace_slug'] ?? $input['workspace'] ?? null),
            'allowed_files' => $this->stringList($input['allowed_files'] ?? []),
            'forbidden_files' => $this->stringList($input['forbidden_files'] ?? []),
            'context_refs' => $this->stringList($input['context_refs'] ?? []),
            'expected_files' => $this->stringList($input['expected_files'] ?? []),
            'suggested_tests' => $this->stringList($input['suggested_tests'] ?? $input['tests'] ?? []),
            'acceptance_criteria' => $this->stringList($input['acceptance_criteria'] ?? []),
            'required_evidence' => $this->stringList($input['required_evidence'] ?? []),
            'source' => $this->string($input['source'] ?? null) ?? 'atlas_dev_runtime_intelligence',
        ];

        $payload['task_packet_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function persist(array $input): AtlasDevTaskPacket
    {
        $packet = $this->build($input);

        return AtlasDevTaskPacket::query()->updateOrCreate(
            ['run_id' => $packet['run_id'], 'task_id' => $packet['task_id']],
            [
                'schema_version' => $packet['schema_version'],
                'uuid' => $packet['task_packet_hash'],
                'objective' => $packet['objective'],
                'task_class' => $packet['task_class'],
                'risk_band' => $packet['risk_band'],
                'workspace_slug' => $packet['workspace_slug'],
                'allowed_files' => $packet['allowed_files'],
                'forbidden_files' => $packet['forbidden_files'],
                'context_refs' => $packet['context_refs'],
                'expected_files' => $packet['expected_files'],
                'suggested_tests' => $packet['suggested_tests'],
                'acceptance_criteria' => $packet['acceptance_criteria'],
                'required_evidence' => $packet['required_evidence'],
                'source' => $packet['source'],
                'task_packet_hash' => $packet['task_packet_hash'],
            ],
        );
    }

    private function normalizeRisk(?string $risk): string
    {
        return match ($risk) {
            'low', 'medium', 'high', 'critical' => $risk,
            'p0', 'danger' => 'critical',
            'p1', 'major' => 'high',
            'p2' => 'medium',
            default => 'medium',
        };
    }

    private function normalizeTaskClass(?string $taskClass, string $riskBand): string
    {
        if (in_array($taskClass, ['trivial', 'read_only', 'patch', 'debug', 'review', 'repair', 'feature'], true)) {
            return $taskClass;
        }

        return $riskBand === 'low' ? 'patch' : 'feature';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->string($item),
            $value,
        ))));
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
