<?php

namespace App\Services\Ai\Rivals\Core;

use InvalidArgumentException;

/**
 * Materialize a hermetic, solver-visible candidate workspace from a frozen unit.
 *
 * Hidden proof (hidden tests + golden solution) is NEVER placed in the solver's
 * file set. Contamination canaries are injected deterministically so any leak of
 * hidden data — or egress to a non-allowlisted host — is detectable and byte-stable
 * on replay. Pure over arrays: no git, no network, no disk. The canary tokens live
 * on the judge-side manifest, never handed to the solver; the solver only receives
 * the canary files on disk, whose contents surface if the workspace is exfiltrated.
 */
final class CandidateWorkspaceMaterializer
{
    public const SCHEMA = 'atlas.rivals2.candidate_workspace.v1';

    /**
     * @param  array<string,mixed>  $unit  one FrozenUnitManifest units[] entry
     * @param  array<string,mixed>  $options  visible_files, hidden_tests, egress_allowlist, canary_count
     * @return array<string,mixed>
     */
    public function materialize(array $unit, array $options = []): array
    {
        $caseId = (string) ($unit['case_id'] ?? '');
        if ($caseId === '') {
            throw new InvalidArgumentException('candidate_workspace_case_missing');
        }
        $unitHash = (string) ($unit['unit_hash'] ?? '');
        if ($unitHash === '') {
            throw new InvalidArgumentException('candidate_workspace_unit_hash_missing');
        }

        $hidden = array_values(array_unique(array_map('strval', array_merge(
            (array) ($unit['hidden_tests'] ?? []),
            (array) ($options['hidden_tests'] ?? []),
        ))));

        $visible = array_values(array_unique(array_map('strval', (array) ($options['visible_files'] ?? []))));
        // fail-closed: hidden proof must never appear in the solver-visible set
        $leaked = array_values(array_intersect($visible, $hidden));
        if ($leaked !== []) {
            throw new InvalidArgumentException('candidate_workspace_hidden_leak:'.implode(',', $leaked));
        }
        sort($visible);

        $canaryCount = max(1, (int) ($options['canary_count'] ?? config('atlas_rivals.egress.canary_count', 3)));
        $canaries = [];
        for ($i = 0; $i < $canaryCount; $i++) {
            $canaries[] = [
                'path' => '.rivals/canary_'.$i.'.txt',
                'token' => hash('sha256', 'rivals-canary|'.$unitHash.'|'.$i),
            ];
        }

        $allowlist = array_values(array_unique(array_map(
            'strval',
            (array) ($options['egress_allowlist'] ?? config('atlas_rivals.egress.allowlist', [])),
        )));
        sort($allowlist);

        $manifest = [
            'schema_version' => self::SCHEMA,
            'case_id' => $caseId,
            'unit_hash' => $unitHash,
            'visible_files' => $visible,
            // hidden proof is referenced by hash/count only — never by content or path list
            'hidden_excluded' => [
                'count' => count($hidden),
                'hidden_tests_hash' => hash('sha256', json_encode($hidden, JSON_UNESCAPED_SLASHES)),
                'golden_sha' => (string) ($unit['golden_sha'] ?? ''),
            ],
            'canaries' => $canaries,
            'egress' => [
                'mode' => 'mediated_default_deny',
                'allowlist' => $allowlist,
            ],
        ];
        $manifest['workspace_hash'] = hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES));

        return $manifest;
    }

    /**
     * Detect leaks in a solver's produced output/patch: any canary token that
     * surfaces means the sandbox boundary was crossed (hidden data or the whole
     * workspace was exfiltrated).
     *
     * @param  array<string,mixed>  $manifest  output of materialize()
     * @return list<string> violations (empty = clean)
     */
    public function detectLeak(array $manifest, string $solverOutput): array
    {
        $violations = [];
        foreach ((array) ($manifest['canaries'] ?? []) as $canary) {
            $token = (string) ($canary['token'] ?? '');
            if ($token !== '' && str_contains($solverOutput, $token)) {
                $violations[] = 'contamination_canary_hit:'.($canary['path'] ?? $token);
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * Audit egress attempts against the mediated allowlist. Any attempt to a host
     * outside the frozen allowlist is a denied, recorded violation (default-deny).
     *
     * @param  array<string,mixed>  $manifest
     * @param  list<string>  $attemptedHosts
     * @return list<string> denied hosts
     */
    public function auditEgress(array $manifest, array $attemptedHosts): array
    {
        $allow = (array) ($manifest['egress']['allowlist'] ?? []);
        $denied = [];
        foreach ($attemptedHosts as $host) {
            $host = (string) $host;
            if ($host !== '' && ! in_array($host, $allow, true)) {
                $denied[] = 'egress_denied:'.$host;
            }
        }

        return array_values(array_unique($denied));
    }
}
