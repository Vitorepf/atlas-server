<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\KnowledgeSync;

/**
 * Pure map from (changed_files, touched_organs, project_lane, release_candidate facts) to the list of
 * REQUIRED knowledge-sync artifacts that must accompany the candidate before the broader system can
 * treat its knowledge surfaces as still-coherent.
 *
 * INPUT FACTS:
 *   { changed_files:list<string>, touched_organs:list<string>,
 *     project_lane?:{project_id:string}, release_candidate?:{requires_release_notes?:bool} }
 *
 * OUTPUT:
 *   { schema, required_artifacts:list<{artifact_id, command_hint, reason, required}> }
 *
 * ARTIFACT FAMILIES:
 *   docs-health-check            — when canonical docs (docs/, *.md, *.rst) changed
 *   engineering-knowledge-sync   — when docs/ changed (the Atlas knowledge sync command must rerun)
 *   code-intelligence-index      — when impl PHP under app/ changed
 *   project-lane-context-freshness — when a project_lane is attached (the lane freshness gate must be rerun)
 *   release-notes-update         — when release_candidate.requires_release_notes is true
 *
 * INVARIANTS:
 *   - DETERMINISTIC: artifacts sorted byte-stably by artifact_id.
 *   - PURE: no I/O, no shell, no git, no provider call.
 */
final class AtlasKnowledgeSyncRequiredArtifactMap
{
    public const SCHEMA = 'atlas.knowledgesync.required_artifact_map.v1';

    public const CATEGORY_ATLAS_NATIVE = 'atlas_native';

    public const CATEGORY_OPERATOR_VISIBILITY = 'operator_visibility';

    /** Baseline atlas_native artifact IDs required for completion and promotion events. */
    private const FINALITY_BASELINE = ['code_index', 'docs', 'memory', 'receipt_chain', 'tests_or_gates'];

    /**
     * @param  array{
     *     changed_files?:list<string>,
     *     touched_organs?:list<string>,
     *     project_lane?:array{project_id?:string},
     *     release_candidate?:array{requires_release_notes?:bool}
     * }  $facts
     * @return array{schema:string, required_artifacts:list<array{artifact_id:string, command_hint:string, reason:string, required:bool}>}
     */
    public function derive(array $facts): array
    {
        $changed = is_array($facts['changed_files'] ?? null) ? array_values(array_map('strval', $facts['changed_files'])) : [];
        $organs = is_array($facts['touched_organs'] ?? null) ? array_values(array_map('strval', $facts['touched_organs'])) : [];
        $lane = is_array($facts['project_lane'] ?? null) ? $facts['project_lane'] : null;
        $candidate = is_array($facts['release_candidate'] ?? null) ? $facts['release_candidate'] : [];
        $eventType = (string) ($facts['event_type'] ?? '');

        $artifacts = [];

        // Completion and promotion events always require the full finality baseline.
        if (in_array($eventType, ['completion', 'promotion'], true)) {
            $hintMap = [
                'code_index'    => 'atlas engineering knowledge index-code --prune',
                'docs'          => 'atlas engineering documentation health',
                'memory'        => 'atlas memory:sync',
                'receipt_chain' => 'atlas:task:receipts verify',
                'tests_or_gates' => 'php artisan test',
            ];
            foreach (self::FINALITY_BASELINE as $id) {
                $artifacts[] = $this->artifact($id, $hintMap[$id] ?? $id, 'required for '.$eventType.' finality', self::CATEGORY_ATLAS_NATIVE);
            }
        }

        $hasCanonicalDocs = $this->anyMatches($changed, static fn (string $p): bool => str_starts_with($p, 'docs/') || in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), ['md', 'rst'], true));
        if ($hasCanonicalDocs) {
            $artifacts[] = $this->artifact('docs-health-check', 'atlas engineering documentation health', 'canonical docs changed — health check rerun required', self::CATEGORY_ATLAS_NATIVE);
            $artifacts[] = $this->artifact('engineering-knowledge-sync', 'atlas engineering knowledge sync --prune', 'docs changed — Atlas KB must re-sync', self::CATEGORY_ATLAS_NATIVE);
        }

        $hasImpl = $this->anyMatches($changed, static fn (string $p): bool => str_starts_with($p, 'app/') && str_ends_with($p, '.php'));
        if ($hasImpl) {
            $artifacts[] = $this->artifact('code-intelligence-index', 'atlas engineering knowledge index-code --prune', 'impl code changed — code-intelligence index must rerun', self::CATEGORY_ATLAS_NATIVE);
        }

        if ($lane !== null && (string) ($lane['project_id'] ?? '') !== '') {
            $artifacts[] = $this->artifact('project-lane-context-freshness:'.$lane['project_id'], 'atlas:task:project-lanes health --manifest=<lane>', 'project_lane attached — freshness gate must rerun', self::CATEGORY_ATLAS_NATIVE);
        }

        if (! empty($candidate['requires_release_notes'])) {
            $artifacts[] = $this->artifact('release-notes-update', 'edit CHANGELOG.md / release notes', 'release_candidate.requires_release_notes=true', self::CATEGORY_OPERATOR_VISIBILITY);
        }

        // Deterministic ordering — deduplicate by artifact_id (completion baseline may overlap file-driven).
        $seen = [];
        $deduped = [];
        foreach ($artifacts as $a) {
            if (! isset($seen[$a['artifact_id']])) {
                $seen[$a['artifact_id']] = true;
                $deduped[] = $a;
            }
        }
        usort($deduped, static fn (array $a, array $b): int => strcmp($a['artifact_id'], $b['artifact_id']));

        $mapHash = hash('sha256', (string) json_encode($deduped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'schema' => self::SCHEMA,
            'required_artifacts' => $deduped,
            'map_hash' => $mapHash,
            'summary' => [
                'changed_files' => count($changed),
                'touched_organs' => count($organs),
                'artifact_count' => count($deduped),
            ],
        ];
    }

    /**
     * Check freshness: returns blocked=true with missing_artifacts if any atlas_native required artifact
     * is absent from $knownFreshIds. Operator-visibility artifacts are intentionally excluded from
     * the finality gate — they can never substitute for atlas_native ones.
     *
     * @param  list<array<string,mixed>>  $requiredArtifacts  from derive()['required_artifacts']
     * @param  list<string>  $knownFreshIds  artifact_ids confirmed fresh by the caller
     * @return array{blocked:bool, missing_artifacts:list<string>}
     */
    public function checkFreshness(array $requiredArtifacts, array $knownFreshIds): array
    {
        $missing = [];
        foreach ($requiredArtifacts as $artifact) {
            $id = (string) ($artifact['artifact_id'] ?? '');
            $category = (string) ($artifact['category'] ?? self::CATEGORY_ATLAS_NATIVE);
            $required = (bool) ($artifact['required'] ?? true);
            if ($required && $category === self::CATEGORY_ATLAS_NATIVE && ! in_array($id, $knownFreshIds, true)) {
                $missing[] = $id;
            }
        }

        return ['blocked' => $missing !== [], 'missing_artifacts' => array_values($missing)];
    }

    /**
     * @return array{artifact_id:string, command_hint:string, reason:string, required:bool, category:string}
     */
    private function artifact(string $id, string $cmdHint, string $reason, string $category = self::CATEGORY_ATLAS_NATIVE): array
    {
        return ['artifact_id' => $id, 'command_hint' => $cmdHint, 'reason' => $reason, 'required' => true, 'category' => $category];
    }

    /**
     * @param  list<string>  $list
     * @param  callable(string):bool  $predicate
     */
    private function anyMatches(array $list, callable $predicate): bool
    {
        foreach ($list as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }
}
