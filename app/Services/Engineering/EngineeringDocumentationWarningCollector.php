<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use Closure;

/**
 * DOC-WARNING COLLECTION concern, extracted from the god-class
 * {@see EngineeringDocumentationHealthService}.
 *
 * Owns the five soft-warning rules (statusValueWarnings,
 * futurePlannedClarityWarnings, deprecatedSuccessorWarnings,
 * schemaCitationWarnings, ambiguousNamingWarnings) plus their private
 * helpers (ambiguousTermsFound, mentionsNonRuntimeMarker).
 *
 * Capabilities and constant values that STAY in the service are passed in:
 *  - shouldSkipPath / referencesGlossary / relativeGlossaryPath as Closures
 *    (the SAME closure-binding pattern used by AtlasLoopRefillerSupplyLaneCoordinator)
 *  - the seven private constant values as scalar/array constructor arguments
 */
class EngineeringDocumentationWarningCollector
{
    /**
     * @param  list<string>  $ambiguousNamingTerms
     * @param  string  $canonicalGlossaryId
     * @param  string  $canonicalGlossaryPath
     * @param  list<string>  $canonicalModuleAllowedStatus
     * @param  string  $canonicalModuleSchema
     * @param  list<string>  $nonCanonicalArtifactPathMarkers
     * @param  list<string>  $statusValueToleratedLegacy
     * @param  Closure(string): bool  $shouldSkipPath
     * @param  Closure(array<string,mixed>, string): bool  $referencesGlossary
     * @param  Closure(): string  $relativeGlossaryPath
     */
    public function __construct(
        private readonly array $ambiguousNamingTerms,
        private readonly string $canonicalGlossaryId,
        private readonly string $canonicalGlossaryPath,
        private readonly array $canonicalModuleAllowedStatus,
        private readonly string $canonicalModuleSchema,
        private readonly array $nonCanonicalArtifactPathMarkers,
        private readonly array $statusValueToleratedLegacy,
        private readonly Closure $shouldSkipPath,
        private readonly Closure $referencesGlossary,
        private readonly Closure $relativeGlossaryPath,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    public function collectWarnings(array $docs): array
    {
        return array_values(array_merge(
            $this->statusValueWarnings($docs),
            $this->futurePlannedClarityWarnings($docs),
            $this->deprecatedSuccessorWarnings($docs),
            $this->schemaCitationWarnings($docs),
            $this->ambiguousNamingWarnings($docs),
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    public function statusValueWarnings(array $docs): array
    {
        $warnings = [];
        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            if (($this->shouldSkipPath)($path)) {
                continue;
            }
            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== $this->canonicalModuleSchema) {
                continue;
            }
            $status = (string) $doc['status'];
            if ($status === '' || $status === 'missing') {
                continue;
            }
            if (in_array($status, $this->canonicalModuleAllowedStatus, true)) {
                continue;
            }
            if (in_array($status, $this->statusValueToleratedLegacy, true)) {
                continue;
            }
            $warnings[] = [
                'rule' => 'status_value_non_canonical',
                'path' => $path,
                'message' => "{$path}: status [{$status}] is not in canonical set [planned|future|building|active|deprecated]; see atlas-documentation-status-cleanup-plan.md",
            ];
        }

        return $warnings;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    public function futurePlannedClarityWarnings(array $docs): array
    {
        $warnings = [];
        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            if (($this->shouldSkipPath)($path)) {
                continue;
            }
            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== $this->canonicalModuleSchema) {
                continue;
            }
            $status = (string) $doc['status'];
            if (! in_array($status, ['future', 'planned'], true)) {
                continue;
            }
            if (array_key_exists('implementation_state', $frontmatter)
                && trim((string) $frontmatter['implementation_state']) !== '') {
                continue;
            }
            if (array_key_exists('blocker', $frontmatter)
                && trim((string) $frontmatter['blocker']) !== '') {
                continue;
            }
            $summary = strtolower((string) ($frontmatter['summary'] ?? ''));
            $body = strtolower((string) ($doc['body'] ?? ''));
            $haystack = $summary."\n".$body;
            if ($this->mentionsNonRuntimeMarker($haystack)) {
                continue;
            }
            $warnings[] = [
                'rule' => 'future_planned_not_runtime_unclear',
                'path' => $path,
                'message' => "{$path}: status [{$status}] doc has no implementation_state/blocker and summary/body does not declare it is not current runtime",
            ];
        }

        return $warnings;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    public function deprecatedSuccessorWarnings(array $docs): array
    {
        $warnings = [];
        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            if (($this->shouldSkipPath)($path)) {
                continue;
            }
            if ((string) $doc['status'] !== 'deprecated') {
                continue;
            }
            $frontmatter = (array) $doc['frontmatter'];
            $supersededBy = $frontmatter['superseded_by'] ?? null;
            if ($supersededBy !== null && $supersededBy !== '' && $supersededBy !== []) {
                continue;
            }
            $summary = strtolower((string) ($frontmatter['summary'] ?? ''));
            if (preg_match('/(supersed|substitu|replaced by|sucesso|sucessora|sucessor)/i', $summary) === 1) {
                continue;
            }
            $warnings[] = [
                'rule' => 'deprecated_without_successor',
                'path' => $path,
                'message' => "{$path}: deprecated doc has no superseded_by field and summary does not name a sucessor",
            ];
        }

        return $warnings;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    public function schemaCitationWarnings(array $docs): array
    {
        $warnings = [];
        $schemaPattern = '/\\batlas\\.[a-z0-9_]+(?:\\.[a-z0-9_]+)*\\.v\\d+\\b/i';
        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            if (($this->shouldSkipPath)($path)) {
                continue;
            }
            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== $this->canonicalModuleSchema) {
                continue;
            }
            $body = (string) ($doc['body'] ?? '');
            if (preg_match($schemaPattern, $body) !== 1) {
                continue;
            }
            $status = (string) $doc['status'];
            $evidence = (array) ($frontmatter['evidence'] ?? []);
            $repoPaths = (array) ($frontmatter['repo_paths'] ?? []);
            $implementationState = trim((string) ($frontmatter['implementation_state'] ?? ''));
            $blocker = trim((string) ($frontmatter['blocker'] ?? ''));

            if (in_array($status, ['active', 'building'], true)) {
                if ($evidence === [] || $repoPaths === []) {
                    $warnings[] = [
                        'rule' => 'schema_cited_without_evidence',
                        'path' => $path,
                        'message' => "{$path}: body cites canonical schema (atlas.*.vN) and status [{$status}] but evidence or repo_paths is empty",
                    ];
                }

                continue;
            }
            if (in_array($status, ['planned', 'future'], true)) {
                continue;
            }
            if ($implementationState !== '' || $blocker !== '') {
                continue;
            }
            $warnings[] = [
                'rule' => 'schema_cited_without_runtime_declaration',
                'path' => $path,
                'message' => "{$path}: body cites canonical schema (atlas.*.vN) but status [{$status}] is outside [active|building|planned|future] and has no implementation_state/blocker field",
            ];
        }

        return $warnings;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,string>>
     */
    public function ambiguousNamingWarnings(array $docs): array
    {
        $warnings = [];
        foreach ($docs as $doc) {
            $path = (string) $doc['path'];
            if (($this->shouldSkipPath)($path)) {
                continue;
            }
            if ($path === $this->canonicalGlossaryPath) {
                continue;
            }
            $frontmatter = (array) $doc['frontmatter'];
            if (($frontmatter['doc_schema'] ?? null) !== $this->canonicalModuleSchema) {
                continue;
            }
            $title = (string) ($frontmatter['title'] ?? '');
            $body = (string) ($doc['body'] ?? '');
            $matches = $this->ambiguousTermsFound($title.' '.$body);
            if ($matches === []) {
                continue;
            }
            if (($this->referencesGlossary)($frontmatter, $body)) {
                continue;
            }
            $count = count($matches);
            $rule = $count >= 2 || $this->ambiguousTermsFound($title) !== []
                ? 'ambiguous_naming_in_title'
                : 'missing_glossary_reference';
            $sample = implode(', ', array_slice($matches, 0, 3));
            $warnings[] = [
                'rule' => $rule,
                'path' => $path,
                'message' => "{$path}: mentions ambiguous cluster terms [{$sample}] without referencing ".($this->relativeGlossaryPath)(),
            ];
        }

        return $warnings;
    }

    public function mentionsNonRuntimeMarker(string $haystack): bool
    {
        $markers = [
            'nao construido',
            'não construido',
            'nao construída',
            'não construída',
            'not implemented',
            'not yet implemented',
            'visao futura',
            'visão futura',
            'tese estrategica',
            'tese estratégica',
            'future state',
            'future vision',
            'planned but not',
            'not current runtime',
            'no codigo equivalente',
            'no código equivalente',
        ];
        foreach ($markers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    public function ambiguousTermsFound(string $haystack): array
    {
        $found = [];
        foreach ($this->ambiguousNamingTerms as $term) {
            if (stripos($haystack, $term) !== false) {
                $found[] = $term;
            }
        }

        return $found;
    }
}
