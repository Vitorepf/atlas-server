<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Illuminate\Support\Str;
use RuntimeException;

final class AtlasFrontendOutcomeMemoryService
{
    public const SCHEMA_VERSION = 'atlas.frontend.outcome_memory.v1';

    public const RECORD_SCHEMA_VERSION = 'atlas.frontend.outcome_record.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input, ?string $storePath = null): array
    {
        $status = $this->status((string) ($input['status'] ?? ''));
        $taskType = $this->slug((string) ($input['task_type'] ?? 'frontend_task'));
        $surface = $this->slug((string) ($input['surface'] ?? 'programming.frontend'));
        $drivers = $this->slugList((array) ($input['drivers'] ?? ['ux_driven', 'atdd', 'tdd']));
        $gates = $this->slugList((array) ($input['gates'] ?? []));
        $failedGates = $this->slugList((array) ($input['failed_gates'] ?? []));
        $evidenceRefs = $this->safeRefs((array) ($input['evidence_refs'] ?? []));

        $record = [
            'schema_version' => self::RECORD_SCHEMA_VERSION,
            'status' => $status,
            'task_type' => $taskType,
            'surface' => $surface,
            'workspace_hash' => $this->hashOrNull((string) ($input['workspace'] ?? '')),
            'company_profile_hash' => $this->hashOrNull((string) ($input['company_profile_ref'] ?? '')),
            'selected_drivers' => $drivers,
            'gates' => $gates,
            'failed_gates' => $failedGates,
            'evidence_refs' => $evidenceRefs,
            'effectiveness' => [
                'doctrine_effective' => $status === 'passed' && $failedGates === [],
                'needs_repair' => in_array($status, ['failed', 'blocked', 'partial'], true) || $failedGates !== [],
                'gate_failure_count' => count($failedGates),
                'evidence_ref_count' => count($evidenceRefs),
            ],
            'provider_policy' => [
                'raw_task_or_customer_source_returned' => false,
                'provider_prompt_returned' => false,
                'safe_for_aemor_projection' => true,
            ],
        ];
        $record['record_hash'] = MissionCanonicalHash::sha256($record);

        if ($storePath !== null && trim($storePath) !== '') {
            $this->appendRecord($record, $storePath);
        }

        return $record;
    }

    /**
     * @return array<string,mixed>
     */
    public function summarize(?string $storePath = null): array
    {
        $storePath = $this->storePath($storePath);
        $records = $this->readRecords($storePath);
        $total = count($records);
        $passed = collect($records)->where('status', 'passed')->count();
        $blocked = collect($records)->where('status', 'blocked')->count();
        $failed = collect($records)->where('status', 'failed')->count();
        $failedGateCounts = [];

        foreach ($records as $record) {
            foreach ((array) ($record['failed_gates'] ?? []) as $gate) {
                $failedGateCounts[$gate] = ($failedGateCounts[$gate] ?? 0) + 1;
            }
        }
        arsort($failedGateCounts);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'store_hash' => hash('sha256', $storePath),
            'records' => [
                'total' => $total,
                'passed' => $passed,
                'blocked' => $blocked,
                'failed' => $failed,
                'effectiveness_rate' => $total > 0 ? round($passed / $total, 4) : null,
            ],
            'top_failed_gates' => collect($failedGateCounts)
                ->take(20)
                ->map(fn (int $count, string $gate): array => ['gate' => $gate, 'count' => $count])
                ->values()
                ->all(),
            'learning_policy' => [
                'promote_to_aemor_when_records_exist' => $total > 0,
                'human_review_required_for_policy_change' => true,
                'raw_customer_source_returned' => false,
            ],
        ];
        $payload['outcome_memory_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function template(): array
    {
        $record = $this->record([
            'status' => 'passed',
            'task_type' => 'saas_dashboard_repair',
            'surface' => 'programming.frontend',
            'drivers' => ['ux_driven', 'atdd', 'tdd', 'data_evidence_driven'],
            'gates' => ['frontend_execution_gate', 'visual_quality_gate', 'anti_ai_slop_detector'],
            'failed_gates' => [],
            'evidence_refs' => ['evidence://frontend/visual-quality-report#sha256'],
        ]);

        return [
            'schema_version' => 'atlas.frontend.outcome_record_template.v1',
            'status' => 'ready',
            'record' => $record,
            'claim_policy' => [
                'template_is_not_outcome_evidence' => true,
                'record_real_outcomes_after_gates' => true,
            ],
            'template_hash' => MissionCanonicalHash::sha256($record),
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function appendRecord(array $record, string $storePath): void
    {
        AppendOnlyJsonlStore::appendUsingFilePutContents(
            $this->storePath($storePath),
            $record,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readRecords(string $storePath): array
    {
        return collect(AppendOnlyJsonlStore::read($storePath))
            ->filter(fn (array $record): bool => ($record['schema_version'] ?? null) === self::RECORD_SCHEMA_VERSION)
            ->values()
            ->all();
    }

    private function storePath(?string $storePath): string
    {
        $storePath = trim((string) $storePath);
        if ($storePath === '') {
            return storage_path('app/atlas/frontend-outcomes/outcomes.jsonl');
        }
        if (str_contains($storePath, "\0")) {
            throw new RuntimeException('store_path_invalid');
        }

        return $storePath;
    }

    private function status(string $status): string
    {
        $status = $this->slug($status);

        return in_array($status, ['passed', 'failed', 'blocked', 'partial', 'warning'], true) ? $status : 'partial';
    }

    private function slug(string $value): string
    {
        $slug = Str::of($value)->lower()->replaceMatches('/[^a-z0-9_.-]+/', '_')->trim('_')->toString();

        return $slug !== '' ? $slug : 'unknown';
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function slugList(array $values): array
    {
        return AiStringListNormalizer::uniqueMappedStrings(
            $values,
            fn (mixed $value): string => is_string($value) && trim($value) !== '' ? $this->slug($value) : '',
        );
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function safeRefs(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => Str::limit($value, 160, ''))
            ->filter(fn (string $value): bool => ! preg_match('/(raw_prompt|customer_source|secret|token|cookie)/i', $value))
            ->unique()
            ->values()
            ->all();
    }

    private function hashOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? hash('sha256', $value) : null;
    }
}
