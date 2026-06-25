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

        $artifacts = [];

        $hasCanonicalDocs = $this->anyMatches($changed, static fn (string $p): bool => str_starts_with($p, 'docs/') || in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), ['md', 'rst'], true));
        if ($hasCanonicalDocs) {
            $artifacts[] = $this->artifact(
                'docs-health-check',
                'atlas engineering documentation health',
                'canonical docs changed — health check rerun required',
            );
            $artifacts[] = $this->artifact(
                'engineering-knowledge-sync',
                'atlas engineering knowledge sync --prune',
                'docs changed — Atlas KB must re-sync',
            );
        }

        $hasImpl = $this->anyMatches($changed, static fn (string $p): bool => str_starts_with($p, 'app/') && str_ends_with($p, '.php'));
        if ($hasImpl) {
            $artifacts[] = $this->artifact(
                'code-intelligence-index',
                'atlas engineering knowledge index-code --prune',
                'impl code changed — code-intelligence index must rerun',
            );
        }

        if ($lane !== null && (string) ($lane['project_id'] ?? '') !== '') {
            $artifacts[] = $this->artifact(
                'project-lane-context-freshness:'.$lane['project_id'],
                'atlas:task:project-lanes health --manifest=<lane>',
                'project_lane attached — freshness gate must rerun',
            );
        }

        if (! empty($candidate['requires_release_notes'])) {
            $artifacts[] = $this->artifact(
                'release-notes-update',
                'edit CHANGELOG.md / release notes',
                'release_candidate.requires_release_notes=true',
            );
        }

        // Deterministic ordering.
        usort($artifacts, static fn (array $a, array $b): int => strcmp($a['artifact_id'], $b['artifact_id']));

        return [
            'schema' => self::SCHEMA,
            'required_artifacts' => $artifacts,
            'summary' => [
                'changed_files' => count($changed),
                'touched_organs' => count($organs),
                'artifact_count' => count($artifacts),
            ],
        ];
    }

    /**
     * @return array{artifact_id:string, command_hint:string, reason:string, required:bool}
     */
    private function artifact(string $id, string $cmdHint, string $reason): array
    {
        return ['artifact_id' => $id, 'command_hint' => $cmdHint, 'reason' => $reason, 'required' => true];
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
