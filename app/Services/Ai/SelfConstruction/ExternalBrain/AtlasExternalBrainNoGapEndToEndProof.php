<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * The final circuit breaker against partial autonomy: a claimed "gap closed" is never trusted on
 * an individual stage report alone. This proof demands ONE dossier showing the full task
 * lifecycle traversed end to end — discovery, task_spec, serving, muscle_outcome,
 * learning_update, next_decision — each with real evidence and a stable dossier hash.
 *
 * Input shape:
 *   { dossier: {
 *       discovery?:       array{evidence?:string, hash?:string},
 *       task_spec?:       array{evidence?:string, hash?:string},
 *       serving?:         array{evidence?:string, hash?:string},
 *       muscle_outcome?:  array{evidence?:string, hash?:string},
 *       learning_update?: array{evidence?:string, hash?:string},
 *       next_decision?:   array{evidence?:string, hash?:string},
 *   } }
 *
 * A stage only counts as traversed when it carries non-empty `evidence` AND a `hash` matching
 * sha256(evidence) — proving the evidence is exactly what the stage claims, not a description
 * that drifted from what actually happened. Any stage missing outright, missing evidence, or
 * carrying an unstable hash makes complete=false and is named in missing_stage so a follow-up
 * task can be created automatically instead of the claim being taken on faith.
 *
 * Pure: no I/O, no provider calls, deterministic — callers supply the dossier facts.
 */
final class AtlasExternalBrainNoGapEndToEndProof
{
    public const SCHEMA = 'atlas.self_construction.external_brain.no_gap_end_to_end_proof.v1';

    /** @var list<string> */
    private const REQUIRED_STAGES = [
        'discovery',
        'task_spec',
        'serving',
        'muscle_outcome',
        'learning_update',
        'next_decision',
    ];

    /**
     * @param  array{dossier?: array<string, array<string,mixed>>}  $facts
     * @return array<string,mixed>
     */
    public function prove(array $facts): array
    {
        $dossier = is_array($facts['dossier'] ?? null) ? $facts['dossier'] : [];

        $stageStates = [];
        $missingStage = [];

        foreach (self::REQUIRED_STAGES as $stage) {
            $entry = is_array($dossier[$stage] ?? null) ? $dossier[$stage] : null;

            if ($entry === null) {
                $stageStates[$stage] = ['evidence_present' => false, 'hash_stable' => false, 'traversed' => false];
                $missingStage[] = "{$stage}_missing";

                continue;
            }

            $evidence = trim((string) ($entry['evidence'] ?? ''));
            if ($evidence === '') {
                $stageStates[$stage] = ['evidence_present' => false, 'hash_stable' => false, 'traversed' => false];
                $missingStage[] = "{$stage}_missing_evidence";

                continue;
            }

            $expectedHash = hash('sha256', $evidence);
            $suppliedHash = (string) ($entry['hash'] ?? '');
            $hashStable = $suppliedHash !== '' && hash_equals($expectedHash, $suppliedHash);

            $stageStates[$stage] = [
                'evidence_present' => true,
                'hash_stable' => $hashStable,
                'traversed' => $hashStable,
                'dossier_hash' => $expectedHash,
            ];

            if (! $hashStable) {
                $missingStage[] = "{$stage}_hash_unstable";
            }
        }

        return array_merge(
            [
                'schema' => self::SCHEMA,
                'complete' => $missingStage === [],
                'missing_stage' => $missingStage,
            ],
            $stageStates,
        );
    }
}
