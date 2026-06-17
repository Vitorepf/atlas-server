<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Models\AtlasDevFailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use Throwable;

/**
 * M5 — Compounding failure memory: injection seam.
 *
 * REUSES the persisted {@see AtlasDevFailureCapsule} rows (model + persistence
 * live in {@see DevFailureCapsuleRuntimeService::persist()}) and surfaces them
 * forward as "known failure modes of this repo/area" into the prompt projection
 * of a subsequent run whose area overlaps a capsule's changed_files.
 *
 * Area identity = path overlap between a run's target files (allowed_files /
 * changed files) and a capsule's changed_files, AND repository/workspace
 * identity via the capsule's task_packet `workspace_slug` (VAL-M5-007).
 * A foreign-area capsule is NEVER injected (VAL-M5-003) and a
 * foreign-workspace capsule is NEVER injected even when its changed_files
 * paths overlap the current run's target set (VAL-M5-007 anti cross-repo
 * bleed). An area with zero matching capsules yields an empty list and the
 * projection stays byte-identical to the pre-M5 baseline (VAL-M5-004 — the
 * renderer omits the section entirely when this returns []).
 *
 * Output contract:
 *   - list<string>, one entry per UNIQUE failure_hash (deduped — VAL-M5-006);
 *   - deterministically ordered (sorted by failure_hash then by entry text)
 *     so identical capsule sets produce byte-identical injection (VAL-M5-006);
 *   - each entry is a single provider-safe string carrying the actionable
 *     failure_class + suggested_repair + truncated, redacted error_excerpt
 *     (VAL-M5-005);
 *   - secret-shaped tokens in error_excerpt are redacted (VAL-M5-005).
 *
 * Workspace scoping (VAL-M5-007): the capsule query is restricted to rows
 * whose `taskPacket.workspace_slug` matches the current run's workspace_slug
 * BEFORE area overlap + failure_hash dedup. A capsule from a different
 * workspace_slug must NEVER inject even when its changed_files overlap.
 *
 * Null-slug semantics (conservative fallback): when the current run's
 * workspace_slug cannot be derived (null/empty), the injector still injects
 * capsules whose `taskPacket.workspace_slug` is ALSO null/empty
 * (i.e. workspace-unresolvable capsules), but NEVER injects a capsule whose
 * task_packet carries a resolvable (non-empty) foreign workspace_slug. This
 * keeps the honest-empty / area-only tests (which never set a workspace_slug
 * on the persisted packet) working while preventing any confirmed
 * foreign-workspace leak on an unidentifiable run (anti-gaming).
 *
 * The injector never fabricates content: every emitted entry is derived from a
 * persisted capsule row. No row → no entry (anti-gaming, VAL-M5-004).
 *
 * This service is read-only and DB-scoped. It does not call any provider and
 * never persists. It is safe to invoke from the deterministic Atlas Dev
 * planning path (AtlasDevFastPathOrchestrator) before the prompt projection
 * is built.
 */
final class DevFailureCapsulePromptInjector
{
    private const ERROR_EXCERPT_LIMIT = 480;

    /**
     * Capsule row contract (subset consumed from AtlasDevFailureCapsule).
     */
    private const SECRET_PATTERNS = [
        '/\b[A-Z0-9_]*API[_-]?KEY[A-Z0-9_]*\b/i' => 'REDACTED_PROVIDER_TOKEN_NAME',
        '/\bAWS_SECRET_ACCESS_KEY\b/i' => 'REDACTED_PROVIDER_TOKEN_NAME',
        '/authorization:\s*bearer\s+[A-Za-z0-9._\-]+/i' => 'authorization: bearer REDACTED',
        '/bearer\s+ey[A-Za-z0-9._\-]+/i' => 'bearer REDACTED',
        '/sk-ant-[A-Za-z0-9._\-]+/i' => 'sk-ant-REDACTED',
        '/sk-[A-Za-z0-9]{20,}/i' => 'sk-REDACTED',
        '/password\s*=\s*[^\s,;]+/i' => 'password=REDACTED',
        '/secret\s*=\s*[^\s,;]+/i' => 'secret=REDACTED',
        '/private_key/i' => 'REDACTED_PRIVATE_KEY_LABEL',
    ];

    /**
     * @param  list<string>  $targetFiles  the run's target set (allowed_files).
     *                                     Empty list returns [] (no area → no
     *                                     injection — VAL-M5-004 honest empty).
     * @param  string|null  $workspaceSlug  the current run's workspace_slug
     *                                      (matches the AtlasDevTaskPacket
     *                                      workspace_slug column). Used to scope
     *                                      capsules to the current workspace
     *                                      BEFORE area overlap + dedup
     *                                      (VAL-M5-007 anti cross-repo bleed).
     *                                      See the class docblock for the
     *                                      null-slug conservative fallback.
     * @return list<string> provider-safe, area-scoped, deduped entries
     */
    public function injectFor(array $targetFiles, ?string $workspaceSlug = null): array
    {
        $normalizedTargets = $this->normalizePaths($targetFiles);
        if ($normalizedTargets === []) {
            // A run with no target area (read-only / read-only-question) never
            // receives failure-mode injection: area identity is undefined.
            return [];
        }

        $currentSlug = $this->normalizeWorkspaceSlug($workspaceSlug);

        // Fail-open: if the capsules table is absent (e.g. a test workspace
        // that never ran the runtime-intelligence migration, or a DB error),
        // there is no failure memory to inject. Mirrors the
        // OpenBrainProjectionAdapter contract — the engine never blocks on a
        // missing read model. An empty list yields an honest baseline
        // projection (VAL-M5-004 — no fabricated content).
        try {
            $query = AtlasDevFailureCapsule::query()
                ->leftJoin(
                    'atlas_dev_task_packets',
                    'atlas_dev_failure_capsules.task_packet_id',
                    '=',
                    'atlas_dev_task_packets.id',
                );

            // Workspace scoping (VAL-M5-007). A foreign-workspace capsule must
            // NEVER inject, even when its changed_files overlap the current
            // run's target set. The capsule's workspace identity is its
            // task_packet.workspace_slug (task_packet_id -> AtlasDevTaskPacket).
            //
            // Null-slug conservative fallback (see class docblock): when the
            // current run's workspace_slug is unresolvable, only capsules that
            // are ALSO workspace-unresolvable (null/empty task_packet
            // workspace_slug) are eligible. A capsule whose task_packet
            // carries a resolvable foreign workspace_slug is NEVER injected
            // on an unidentifiable run (no foreign-repo leak).
            if ($currentSlug !== null) {
                $query->where(
                    fn ($q) => $q
                        ->where('atlas_dev_task_packets.workspace_slug', $currentSlug)
                        ->orWhereNull('atlas_dev_task_packets.workspace_slug')
                        ->orWhere('atlas_dev_task_packets.workspace_slug', ''),
                );
            } else {
                $query->where(
                    fn ($q) => $q
                        ->whereNull('atlas_dev_task_packets.workspace_slug')
                        ->orWhere('atlas_dev_task_packets.workspace_slug', ''),
                );
            }

            $capsules = $query->get(['atlas_dev_failure_capsules.*']);
        } catch (Throwable) {
            return [];
        }

        if ($capsules->isEmpty()) {
            return [];
        }

        // Bucket entries by failure_hash so duplicates collapse (the
        // persistence layer already dedups by hash via updateOrCreate, but
        // the injector keeps the dedup invariant explicitly so a future
        // caller that bypasses persist() cannot produce double entries).
        $byHash = [];
        foreach ($capsules as $capsule) {
            $hash = (string) ($capsule->failure_hash ?? '');
            if ($hash === '' || array_key_exists($hash, $byHash)) {
                continue;
            }
            if (! $this->overlapsArea($capsule, $normalizedTargets)) {
                continue;
            }

            $byHash[$hash] = $this->buildEntry($capsule);
        }

        $entries = array_values($byHash);

        // Deterministic ordering: sort by the rendered entry text. Two
        // invocations over the same capsule set produce the identical list
        // (VAL-M5-006). String sort is stable for ASCII failure_class tokens.
        usort($entries, static fn (string $a, string $b): int => strcmp($a, $b));

        return AtlasDevStringListNormalizer::uniqueStrings($entries);
    }

    /**
     * Normalize the workspace_slug the same way the runtime does
     * (DevTaskPacketRuntimeService falls back to the workspace string when
     * no explicit slug is supplied). Empty/whitespace slugs collapse to
     * null, which triggers the conservative null-slug fallback (no
     * foreign-workspace injection).
     */
    private function normalizeWorkspaceSlug(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }
        $trimmed = trim($slug);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }
            $trimmed = trim($path);
            if ($trimmed === '') {
                continue;
            }
            $normalized[] = $trimmed;
        }

        return AtlasDevStringListNormalizer::uniqueStrings($normalized);
    }

    /**
     * @param  list<string>  $normalizedTargets
     */
    private function overlapsArea(AtlasDevFailureCapsule $capsule, array $normalizedTargets): bool
    {
        $rawChanged = $capsule->changed_files;
        if (! is_array($rawChanged)) {
            return false;
        }
        $capsulePaths = $this->normalizePaths($rawChanged);
        if ($capsulePaths === []) {
            // A capsule with no changed_files carries no area identity; never
            // inject (no overlap can be proven — anti-gaming).
            return false;
        }

        foreach ($capsulePaths as $capsulePath) {
            foreach ($normalizedTargets as $targetPath) {
                if ($this->pathOverlaps($capsulePath, $targetPath)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Two paths overlap when they are equal or one is a directory prefix of
     * the other (a capsule touching app/Services/Foo/ overlaps a run touching
     * app/Services/Foo/Bar.php). Exact equality is the most common case.
     */
    private function pathOverlaps(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // Normalize trailing slashes for prefix comparison.
        $aNorm = rtrim($a, '/');
        $bNorm = rtrim($b, '/');

        // Directory-prefix overlap: a capsule whose changed_files contains
        // "app/Services/Foo" overlaps a run targeting "app/Services/Foo/Bar.php"
        // and vice-versa.
        return str_starts_with($aNorm.'/', $bNorm.'/')
            || str_starts_with($bNorm.'/', $aNorm.'/');
    }

    private function buildEntry(AtlasDevFailureCapsule $capsule): string
    {
        $failureClass = trim((string) ($capsule->failure_class ?? ''));
        $suggestedRepair = trim((string) ($capsule->suggested_repair ?? ''));
        $errorExcerpt = $this->truncate($this->redact((string) ($capsule->error_excerpt ?? '')));

        $parts = [];
        if ($failureClass !== '') {
            $parts[] = 'failure_class='.$failureClass;
        }
        if ($suggestedRepair !== '') {
            $parts[] = 'suggested_repair='.$suggestedRepair;
        }
        if ($errorExcerpt !== '') {
            $parts[] = 'error_excerpt='.$errorExcerpt;
        }

        return implode(' | ', $parts);
    }

    private function truncate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return strlen($value) <= self::ERROR_EXCERPT_LIMIT
            ? $value
            : substr($value, 0, self::ERROR_EXCERPT_LIMIT - 3).'...';
    }

    /**
     * Apply the same secret-shaped token redaction that the projection seam
     * (ProviderPromptBuilder::providerSafeExcerpt) enforces, so injected
     * error_excerpt can never leak a raw key/bearer/secret (VAL-M5-005).
     */
    private function redact(string $value): string
    {
        $safe = $value;
        foreach (self::SECRET_PATTERNS as $pattern => $replacement) {
            $safe = (string) preg_replace($pattern, $replacement, $safe);
        }

        return $safe;
    }
}
