<?php

namespace App\Services\Ai\Programming\Governance;

use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Support\DatabaseTableAvailability;
use RuntimeException;
use Throwable;

/**
 * Append-only evidence ledger for Programming Governance work items.
 *
 * Persists each receipt twice: as a reference inside the work item's
 * `evidence_refs_json`, and as a full row in the canonical
 * `atlas_engineering_evidence` ledger.
 *
 * Hardens each receipt with file hashes, output hash, optional diff content
 * read from disk, and an optional `parent_receipt_id` linking. Receipts that
 * declare files that do not exist are rejected — evidence must be auditable.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md (Contrato 5)
 */
class ProgrammingEvidenceLedger
{
    /** @var list<string> */
    private const PROOF_FIELDS = ['command', 'output', 'files', 'tests', 'diff_path', 'artifact_url'];

    private const MAX_DIFF_BYTES = 65536;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     *
     * @throws RuntimeException when the engineering evidence ledger is missing,
     *                          rejects the row, or the receipt fails integrity
     *                          checks (declared file missing, etc.).
     */
    public function record(AtlasProgrammingWorkItem $workItem, array $input): array
    {
        if (! $this->engineeringEvidenceAvailable()) {
            throw new RuntimeException('atlas_engineering_evidence_table_missing');
        }

        $receipt = $this->normalize($input);
        $receipt = $this->harden($workItem, $receipt);

        $payload = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $receipt['receipt_id'] = hash('sha256', $workItem->id.'|'.$payload.'|'.microtime(true));
        $receipt['recorded_at'] = now()->toJSON();

        $summary = (string) ($receipt['summary']
            ?? $this->synthesizedSummary($workItem, $receipt));
        if (trim($summary) === '') {
            $summary = "Programming Governance receipt for work item {$workItem->code}";
        }

        try {
            $row = AtlasEngineeringEvidence::query()->create([
                'task_id' => $workItem->id,
                'project_id' => null,
                'project_step_id' => null,
                'trace_id' => null,
                'evidence_type' => substr((string) ($receipt['evidence_type'] ?? 'programming.governance.receipt'), 0, 80),
                'target_id' => substr($workItem->id, 0, 120),
                'status' => substr((string) ($receipt['status'] ?? 'recorded'), 0, 32),
                'confidence' => $receipt['confidence'] !== null ? (float) $receipt['confidence'] : null,
                'summary' => $summary,
                'command' => $this->truncate((string) ($receipt['command'] ?? ''), 500) ?: null,
                'artifact_url' => $receipt['artifact_url'] ?? null,
                'output_excerpt' => $receipt['output'] ?? null,
                'files' => $receipt['files'] ?? [],
                'metadata' => [
                    'work_item_id' => $workItem->id,
                    'work_item_code' => $workItem->code,
                    'receipt_id' => $receipt['receipt_id'],
                    'parent_receipt_id' => $receipt['parent_receipt_id'] ?? null,
                    'tests' => $receipt['tests'] ?? [],
                    'gaps' => $receipt['gaps'] ?? [],
                    'diff_path' => $receipt['diff_path'] ?? null,
                    'file_hashes' => $receipt['file_hashes'] ?? [],
                    'output_hash' => $receipt['output_hash'] ?? null,
                    'diff_hash' => $receipt['diff_hash'] ?? null,
                    'diff_bytes' => $receipt['diff_bytes'] ?? null,
                ],
                'source' => 'atlas_programming_governance',
                'recorded_at' => now(),
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'engineering_evidence_write_failed:'.$e::class.':'.$e->getMessage(),
                previous: $e,
            );
        }

        $receipt['summary'] = $summary;
        $receipt['storage'] = [
            'persisted' => true,
            'id' => $row->id,
            'table' => 'atlas_engineering_evidence',
        ];

        return $receipt;
    }

    public function hasMechanicalProof(array $receipt): bool
    {
        foreach (self::PROOF_FIELDS as $field) {
            $value = $receipt[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Augment the receipt with file hashes, output hash and diff content.
     *
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function harden(AtlasProgrammingWorkItem $workItem, array $receipt): array
    {
        $workspace = $this->resolveWorkspace($workItem);

        $fileHashes = [];
        $missingFiles = [];
        foreach ((array) ($receipt['files'] ?? []) as $relative) {
            if (! is_string($relative) || trim($relative) === '') {
                continue;
            }
            $absolute = $this->joinPath($workspace, $relative);
            if (! is_file($absolute)) {
                $missingFiles[] = $relative;

                continue;
            }
            $contents = @file_get_contents($absolute);
            if ($contents === false) {
                $missingFiles[] = $relative;

                continue;
            }
            $fileHashes[] = [
                'path' => $relative,
                'sha256' => hash('sha256', $contents),
                'bytes' => strlen($contents),
            ];
        }
        if ($missingFiles !== []) {
            throw new RuntimeException(
                'evidence_files_not_found:'.implode(',', $missingFiles),
            );
        }
        $receipt['file_hashes'] = $fileHashes;

        $output = $receipt['output'] ?? null;
        $receipt['output_hash'] = is_string($output) && $output !== ''
            ? hash('sha256', $output)
            : null;

        $diffPath = $receipt['diff_path'] ?? null;
        if (is_string($diffPath) && $diffPath !== '') {
            $absoluteDiff = $this->joinPath($workspace, $diffPath);
            if (! is_file($absoluteDiff)) {
                throw new RuntimeException("evidence_diff_path_not_found:{$diffPath}");
            }
            $diffContents = @file_get_contents($absoluteDiff);
            if ($diffContents === false) {
                throw new RuntimeException("evidence_diff_path_unreadable:{$diffPath}");
            }
            $receipt['diff_hash'] = hash('sha256', $diffContents);
            $receipt['diff_bytes'] = strlen($diffContents);
            $receipt['diff_excerpt'] = strlen($diffContents) > self::MAX_DIFF_BYTES
                ? substr($diffContents, 0, self::MAX_DIFF_BYTES)
                : $diffContents;
        }

        if (isset($receipt['parent_receipt_id']) && is_string($receipt['parent_receipt_id'])) {
            $parentExists = false;
            foreach ((array) $workItem->evidence_refs_json as $existing) {
                if (is_array($existing) && ($existing['receipt_id'] ?? null) === $receipt['parent_receipt_id']) {
                    $parentExists = true;
                    break;
                }
            }
            if (! $parentExists) {
                throw new RuntimeException("evidence_parent_receipt_not_found:{$receipt['parent_receipt_id']}");
            }
        }

        return $receipt;
    }

    private function resolveWorkspace(AtlasProgrammingWorkItem $workItem): string
    {
        $declared = is_string($workItem->workspace ?? null) ? trim((string) $workItem->workspace) : '';
        if ($declared !== '' && is_dir($declared)) {
            return rtrim($declared, '/');
        }

        return rtrim(base_path(), '/');
    }

    private function joinPath(string $workspace, string $relative): string
    {
        if ($relative === '' || $relative[0] === '/') {
            return $relative;
        }

        return $workspace.'/'.ltrim($relative, '/');
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function synthesizedSummary(AtlasProgrammingWorkItem $workItem, array $receipt): string
    {
        $parts = [];
        if (! empty($receipt['command'])) {
            $parts[] = 'command='.$receipt['command'];
        }
        if (! empty($receipt['files'])) {
            $parts[] = 'files='.count($receipt['files']);
        }
        if (! empty($receipt['tests'])) {
            $parts[] = 'tests='.count($receipt['tests']);
        }
        $tail = $parts !== [] ? ' ('.implode(', ', $parts).')' : '';

        return "Programming Governance receipt for {$workItem->code}{$tail}";
    }

    private function truncate(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $normalized = [
            'schema_version' => 'atlas.programming.evidence_receipt.v2',
            'evidence_type' => $input['evidence_type'] ?? 'programming.governance.receipt',
            'status' => $input['status'] ?? 'recorded',
            'command' => $this->stringOrNull($input['command'] ?? null),
            'output' => $this->stringOrNull($input['output'] ?? null),
            'files' => array_values(array_filter((array) ($input['files'] ?? []), 'is_string')),
            'tests' => array_values(array_filter((array) ($input['tests'] ?? []), 'is_string')),
            'gaps' => array_values(array_filter((array) ($input['gaps'] ?? []), 'is_string')),
            'diff_path' => $this->stringOrNull($input['diff_path'] ?? null),
            'artifact_url' => $this->stringOrNull($input['artifact_url'] ?? null),
            'summary' => $this->stringOrNull($input['summary'] ?? null),
            'confidence' => isset($input['confidence']) ? (float) $input['confidence'] : null,
            'parent_receipt_id' => $this->stringOrNull($input['parent_receipt_id'] ?? null),
        ];
        foreach ($input as $key => $value) {
            if (! array_key_exists($key, $normalized)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $value;
    }

    private function engineeringEvidenceAvailable(): bool
    {
        return DatabaseTableAvailability::has('atlas_engineering_evidence');
    }
}
