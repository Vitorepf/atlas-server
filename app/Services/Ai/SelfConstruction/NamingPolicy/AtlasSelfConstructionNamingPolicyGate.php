<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NamingPolicy;

/**
 * Atlas Self-Construction OS — naming policy gate.
 *
 * Enforces the canonical rule declared in
 * `docs/engineering-knowledge-base/atlas-self-construction-catalog.md`:
 *
 *  - New class files in `app/Services/Ai/SelfConstruction/**` MUST have a
 *    class name (sans `.php`) of at most 50 characters.
 *  - The `ApXxx` suffix (e.g. `Ap374HandoffPacket`) is only admitted when
 *    the service emits the `atlas.self_construction.handoff_packet.v1`
 *    schema; AP-number as a standalone naming hint is forbidden for new
 *    files.
 *
 * The gate is intentionally additive and non-mutating: it returns a
 * structured report, never deletes or renames files. Callers (CI hooks,
 * `atlas:engineering:knowledge code-gate`, pre-commit) decide what to do.
 *
 * Existing files violating the policy are GRANDFATHERED — the gate
 * separates `existing_violations` (informational) from `new_violations`
 * (fail-fast). Callers pass the list of "new" files explicitly so the
 * gate stays decoupled from `git diff` parsing.
 */
final class AtlasSelfConstructionNamingPolicyGate
{
    public const SCHEMA_VERSION = 'atlas.self_construction.naming_policy_gate.v1';

    public const MAX_CLASS_NAME_LENGTH = 50;

    public const VIOLATION_TOO_LONG = 'class_name_too_long';

    public const VIOLATION_FORBIDDEN_AP_SUFFIX = 'forbidden_ap_suffix_without_handoff_packet_schema';

    public const VIOLATION_TEMPLATE_FARM_DENSITY = 'template_farm_naming_density';

    public const FAMILY_DENSITY_LIMIT = 3;

    /**
     * @var non-empty-string
     */
    private const TARGET_ROOT = 'app/Services/Ai/SelfConstruction';

    private const HANDOFF_PACKET_SCHEMA = 'atlas.self_construction.handoff_packet.v1';

    /**
     * @var non-empty-string
     */
    private string $absoluteRoot;

    /**
     * @var non-empty-string
     */
    private string $repoRoot;

    public function __construct(?string $absoluteRoot = null, ?string $repoRoot = null)
    {
        $this->absoluteRoot = $absoluteRoot ?? base_path(self::TARGET_ROOT);
        // When tests pass a sandbox as absoluteRoot, the repo root is the
        // sandbox itself (the file-existence check during AP-suffix
        // resolution then targets the sandbox, not the real app tree).
        $this->repoRoot = $repoRoot ?? ($absoluteRoot !== null ? $absoluteRoot : base_path());
    }

    /**
     * Evaluate the policy for a snapshot.
     *
     * @param  list<string>  $newRelativePaths  Files to check fail-fast (e.g. from `git diff --diff-filter=A`).
     * @return array{
     *   schema_version: string,
     *   status: 'ok'|'failed',
     *   policy: array{max_class_name_length: int, target_root: string},
     *   new_violations: list<array{file: string, class_name: string, length: int, violation: string, detail: string}>,
     *   existing_violations: array{count: int, sample: list<string>},
     *   summary: array{
     *     scanned_existing: int,
     *     new_checked: int,
     *     new_failed: int,
     *     existing_violation_count: int
     *   }
     * }
     */
    public function evaluate(array $newRelativePaths = []): array
    {
        $newViolations = [];
        foreach ($newRelativePaths as $relative) {
            $violation = $this->checkPath($relative);
            if ($violation !== null) {
                $newViolations[] = $violation;
            }
        }

        // Template-farm density check — applies only to newRelativePaths.
        $familyCounts = [];
        foreach ($newRelativePaths as $relative) {
            if (! $this->isInScope($relative)) {
                continue;
            }
            $className = preg_replace('/\.php$/', '', basename($relative)) ?? basename($relative);
            $family = $this->extractFamily($className);
            $familyCounts[$family] = ($familyCounts[$family] ?? 0) + 1;
        }
        $failedFamilies = [];
        foreach ($familyCounts as $family => $count) {
            if ($count >= self::FAMILY_DENSITY_LIMIT) {
                $failedFamilies[] = $family;
                $newViolations[] = [
                    'file' => 'batch:family:'.$family,
                    'class_name' => $family,
                    'length' => $count,
                    'violation' => self::VIOLATION_TEMPLATE_FARM_DENSITY,
                    'detail' => sprintf(
                        'template-farm density: family "%s" appears %d times in this batch (limit %d). Each new file must bring a distinct capability.',
                        $family,
                        $count,
                        self::FAMILY_DENSITY_LIMIT,
                    ),
                ];
            }
        }

        $existing = $this->scanExisting();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $newViolations === [] ? 'ok' : 'failed',
            'policy' => [
                'max_class_name_length' => self::MAX_CLASS_NAME_LENGTH,
                'target_root' => self::TARGET_ROOT,
            ],
            'new_violations' => $newViolations,
            'existing_violations' => [
                'count' => count($existing),
                'sample' => array_slice($existing, 0, 10),
            ],
            'summary' => [
                'scanned_existing' => $this->countScannableFiles(),
                'new_checked' => count($newRelativePaths),
                'new_failed' => count($newViolations),
                'existing_violation_count' => count($existing),
                'families_inspected' => count($familyCounts),
                'families_failed' => count($failedFamilies),
            ],
        ];
    }

    /**
     * @return array{file: string, class_name: string, length: int, violation: string, detail: string}|null
     */
    public function checkPath(string $relativePath): ?array
    {
        if (! $this->isInScope($relativePath)) {
            return null;
        }

        $basename = basename($relativePath);
        $className = preg_replace('/\.php$/', '', $basename) ?? $basename;
        $length = mb_strlen($className);

        if ($length > self::MAX_CLASS_NAME_LENGTH) {
            return [
                'file' => $relativePath,
                'class_name' => $className,
                'length' => $length,
                'violation' => self::VIOLATION_TOO_LONG,
                'detail' => sprintf(
                    'class name has %d chars; policy is <=%d. See docs/engineering-knowledge-base/atlas-self-construction-catalog.md.',
                    $length,
                    self::MAX_CLASS_NAME_LENGTH,
                ),
            ];
        }

        if ($this->hasForbiddenApSuffix($className, $relativePath)) {
            return [
                'file' => $relativePath,
                'class_name' => $className,
                'length' => $length,
                'violation' => self::VIOLATION_FORBIDDEN_AP_SUFFIX,
                'detail' => sprintf(
                    'class name contains ApNNN suffix but does not emit %s. See docs/engineering-knowledge-base/atlas-self-construction-catalog.md (Contract 2).',
                    self::HANDOFF_PACKET_SCHEMA,
                ),
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function scanExisting(): array
    {
        $violations = [];
        foreach ($this->listExistingFiles() as $relative) {
            $basename = basename($relative);
            $className = preg_replace('/\.php$/', '', $basename) ?? $basename;
            if (mb_strlen($className) > self::MAX_CLASS_NAME_LENGTH) {
                $violations[] = $relative;
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function listExistingFiles(): array
    {
        if (! is_dir($this->absoluteRoot)) {
            return [];
        }

        $files = [];
        $dirIt = new \RecursiveDirectoryIterator($this->absoluteRoot, \RecursiveDirectoryIterator::SKIP_DOTS);
        // skip any entry (file or directory) whose name starts with '_' — e.g. _quarantine
        $filterIt = new \RecursiveCallbackFilterIterator($dirIt, static fn (\SplFileInfo $f): bool => ! str_starts_with($f->getFilename(), '_'));
        foreach (new \RecursiveIteratorIterator($filterIt) as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            // build the relative path by replacing the absolute root prefix with TARGET_ROOT
            $relative = self::TARGET_ROOT.str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($this->absoluteRoot)));
            $files[] = $relative;
        }

        sort($files);

        return $files;
    }

    private function countScannableFiles(): int
    {
        return count($this->listExistingFiles());
    }

    private function isInScope(string $relativePath): bool
    {
        $normalized = ltrim($relativePath, './');

        return str_starts_with($normalized, self::TARGET_ROOT.'/')
            && str_ends_with($normalized, '.php');
    }

    private function extractFamily(string $className): string
    {
        // Family = last PascalCase word (e.g. "Service", "Gate", "Policy").
        if (preg_match('/([A-Z][a-z]+)$/', $className, $m) === 1) {
            return $m[1];
        }

        return $className;
    }

    private function hasForbiddenApSuffix(string $className, string $relativePath): bool
    {
        if (preg_match('/Ap\d{2,4}[A-Z]/', $className) !== 1) {
            return false;
        }

        // Allow when the file actually declares the handoff packet schema.
        // We do this best-effort: read the file and look for the schema string.
        // If the file does not exist yet (gate called on a planned path),
        // we conservatively fail — operator must rename or add the schema.
        $absolute = $this->repoRoot.'/'.ltrim($relativePath, '/');
        if (! is_file($absolute)) {
            return true;
        }
        $contents = file_get_contents($absolute);
        if ($contents === false) {
            return true;
        }

        return ! str_contains($contents, self::HANDOFF_PACKET_SCHEMA);
    }
}
