<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

/**
 * Project-specific stewardship gate. Combines three independent FACT sources — admitted lane manifest,
 * context-freshness verdict, and candidate task-evidence facts — into ONE deterministic verification
 * policy verdict.
 *
 * INVARIANTS:
 *   - allowed=true ONLY when admission.admitted===true AND freshness.conformant===true AND every
 *     declared verification_command has a matching task_evidence.evidence row reporting passed===true.
 *   - allowed=false ⇒ blockers[] enumerates the EXACT missing/failing facts (no scalar score).
 *   - DETERMINISTIC: identical (admission, freshness, evidence) ⇒ byte-identical envelope.
 */
final class AtlasProjectLaneVerificationPolicy
{
    public const SCHEMA = 'atlas.multiproject.lane_verification_policy.v1';

    /**
     * @param  array<string,mixed>  $admission      output of AtlasProjectLaneAdmissionPolicy::admit()
     * @param  array<string,mixed>  $freshness      output of AtlasProjectLaneContextFreshnessGate::evaluate()
     * @param  array{evidence?:array<string,array{passed?:bool, gate?:string}>}  $taskEvidence  candidate task FACT bundle
     * @return array{schema:string, allowed:bool, project_id:string, blockers:list<string>, sources:array{admission_admitted:bool, freshness_conformant:bool, missing_evidence_for:list<string>, failing_evidence_for:list<string>}}
     */
    public function decide(array $admission, array $freshness, array $taskEvidence): array
    {
        $blockers = [];
        $admitted = (bool) ($admission['admitted'] ?? false);
        if (! $admitted) {
            foreach ((array) ($admission['blocking_reasons'] ?? []) as $r) {
                $blockers[] = 'admission:'.(string) $r;
            }
            if ($admission === [] || ! isset($admission['admitted'])) {
                $blockers[] = 'admission:envelope_missing';
            }
        }

        $freshConformant = (bool) ($freshness['conformant'] ?? false);
        if (! $freshConformant) {
            foreach ((array) ($freshness['blockers'] ?? []) as $b) {
                $blockers[] = 'freshness:'.(string) $b;
            }
            if ($freshness === [] || ! isset($freshness['conformant'])) {
                $blockers[] = 'freshness:envelope_missing';
            }
        }

        $verificationCmds = is_array($admission['verification_commands'] ?? null)
            ? array_values(array_map('strval', $admission['verification_commands']))
            : [];

        $evidenceByGate = is_array($taskEvidence['evidence'] ?? null) ? $taskEvidence['evidence'] : [];
        $missing = [];
        $failing = [];
        foreach ($verificationCmds as $cmd) {
            $row = is_array($evidenceByGate[$cmd] ?? null) ? $evidenceByGate[$cmd] : null;
            if ($row === null) {
                $missing[] = $cmd;
                $blockers[] = 'evidence_missing_for:'.$cmd;

                continue;
            }
            if (($row['passed'] ?? null) !== true) {
                $failing[] = $cmd;
                $blockers[] = 'evidence_failing_for:'.$cmd;
            }
        }

        // Sort blockers for determinism.
        sort($blockers, SORT_STRING);
        sort($missing, SORT_STRING);
        sort($failing, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'allowed' => $blockers === [],
            'project_id' => (string) ($admission['project_id'] ?? ''),
            'blockers' => $blockers,
            'sources' => [
                'admission_admitted' => $admitted,
                'freshness_conformant' => $freshConformant,
                'missing_evidence_for' => $missing,
                'failing_evidence_for' => $failing,
            ],
        ];
    }
}
