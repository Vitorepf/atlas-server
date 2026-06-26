<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use Closure;

/**
 * CANONICAL-MODULE VIOLATION RULES concern, extracted from the god-class
 * {@see EngineeringDocumentationHealthService}.
 *
 * Owns every hard-gate violation rule: requiredDocsReport,
 * frontmatterViolations, humanGoldDocumentationViolations,
 * canonicalModuleCoverageViolations, canonicalModuleViolations,
 * agenticEngineeringAuthorityViolations and their direct helpers
 * (requiresMacroNaming, referencesDoc, looksLikeInternalPrompt,
 * nonEmptyListStrings).
 *
 * Constants and capabilities that STAY in the service are passed in:
 *  - isNonCanonicalArtifactPath as a Closure
 *  - the private constant values as constructor arguments
 */
class EngineeringDocumentationViolationRules
{
    /**
     * @param  array<string,int>  $requiredDocs
     * @param  list<string>  $requiredFrontmatter
     * @param  list<string>  $humanGoldFrontmatter
     * @param  list<string>  $humanGoldGraphIds
     * @param  string  $canonicalModuleSchema
     * @param  list<string>  $canonicalModuleRequiredFrontmatter
     * @param  list<string>  $canonicalMacroNamingFrontmatter
     * @param  list<string>  $canonicalModuleAllowedStatus
     * @param  list<string>  $canonicalModuleAllowedLayers
     * @param  list<string>  $canonicalModuleAllowedKinds
     * @param  list<string>  $canonicalModuleAllowedRisk
     * @param  list<string>  $canonicalModuleOptionalListFrontmatter
     * @param  list<string>  $canonicalModuleRequiredSections
     * @param  array<string,array{reason:string,requires:list<string>}>  $agenticEngineeringAuthorityLinks
     * @param  string  $agenticEngineeringAuthorityMapPath
     * @param  string  $agenticEngineeringAuthorityMapId
     * @param  string  $agenticEngineeringInventoryPath
     * @param  string  $agenticEngineeringInventoryId
     * @param  Closure(string): bool  $isNonCanonicalArtifactPath
     */
    public function __construct(
        private readonly array $requiredDocs,
        private readonly array $requiredFrontmatter,
        private readonly array $humanGoldFrontmatter,
        private readonly array $humanGoldGraphIds,
        private readonly string $canonicalModuleSchema,
        private readonly array $canonicalModuleRequiredFrontmatter,
        private readonly array $canonicalMacroNamingFrontmatter,
        private readonly array $canonicalModuleAllowedStatus,
        private readonly array $canonicalModuleAllowedLayers,
        private readonly array $canonicalModuleAllowedKinds,
        private readonly array $canonicalModuleAllowedRisk,
        private readonly array $canonicalModuleOptionalListFrontmatter,
        private readonly array $canonicalModuleRequiredSections,
        private readonly array $agenticEngineeringAuthorityLinks,
        private readonly string $agenticEngineeringAuthorityMapPath,
        private readonly string $agenticEngineeringAuthorityMapId,
        private readonly string $agenticEngineeringInventoryPath,
        private readonly string $agenticEngineeringInventoryId,
        private readonly Closure $isNonCanonicalArtifactPath,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array{items:array<int,array<string,mixed>>,missing:array<int,string>}
     */
    public function requiredDocsReport(array $docs): array
    {
        $byPath = collect($docs)->keyBy('path');
        $items = [];
        $missing = [];

        foreach ($this->requiredDocs as $path => $limit) {
            $doc = $byPath->get($path);
            $exists = is_array($doc);
            if (! $exists) {
                $missing[] = "{$path}: required documentation bootstrap file is missing";
            }

            $items[] = [
                'path' => $path,
                'exists' => $exists,
                'line_count' => $exists ? (int) $doc['line_count'] : null,
                'limit' => $limit,
            ];
        }

        return ['items' => $items, 'missing' => $missing];
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    public function frontmatterViolations(array $docs): array
    {
        $violations = [];

        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            $status = (string) $doc['status'];
            if (str_contains($path, '/archive/')
                || ($this->isNonCanonicalArtifactPath)($path)
                || in_array($status, ['archived', 'source_material'], true)) {
                continue;
            }

            $frontmatter = (array) $doc['frontmatter'];
            foreach ($this->requiredFrontmatter as $field) {
                if (! array_key_exists($field, $frontmatter) || $frontmatter[$field] === [] || $frontmatter[$field] === '') {
                    $violations[] = "{$path}: missing required frontmatter field [{$field}]";
                }
            }

            foreach ((array) $doc['frontmatter_errors'] as $error) {
                $violations[] = "{$path}: frontmatter parse error [{$error}]";
            }
        }

        return $violations;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    public function humanGoldDocumentationViolations(array $docs): array
    {
        $violations = [];

        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            $frontmatter = (array) $doc['frontmatter'];
            $graphId = (string) ($frontmatter['graph_id'] ?? '');
            if (! in_array($graphId, $this->humanGoldGraphIds, true)) {
                continue;
            }

            foreach ($this->humanGoldFrontmatter as $field) {
                if (! array_key_exists($field, $frontmatter) || trim((string) $frontmatter[$field]) === '') {
                    $violations[] = "{$path}: human gold doc missing field [{$field}]";
                }
            }

            foreach (['depends_on', 'flows_to', 'unlocks', 'governs'] as $field) {
                $value = $frontmatter[$field] ?? null;
                if (! is_array($value) || $this->nonEmptyListStrings($value) === []) {
                    $violations[] = "{$path}: human gold doc relation [{$field}] must be a non-empty list";
                }
            }

            $summaryFields = [
                'summary' => (string) ($frontmatter['summary'] ?? ''),
                'human_summary' => (string) ($frontmatter['human_summary'] ?? ''),
            ];
            foreach ($summaryFields as $field => $value) {
                if ($this->looksLikeInternalPrompt($value)) {
                    $violations[] = "{$path}: human gold doc field [{$field}] looks like internal prompt/task text";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<int,mixed>  $items
     * @return array<int,string>
     */
    public function nonEmptyListStrings(array $items): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $items
        ), fn (string $item): bool => $item !== ''));
    }

    public function looksLikeInternalPrompt(string $value): bool
    {
        $value = strtolower($value);
        foreach ([
            'prompt completo',
            'prompt para',
            'me manda',
            'voce pediu',
            'você pediu',
            'manda o claude',
            'manda o codex',
            'manda o gemini',
            'goal enorme',
            'type something',
            'chat about this',
            'skip interview',
        ] as $marker) {
            if (str_contains($value, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    public function canonicalModuleCoverageViolations(array $docs): array
    {
        $violations = [];

        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            $status = (string) $doc['status'];
            if (str_contains($path, '/archive/') || str_contains($path, '/templates/') || ($this->isNonCanonicalArtifactPath)($path) || in_array($status, ['archived', 'source_material'], true)) {
                continue;
            }

            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== $this->canonicalModuleSchema) {
                $violations[] = "{$path}: official non-archive docs must declare doc_schema [".$this->canonicalModuleSchema.']';
            }
        }

        return $violations;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    public function canonicalModuleViolations(array $docs): array
    {
        $violations = [];
        $graphIds = [];

        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== $this->canonicalModuleSchema) {
                continue;
            }
            if (str_contains($path, '/templates/') || ($this->isNonCanonicalArtifactPath)($path)) {
                continue;
            }

            foreach ($this->canonicalModuleRequiredFrontmatter as $field) {
                if (! array_key_exists($field, $frontmatter) || $frontmatter[$field] === [] || $frontmatter[$field] === '') {
                    $violations[] = "{$path}: missing canonical module field [{$field}]";
                }
            }

            if (array_key_exists('macro_layer', $frontmatter) && ! is_bool($frontmatter['macro_layer'])) {
                $violations[] = "{$path}: canonical module field [macro_layer] must be boolean";
            }

            if ($this->requiresMacroNaming($frontmatter)) {
                foreach ($this->canonicalMacroNamingFrontmatter as $field) {
                    if (! array_key_exists($field, $frontmatter) || trim((string) $frontmatter[$field]) === '') {
                        $violations[] = "{$path}: macro structural layer missing required naming field [{$field}]";
                    }
                }
            }

            $graphId = trim((string) ($frontmatter['graph_id'] ?? ''));
            if ($graphId !== '') {
                if (isset($graphIds[$graphId])) {
                    $violations[] = "{$path}: duplicate graph_id [{$graphId}] already used by {$graphIds[$graphId]}";
                }
                $graphIds[$graphId] = $path;
                if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $graphId)) {
                    $violations[] = "{$path}: graph_id [{$graphId}] must be a stable lowercase ASCII slug";
                }
            }

            $graphStatus = (string) ($frontmatter['graph_status'] ?? '');
            if ($graphStatus !== '' && ! in_array($graphStatus, $this->canonicalModuleAllowedStatus, true)) {
                $violations[] = "{$path}: graph_status [{$graphStatus}] is not allowed";
            }

            $graphLayer = (string) ($frontmatter['graph_layer'] ?? '');
            if ($graphLayer !== '' && ! in_array($graphLayer, $this->canonicalModuleAllowedLayers, true)) {
                $violations[] = "{$path}: graph_layer [{$graphLayer}] is not allowed";
            }

            $graphKind = (string) ($frontmatter['graph_kind'] ?? '');
            if ($graphKind !== '' && ! in_array($graphKind, $this->canonicalModuleAllowedKinds, true)) {
                $violations[] = "{$path}: graph_kind [{$graphKind}] is not allowed";
            }

            $graphSource = (string) ($frontmatter['graph_source'] ?? '');
            if ($graphSource !== '' && $graphSource !== 'repo') {
                $violations[] = "{$path}: graph_source must be [repo] for canonical engineering docs";
            }

            $riskLevel = (string) ($frontmatter['risk_level'] ?? '');
            if ($riskLevel !== '' && ! in_array($riskLevel, $this->canonicalModuleAllowedRisk, true)) {
                $violations[] = "{$path}: risk_level [{$riskLevel}] is not allowed";
            }

            foreach (['repo_paths', 'allowed_changes', 'forbidden_changes', 'evidence', 'required_tests', 'next_actions'] as $field) {
                if (array_key_exists($field, $frontmatter) && ! is_array($frontmatter[$field])) {
                    $violations[] = "{$path}: canonical module field [{$field}] must be a list";
                }
            }

            foreach ($this->canonicalModuleOptionalListFrontmatter as $field) {
                if (array_key_exists($field, $frontmatter) && ! is_array($frontmatter[$field])) {
                    $violations[] = "{$path}: optional canonical module field [{$field}] must be a list";
                }
            }

            if (array_key_exists('requires_evidence', $frontmatter) && ! is_bool($frontmatter['requires_evidence'])) {
                $violations[] = "{$path}: canonical module field [requires_evidence] must be boolean";
            }

            foreach ((array) ($frontmatter['repo_paths'] ?? []) as $repoPath) {
                $repoPath = trim((string) $repoPath);
                if ($repoPath === '' || str_starts_with($repoPath, 'external:') || str_starts_with($repoPath, 'future:')) {
                    continue;
                }
                if (! file_exists(base_path($repoPath))) {
                    $violations[] = "{$path}: repo_paths entry [{$repoPath}] does not exist";
                }
            }

            $body = (string) ($doc['body'] ?? '');
            foreach ($this->canonicalModuleRequiredSections as $section) {
                if (! preg_match('/^##\s+'.preg_quote($section, '/').'\s*$/mi', $body)) {
                    $violations[] = "{$path}: missing canonical module section [{$section}]";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    public function requiresMacroNaming(array $frontmatter): bool
    {
        if (($frontmatter['macro_layer'] ?? false) === true) {
            return true;
        }

        foreach ($this->canonicalMacroNamingFrontmatter as $field) {
            if (array_key_exists($field, $frontmatter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,string>
     */
    public function agenticEngineeringAuthorityViolations(array $docs): array
    {
        $violations = [];
        $byPath = collect($docs)->keyBy('path');

        foreach ($this->agenticEngineeringAuthorityLinks as $path => $rule) {
            $doc = $byPath->get($path);
            if (! is_array($doc)) {
                $violations[] = "{$path}: missing Agentic Engineering authority-chain doc [{$rule['reason']}]";
                continue;
            }

            $frontmatter = (array) ($doc['frontmatter'] ?? []);
            $body = (string) ($doc['body'] ?? '');
            foreach ($rule['requires'] as $requiredPath) {
                if ($this->referencesDoc($frontmatter, $body, $requiredPath)) {
                    continue;
                }

                $violations[] = "{$path}: Agentic Engineering authority chain must reference [{$requiredPath}] ({$rule['reason']})";
            }
        }

        return $violations;
    }

    /**
     * @param  array<string,mixed>  $frontmatter
     */
    public function referencesDoc(array $frontmatter, string $body, string $requiredPath): bool
    {
        $requiredId = match ($requiredPath) {
            $this->agenticEngineeringAuthorityMapPath => $this->agenticEngineeringAuthorityMapId,
            $this->agenticEngineeringInventoryPath => $this->agenticEngineeringInventoryId,
            default => preg_replace('/\.md$/', '', basename($requiredPath)) ?: $requiredPath,
        };
        foreach (['related_paths', 'depends_on', 'flows_to', 'governs', 'related_to', 'influenced_by', 'evidence'] as $field) {
            foreach ((array) ($frontmatter[$field] ?? []) as $value) {
                $value = (string) $value;
                if ($value === $requiredPath || $value === $requiredId) {
                    return true;
                }
                if (str_contains($value, basename($requiredPath))) {
                    return true;
                }
            }
        }

        return str_contains($body, basename($requiredPath)) || str_contains($body, $requiredPath);
    }
}
