<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Fail-closed certification: the Autonomous external brain is never "done" on the strength of an
 * individual organ report. This gate demands one evidence dossier proving every closed-loop link
 * — proposal, task_fabric, queue_admission, muscle_outcome, learning_update, resequencing — is
 * actually connected, not merely present.
 *
 * Input shape:
 *   { dossier: {
 *       proposal?:          array{evidence?:string, hash?:string},
 *       task_fabric?:       array{evidence?:string, hash?:string},
 *       queue_admission?:   array{evidence?:string, hash?:string},
 *       muscle_outcome?:    array{evidence?:string, hash?:string},
 *       learning_update?:   array{evidence?:string, hash?:string},
 *       resequencing?:      array{evidence?:string, hash?:string},
 *   } }
 *
 * A link only counts as wired when it carries non-empty `evidence` AND a `hash` that matches
 * sha256(evidence) — the dossier hash proving the evidence is exactly what the link claims, not
 * a description that drifted from what was actually produced. Any link missing outright, missing
 * evidence, or carrying a hash that does not match its own evidence is an integration gap:
 * ready=false and the gap is named in missing_links so the next brain batch can wire it instead
 * of originating another detached organ.
 *
 * Pure: no I/O, no provider calls, deterministic — callers supply the dossier facts.
 */
final class AtlasExternalBrainClosedLoopReadinessGate
{
    public const SCHEMA = 'atlas.self_construction.external_brain.closed_loop_readiness_gate.v1';

    /** @var list<string> */
    private const REQUIRED_LINKS = [
        'proposal',
        'task_fabric',
        'queue_admission',
        'muscle_outcome',
        'learning_update',
        'resequencing',
    ];

    /**
     * @param  array{dossier?: array<string, array<string,mixed>>}  $facts
     * @return array<string,mixed>
     */
    public function check(array $facts): array
    {
        $dossier = is_array($facts['dossier'] ?? null) ? $facts['dossier'] : [];

        $linkStates = [];
        $missingLinks = [];

        foreach (self::REQUIRED_LINKS as $link) {
            $entry = is_array($dossier[$link] ?? null) ? $dossier[$link] : null;

            if ($entry === null) {
                $linkStates[$link] = ['evidence_present' => false, 'hash_stable' => false, 'wired' => false];
                $missingLinks[] = "{$link}_missing";

                continue;
            }

            $evidence = trim((string) ($entry['evidence'] ?? ''));
            if ($evidence === '') {
                $linkStates[$link] = ['evidence_present' => false, 'hash_stable' => false, 'wired' => false];
                $missingLinks[] = "{$link}_missing_evidence";

                continue;
            }

            $expectedHash = hash('sha256', $evidence);
            $suppliedHash = (string) ($entry['hash'] ?? '');
            $hashStable = $suppliedHash !== '' && hash_equals($expectedHash, $suppliedHash);

            $linkStates[$link] = [
                'evidence_present' => true,
                'hash_stable' => $hashStable,
                'wired' => $hashStable,
                'dossier_hash' => $expectedHash,
            ];

            if (! $hashStable) {
                $missingLinks[] = "{$link}_hash_unstable";
            }
        }

        return array_merge(
            [
                'schema' => self::SCHEMA,
                'ready' => $missingLinks === [],
                'missing_links' => $missingLinks,
            ],
            $linkStates,
        );
    }
}
