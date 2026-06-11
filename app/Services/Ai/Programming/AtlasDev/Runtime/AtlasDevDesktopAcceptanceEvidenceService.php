<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Runtime;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Support\JsonFileStore;
use Carbon\CarbonImmutable;
use Throwable;

final class AtlasDevDesktopAcceptanceEvidenceService
{
    public const SCHEMA_VERSION = 'atlas.dev.desktop_acceptance_gate.v1';

    /**
     * @return array<string,mixed>
     */
    public function inspect(int $minRealRuns = 1, int $maxAgeHours = 168): array
    {
        $minRealRuns = max(1, $minRealRuns);
        $maxAgeHours = max(1, $maxAgeHours);
        $receiptsRoot = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR);
        $acceptanceDir = $receiptsRoot.DIRECTORY_SEPARATOR.'desktop_acceptance';
        $records = $this->loadRecords($acceptanceDir, $receiptsRoot, $maxAgeHours);
        $passedRecords = array_values(array_filter(
            $records,
            static fn (array $record): bool => ($record['status'] ?? null) === 'passed',
        ));

        $blockers = [];
        if (! is_file($acceptanceDir.DIRECTORY_SEPARATOR.'latest.json')) {
            $blockers[] = 'desktop_acceptance_latest_missing';
        }
        if ($records === []) {
            $blockers[] = 'desktop_acceptance_evidence_missing';
        }
        if (count($passedRecords) < $minRealRuns) {
            $blockers[] = 'desktop_acceptance_min_real_runs_not_met';
        }

        $latest = $this->latestRecord($records);
        if ($latest !== null && ($latest['status'] ?? null) !== 'passed') {
            $blockers[] = 'desktop_acceptance_latest_not_passed';
            foreach ((array) ($latest['blockers'] ?? []) as $blocker) {
                $blockers[] = (string) $blocker;
            }
        }

        $blockers = AtlasDevStringListNormalizer::uniqueTrimmedStrings($blockers);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'external_provider_call' => false,
            'requirements' => [
                'min_real_runs' => $minRealRuns,
                'max_age_hours' => $maxAgeHours,
                'latest_ref' => 'desktop_acceptance/latest.json',
            ],
            'summary' => [
                'records_found' => count($records),
                'passed_records' => count($passedRecords),
                'latest_run_id' => $latest['run_id'] ?? null,
                'latest_recorded_at' => $latest['recorded_at'] ?? null,
            ],
            'records' => $records,
            'remaining_blockers' => $blockers,
            'note' => 'Read-only audit of persisted real-smoke evidence. This does not call a provider and does not certify full product completion.',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRecords(string $acceptanceDir, string $receiptsRoot, int $maxAgeHours): array
    {
        if (! is_dir($acceptanceDir)) {
            return [];
        }

        $records = [];
        foreach (glob($acceptanceDir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            if (basename($path) === 'latest.json') {
                continue;
            }

            $decoded = JsonFileStore::readArray($path);
            if ($decoded === null) {
                $records[] = [
                    'ref' => 'desktop_acceptance/'.basename($path),
                    'status' => 'blocked',
                    'blockers' => ['desktop_acceptance_record_invalid_json'],
                ];

                continue;
            }

            $records[] = $this->normaliseRecord($decoded, 'desktop_acceptance/'.basename($path), $receiptsRoot, $maxAgeHours);
        }

        usort($records, static fn (array $a, array $b): int => strcmp(
            (string) ($b['recorded_at'] ?? ''),
            (string) ($a['recorded_at'] ?? ''),
        ));

        return $records;
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function normaliseRecord(array $record, string $ref, string $receiptsRoot, int $maxAgeHours): array
    {
        $runId = is_string($record['run_id'] ?? null) ? (string) $record['run_id'] : '';
        $blockers = $this->recordBlockers($record, $receiptsRoot, $maxAgeHours);

        return [
            'ref' => $ref,
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'run_id' => $runId !== '' ? $runId : null,
            'recorded_at' => is_string($record['recorded_at'] ?? null) ? $record['recorded_at'] : null,
            'provider' => $record['provider'] ?? null,
            'model_family' => $record['model_family'] ?? null,
            'completion_state' => $record['completion_state'] ?? null,
            'scope_guard_status' => $record['scope_guard_status'] ?? null,
            'verification_status' => $record['verification_status'] ?? null,
            'patch_apply_status' => $record['patch_apply_status'] ?? null,
            'receipt_ref' => $runId !== '' ? 'receipts/'.$runId.'/verification_receipt.json' : null,
            'receipt_hash' => $record['receipt_hash'] ?? null,
            'changed_files_count' => count((array) ($record['changed_files'] ?? [])),
            'tests_count' => (int) ($record['tests_count'] ?? 0),
            'honesty_flags_count' => count((array) ($record['honesty_flags'] ?? [])),
            'workspace_assertion_passed' => (bool) ($record['workspace_assertion_passed'] ?? false),
            'operator_confirmation' => [
                'token_issued' => (bool) data_get($record, 'operator_confirmation.token_issued', false),
                'token_consumed' => (bool) data_get($record, 'operator_confirmation.token_consumed', false),
                'compact_sdd_hash_pinned' => (bool) data_get($record, 'operator_confirmation.compact_sdd_hash_pinned', false),
            ],
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string,mixed>  $record
     * @return list<string>
     */
    private function recordBlockers(array $record, string $receiptsRoot, int $maxAgeHours): array
    {
        $blockers = [];
        $runId = is_string($record['run_id'] ?? null) ? (string) $record['run_id'] : '';

        $expectations = [
            'schema_version' => 'atlas.dev.desktop_acceptance_evidence.v1',
            'source_command' => 'atlas:dev:desktop:real-smoke',
            'status' => 'passed',
            'external_provider_call' => true,
            'provider' => 'claude_cli',
            'model_family' => 'sonnet',
            'completion_state' => 'passed',
            'scope_guard_status' => 'passed',
            'verification_status' => 'passed',
            'patch_apply_status' => 'applied',
            'workspace_assertion_passed' => true,
        ];

        foreach ($expectations as $key => $expected) {
            if (($record[$key] ?? null) !== $expected) {
                $blockers[] = 'desktop_acceptance_'.$key.'_invalid';
            }
        }

        if ($runId === '') {
            $blockers[] = 'desktop_acceptance_run_id_missing';
        }
        if (! $this->isRecent((string) ($record['recorded_at'] ?? ''), $maxAgeHours)) {
            $blockers[] = 'desktop_acceptance_evidence_stale_or_invalid_time';
        }
        if (! is_string($record['receipt_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/', (string) $record['receipt_hash']) !== 1) {
            $blockers[] = 'desktop_acceptance_receipt_hash_invalid';
        }
        if (count((array) ($record['changed_files'] ?? [])) < 1) {
            $blockers[] = 'desktop_acceptance_changed_files_missing';
        }
        if ((int) ($record['tests_count'] ?? 0) < 1) {
            $blockers[] = 'desktop_acceptance_tests_missing';
        }
        if (count((array) ($record['honesty_flags'] ?? [])) > 0) {
            $blockers[] = 'desktop_acceptance_honesty_flags_present';
        }
        if (! (bool) data_get($record, 'operator_confirmation.token_issued', false)) {
            $blockers[] = 'desktop_acceptance_confirmation_token_not_issued';
        }
        if (! (bool) data_get($record, 'operator_confirmation.token_consumed', false)) {
            $blockers[] = 'desktop_acceptance_confirmation_token_not_consumed';
        }
        if (! (bool) data_get($record, 'operator_confirmation.compact_sdd_hash_pinned', false)) {
            $blockers[] = 'desktop_acceptance_compact_sdd_hash_not_pinned';
        }
        if ($runId !== '' && ! is_file($receiptsRoot.DIRECTORY_SEPARATOR.$runId.DIRECTORY_SEPARATOR.'verification_receipt.json')) {
            $blockers[] = 'desktop_acceptance_verification_receipt_missing';
        }

        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($blockers);
    }

    private function isRecent(string $recordedAt, int $maxAgeHours): bool
    {
        if ($recordedAt === '') {
            return false;
        }

        try {
            $timestamp = CarbonImmutable::parse($recordedAt);
        } catch (Throwable) {
            return false;
        }

        return $timestamp->greaterThanOrEqualTo(CarbonImmutable::now()->subHours($maxAgeHours))
            && $timestamp->lessThanOrEqualTo(CarbonImmutable::now()->addMinute());
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>|null
     */
    private function latestRecord(array $records): ?array
    {
        if ($records === []) {
            return null;
        }

        $latestPath = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'desktop_acceptance'.DIRECTORY_SEPARATOR.'latest.json';
        if (is_file($latestPath)) {
            $decoded = JsonFileStore::readArray($latestPath);
            $runId = is_array($decoded) && is_string($decoded['run_id'] ?? null) ? $decoded['run_id'] : null;
            if ($runId !== null) {
                foreach ($records as $record) {
                    if (($record['run_id'] ?? null) === $runId) {
                        return $record;
                    }
                }

                return [
                    'status' => 'blocked',
                    'run_id' => $runId,
                    'recorded_at' => null,
                    'blockers' => ['desktop_acceptance_latest_run_not_found'],
                ];
            }
        }

        return $records[0];
    }
}
