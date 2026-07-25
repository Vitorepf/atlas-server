<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NamingPolicy;

use App\Services\Ai\SelfConstruction\Support\NamingPolicyRules;

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

    public const VIOLATION_VAGUE_GENERATED_NAME = 'vague_generated_slop_name';

    public const VIOLATION_FORBIDDEN_QUARANTINE_NAME = 'forbidden_quarantine_name_outside_quarantine_dir';

    public const VIOLATION_DUPLICATE_CONCEPT = 'duplicate_concept_name_in_batch';

    public const VIOLATION_BOUNDARY = 'boundary_violation_wrong_layer_suffix';

    public const FAMILY_DENSITY_LIMIT = 3;

    /**
     * @var non-empty-string
     */
    private const TARGET_ROOT = NamingPolicyRules::TARGET_ROOT;

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
                $newViolations[] = $this->violationEntry(
                    'batch:family:'.$family, $family, $count, self::VIOLATION_TEMPLATE_FARM_DENSITY,
                    sprintf(
                        'template-farm density: family "%s" appears %d times in this batch (limit %d). Each new file must bring a distinct capability.',
                        $family,
                        $count,
                        self::FAMILY_DENSITY_LIMIT,
                    ),
                    'consolidate the repeated family into one class, or rename the extras to distinct responsibility-specific names',
                );
            }
        }

        // Duplicate-concept check — files whose stem (version/copy marker stripped) collides
        // are the same responsibility named twice, not two distinct capabilities.
        $conceptCounts = [];
        $conceptFiles = [];
        foreach ($newRelativePaths as $relative) {
            if (! $this->isInScope($relative)) {
                continue;
            }
            $className = preg_replace('/\.php$/', '', basename($relative)) ?? basename($relative);
            $concept = $this->conceptStem($className);
            $conceptCounts[$concept] = ($conceptCounts[$concept] ?? 0) + 1;
            $conceptFiles[$concept][] = $relative;
        }
        foreach ($conceptCounts as $concept => $count) {
            if ($count < 2) {
                continue;
            }
            foreach ($conceptFiles[$concept] as $relative) {
                $className = preg_replace('/\.php$/', '', basename($relative)) ?? basename($relative);
                $newViolations[] = $this->violationEntry(
                    $relative, $className, $count, self::VIOLATION_DUPLICATE_CONCEPT,
                    sprintf(
                        'duplicate concept: "%s" collides with %d other file(s) in this batch after stripping version/copy markers.',
                        $concept,
                        $count - 1,
                    ),
                    'merge the duplicate files into one class, or rename to a distinct responsibility (not a version/copy suffix)',
                );
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
     * @return array{file: string, path: string, class_name: string, length: int, violation: string, violation_code: string, detail: string, suggested_name_or_action: string}|null
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
            return $this->violationEntry(
                $relativePath, $className, $length, self::VIOLATION_TOO_LONG,
                sprintf(
                    'class name has %d chars; policy is <=%d. See docs/engineering-knowledge-base/atlas-self-construction-catalog.md.',
                    $length,
                    self::MAX_CLASS_NAME_LENGTH,
                ),
                'shorten the class name to <='.self::MAX_CLASS_NAME_LENGTH.' characters while preserving the responsibility it names',
            );
        }

        if ($this->hasForbiddenApSuffix($className, $relativePath)) {
            return $this->violationEntry(
                $relativePath, $className, $length, self::VIOLATION_FORBIDDEN_AP_SUFFIX,
                sprintf(
                    'class name contains ApNNN suffix but does not emit %s. See docs/engineering-knowledge-base/atlas-self-construction-catalog.md (Contract 2).',
                    self::HANDOFF_PACKET_SCHEMA,
                ),
                'drop the ApNNN suffix, or emit the '.self::HANDOFF_PACKET_SCHEMA.' schema to earn it',
            );
        }

        if ($this->isVagueGeneratedName($className)) {
            return $this->violationEntry(
                $relativePath, $className, $length, self::VIOLATION_VAGUE_GENERATED_NAME,
                'class name is a bare generated-slop stem with no responsibility information of its own.',
                'rename to describe the concrete responsibility, e.g. "<Concept><Role>" instead of a generic stem like Helper/Manager/Util',
            );
        }

        if ($this->hasForbiddenQuarantineName($className, $relativePath)) {
            return $this->violationEntry(
                $relativePath, $className, $length, self::VIOLATION_FORBIDDEN_QUARANTINE_NAME,
                'class name references quarantine but the file does not live under a "_quarantine" directory.',
                'move the file into a "_quarantine" directory, or remove "Quarantine" from the class name',
            );
        }

        if ($this->violatesLayerBoundary($className)) {
            return $this->violationEntry(
                $relativePath, $className, $length, self::VIOLATION_BOUNDARY,
                'class name suffix belongs to a different architectural layer (HTTP/DB/framework), not the pure Self-Construction service tree.',
                'move this class to its proper layer directory (app/Http, database/migrations, app/Models, ...), or rename it to a service-layer responsibility name',
            );
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
        return NamingPolicyRules::isInScope($relativePath);
    }

    private function extractFamily(string $className): string
    {
        return NamingPolicyRules::extractFamily($className);
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

    /**
     * @return array{file: string, path: string, class_name: string, length: int, violation: string, violation_code: string, detail: string, suggested_name_or_action: string}
     */
    private function violationEntry(string $relativePath, string $className, int $length, string $code, string $detail, string $suggestedNameOrAction): array
    {
        return [
            'file' => $relativePath,
            'path' => $relativePath,
            'class_name' => $className,
            'length' => $length,
            'violation' => $code,
            'violation_code' => $code,
            'detail' => $detail,
            'suggested_name_or_action' => $suggestedNameOrAction,
        ];
    }

    private function isVagueGeneratedName(string $className): bool
    {
        return NamingPolicyRules::isVagueGeneratedName($className);
    }

    private function hasForbiddenQuarantineName(string $className, string $relativePath): bool
    {
        return NamingPolicyRules::hasForbiddenQuarantineName($className, $relativePath);
    }

    private function violatesLayerBoundary(string $className): bool
    {
        return NamingPolicyRules::violatesLayerBoundary($className);
    }

    /**
     * Strip a trailing version/duplicate marker (V2, 2, Copy, New) so "FooReducer" and
     * "FooReducer2" collapse to the same concept for duplicate detection.
     */
    private function conceptStem(string $className): string
    {
        return NamingPolicyRules::conceptStem($className);
    }
}
