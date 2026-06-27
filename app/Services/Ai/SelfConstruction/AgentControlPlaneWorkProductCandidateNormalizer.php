<?php

namespace App\Services\Ai\SelfConstruction;



use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
/**
 * Normalizes operator/synthetic work-product candidates without scanning the
 * workspace or collecting artifacts.
 */
final class AgentControlPlaneWorkProductCandidateNormalizer
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    use KsortsArraysByReference;

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function normalize(array $candidates): array
    {
        $normalized = [];
        $violations = [];
        $seen = [];

        foreach ($candidates as $index => $candidate) {
            $artifactId = $this->scalarString($candidate['artifact_id'] ?? '');
            $path = $this->normalizePath($this->scalarString($candidate['path'] ?? ''));
            $artifactHash = strtolower($this->scalarString($candidate['artifact_hash'] ?? ''));

            if ($artifactId === '') {
                $violations[] = $this->violation('missing_artifact_id', $index, 'Candidate requires an artifact id.');
            } elseif (isset($seen[$artifactId])) {
                $violations[] = $this->violation('duplicate_artifact_id', $index, 'Artifact id must be unique.');
            }
            $seen[$artifactId] = true;

            foreach ($this->pathViolations($path, $index) as $violation) {
                $violations[] = $violation;
            }
            if (preg_match('/^[a-f0-9]{64}$/', $artifactHash) !== 1) {
                $violations[] = $this->violation('missing_artifact_hash', $index, 'Candidate requires a sha256 artifact hash.');
            }
            if ((bool) ($candidate['workspace_scan_requested'] ?? false)) {
                $violations[] = $this->violation('workspace_scan_requested', $index, 'Automatic workspace scans are not allowed.');
            }
            if ((bool) ($candidate['write_requested'] ?? false)) {
                $violations[] = $this->violation('write_requested', $index, 'Work product writes are not allowed.');
            }
            if ((bool) ($candidate['completion_claim_requested'] ?? false)) {
                $violations[] = $this->violation('completion_claim_requested', $index, 'Completion claims are not allowed.');
            }

            $normalized[] = [
                'artifact_id' => $artifactId,
                'path' => $path,
                'artifact_hash' => $artifactHash,
                'artifact_type' => $this->classify($path),
                'source' => $this->scalarString($candidate['source'] ?? 'manual_manifest'),
                'workspace_scan_requested' => false,
                'write_requested' => false,
                'completion_claim_requested' => false,
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => [$a['path'], $a['artifact_id']] <=> [$b['path'], $b['artifact_id']]);

        return [
            'status' => $violations === [] ? 'normalized' : 'normalization_blocked',
            'candidate_count' => count($normalized),
            'normalized_work_products' => $normalized,
            'violations' => $violations,
            'violation_count' => count($violations),
            'normalized_work_products_hash' => $this->stableHash($normalized),
            'workspace_scan_allowed' => false,
            'work_product_write_allowed' => false,
            'completion_claim_allowed' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function pathViolations(string $path, int $index): array
    {
        $violations = [];
        if ($path === '') {
            $violations[] = $this->violation('missing_artifact_path', $index, 'Candidate requires an artifact path.');
        }
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1) {
            $violations[] = $this->violation('absolute_path_not_allowed', $index, 'Absolute paths are not allowed.');
        }
        if (str_contains($path, '..')) {
            $violations[] = $this->violation('path_traversal_not_allowed', $index, 'Path traversal is not allowed.');
        }
        foreach (['vendor/', 'node_modules/', 'storage/framework/cache/', '.env', '.git/'] as $forbidden) {
            if (str_starts_with($path, $forbidden) || str_contains($path, '/'.$forbidden)) {
                $violations[] = $this->violation('forbidden_path_segment', $index, 'Path is outside the allowed work-product collection surface.');
            }
        }

        return $violations;
    }

    private function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path));
    }

    private function classify(string $path): string
    {
        $lower = strtolower($path);
        if (str_contains($lower, 'tests/') && str_ends_with($lower, '.php')) {
            return 'test_file';
        }
        if (str_ends_with($lower, '.php')) {
            return 'php_source';
        }
        if (str_ends_with($lower, '.md')) {
            return 'markdown_doc';
        }
        if (str_ends_with($lower, '.json')) {
            return 'json_artifact';
        }
        if (str_ends_with($lower, '.ts') || str_ends_with($lower, '.tsx')) {
            return 'typescript_source';
        }
        if (str_ends_with($lower, '.sh')) {
            return 'shell_script';
        }

        return 'unknown';
    }

    private function violation(string $code, int $index, string $message): array
    {
        return ['code' => $code, 'candidate_index' => $index, 'message' => $message];
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($this->ksortRecursiveByReference($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

}
