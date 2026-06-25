<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\KnowledgeSync;

/**
 * Pure gate. Blocks Self-Construction completion when REQUIRED documentation evidence is stale, missing
 * or contradictory. Composes:
 *   - required_artifacts (from {@see AtlasKnowledgeSyncRequiredArtifactMap})
 *   - observed docs_health   {ok:bool, observed_at_unix:int, debt_facts?:list<string>}
 *   - observed sync_result   {ok:bool, observed_at_unix:int}
 *   - changed_docs (raw doc paths that changed)
 *
 * INVARIANTS:
 *   - conformant=false with NAMED blockers when artifact required & evidence missing / stale / failing.
 *   - PASSES when no doc artifact is required (no canonical docs changed) — bypass.
 *   - debt_facts surfaced verbatim — NEVER aggregated into a scalar score.
 *   - DETERMINISTIC envelope (blockers sorted).
 */
final class AtlasKnowledgeSyncDocsDriftGate
{
    public const SCHEMA = 'atlas.knowledgesync.docs_drift_gate.v1';

    public const STALE_WINDOW_SECONDS_DEFAULT = 86400;

    /**
     * @param  array{
     *     required_artifacts:list<array{artifact_id?:string}>,
     *     docs_health?:array{ok?:bool, observed_at_unix?:int, debt_facts?:list<string>},
     *     sync_result?:array{ok?:bool, observed_at_unix?:int},
     *     changed_docs?:list<string>,
     *     now_unix?:int,
     *     stale_window_seconds?:int
     * }  $facts
     * @return array{schema:string, conformant:bool, blockers:list<string>, debt_facts:list<string>}
     */
    public function evaluate(array $facts): array
    {
        $now = (int) ($facts['now_unix'] ?? 0);
        $window = (int) ($facts['stale_window_seconds'] ?? self::STALE_WINDOW_SECONDS_DEFAULT);
        $artifactIds = array_values(array_map(static fn (array $a): string => (string) ($a['artifact_id'] ?? ''), (array) ($facts['required_artifacts'] ?? [])));
        $needsDocsHealth = in_array('docs-health-check', $artifactIds, true);
        $needsSync = in_array('engineering-knowledge-sync', $artifactIds, true);

        $blockers = [];
        $debt = [];

        if ($needsDocsHealth) {
            $dh = is_array($facts['docs_health'] ?? null) ? $facts['docs_health'] : null;
            if ($dh === null) {
                $blockers[] = 'docs_health_missing';
            } else {
                if (! ($dh['ok'] ?? false)) {
                    $blockers[] = 'docs_health_not_ok';
                }
                if (! isset($dh['observed_at_unix'])) {
                    $blockers[] = 'docs_health_no_observed_at';
                } elseif ($now > 0 && ($now - (int) $dh['observed_at_unix']) > $window) {
                    $blockers[] = 'docs_health_stale';
                }
                foreach ((array) ($dh['debt_facts'] ?? []) as $d) {
                    $debt[] = 'docs_health:'.(string) $d;
                }
            }
        }

        if ($needsSync) {
            $sr = is_array($facts['sync_result'] ?? null) ? $facts['sync_result'] : null;
            if ($sr === null) {
                $blockers[] = 'sync_result_missing';
            } else {
                if (! ($sr['ok'] ?? false)) {
                    $blockers[] = 'sync_result_not_ok';
                }
                if (! isset($sr['observed_at_unix'])) {
                    $blockers[] = 'sync_result_no_observed_at';
                } elseif ($now > 0 && ($now - (int) $sr['observed_at_unix']) > $window) {
                    $blockers[] = 'sync_result_stale';
                }
            }
        }

        sort($blockers, SORT_STRING);
        sort($debt, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'conformant' => $blockers === [],
            'blockers' => $blockers,
            'debt_facts' => $debt,
        ];
    }
}
