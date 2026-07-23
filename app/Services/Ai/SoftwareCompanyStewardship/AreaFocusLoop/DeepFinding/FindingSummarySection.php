<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;

/**
 * Finding post-processing section — extracted verbatim from
 * AreaFocusDeepFindingEngineService by the GOD-DEBULK split. Pure array
 * transforms over already-enriched findings: dedupe by hash, deterministic
 * sort, and the kind/owner/severity/focus summaries + next-action routing.
 * No finding construction happens here; taxonomy constants live on the façade.
 */
class FindingSummarySection
{
    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    public function dedupe(array $findings): array
    {
        $seen = [];
        $unique = [];
        foreach ($findings as $finding) {
            $key = (string) ($finding['finding_hash'] ?? '');
            $originType = (string) ($finding['origin_type'] ?? '');
            if ($originType === 'missing_test') {
                $files = AreaFocusStringListNormalizer::stringifiedNonEmptyValues($finding['affected_files'] ?? []);
                $key = 'missing_test:'.($files[0] ?? $key);
            } elseif (in_array($originType, ['provider_routing_risk', 'execution_bottleneck'], true)) {
                $files = AreaFocusStringListNormalizer::stringifiedNonEmptyValues($finding['affected_files'] ?? []);
                $key = $originType.':'.($files[0] ?? $key);
            }
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $finding;
        }

        return $unique;
    }

    /**
     * Focus-first, then severity, then confidence, then stable by hash.
     *
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    public function sortFindings(array $findings): array
    {
        usort($findings, static function (array $a, array $b): int {
            $aScore = (int) ($a['factory_priority_score'] ?? $a['priority_score'] ?? 0);
            $bScore = (int) ($b['factory_priority_score'] ?? $b['priority_score'] ?? 0);

            return $bScore <=> $aScore
                ?: ((int) ($b['roi_score'] ?? 0) <=> (int) ($a['roi_score'] ?? 0))
                ?: (((bool) ($b['in_focus'] ?? false)) <=> ((bool) ($a['in_focus'] ?? false)))
                ?: ((string) ($a['kind'] ?? '') <=> (string) ($b['kind'] ?? ''))
                ?: ((string) ($a['finding_hash'] ?? '') <=> (string) ($b['finding_hash'] ?? ''));
        });

        return array_values($findings);
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    public function kindSummary(array $findings): array
    {
        $summary = array_fill_keys(AreaFocusDeepFindingEngineService::KINDS, 0);
        foreach ($findings as $finding) {
            $kind = (string) ($finding['kind'] ?? '');
            if (array_key_exists($kind, $summary)) {
                $summary[$kind]++;
            }
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    public function ownerSummary(array $findings): array
    {
        $summary = array_fill_keys(AreaFocusDeepFindingEngineService::OWNER_CANDIDATES, 0);
        foreach ($findings as $finding) {
            $owner = (string) ($finding['owner_candidate'] ?? '');
            if (array_key_exists($owner, $summary)) {
                $summary[$owner]++;
            }
        }

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    public function severitySummary(array $findings): array
    {
        $summary = [];
        foreach ($findings as $finding) {
            $sev = (string) ($finding['severity'] ?? 'unknown');
            $summary[$sev] = ($summary[$sev] ?? 0) + 1;
        }
        ksort($summary);

        return $summary;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,int>
     */
    public function focusSummary(array $findings): array
    {
        $in = 0;
        foreach ($findings as $finding) {
            if (($finding['in_focus'] ?? false) === true) {
                $in++;
            }
        }

        return ['in_focus' => $in, 'out_of_focus' => count($findings) - $in];
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return list<string>
     */
    public function nextActions(array $findings): array
    {
        if ($findings === []) {
            return ['No findings in focus; nothing to route. Re-run on the next cycle.'];
        }

        return [
            'Surface findings in the Morning Inbox for operator decision; nothing auto-executes.',
            'Route accepted findings to Self-Directed Evolution via the per-finding spec_seed (proposal-only).',
            'Route execution-ready findings to Atlas Dev (small/local) or Forge (long-horizon) under governed review.',
        ];
    }
}
