<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S122 — L8 Transcendence / P4 (Local distillation).
 *
 * Mines recurring task classes from already-governed evidence so the operator
 * can later decide whether a class is worth a local distilled engine. Doctrine
 * (atlas-aaeos-l8-transcendence-map P4): "Distillation starts from recurring
 * governed evidence." A task class only becomes a candidate once it recurs at
 * or above the recurrence threshold; classes below it are blocked, never
 * surfaced. Privacy classes that the operator keeps on-device by contract
 * (sensitive / secret / cyber — mirrored byte-for-byte from
 * AreaFocusForgeHandoffBuilderService::sensitive_classes_stay_local) force
 * local_first_required so a distilled engine for them can never leave the Mac.
 *
 * Pure: every returned field is computed from the method input via real rules
 * (deterministic slugging, recurrence counting, worst-case quality floor,
 * most-restrictive privacy resolution, ref de-duplication). No I/O, no DB, no
 * clock, no randomness, no write authority. NO TRAINING is performed here:
 * training_performed is always false — this is read-only candidate mining.
 */
final class L8LocalDistillationTaskClassMiner
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.local_distillation.task_class_candidates.v1';

    /**
     * Minimum number of governed evidence samples a task class needs before it
     * is treated as recurring (a real pattern) rather than incidental noise.
     * Below this the class is blocked, mirroring the sibling miner doctrine
     * that three observations is the floor between signal and noise.
     */
    private const MIN_RECURRENCE = 3;

    /**
     * Privacy classes that stay on-device by operator contract. Mirrored
     * byte-for-byte from
     * AreaFocusForgeHandoffBuilderService::sensitive_classes_stay_local.
     * A candidate whose resolved privacy class is one of these carries
     * local_first_required=true.
     *
     * @var list<string>
     */
    private const LOCAL_FIRST_PRIVACY_CLASSES = ['sensitive', 'secret', 'cyber'];

    /**
     * Privacy classes ordered from least to most restrictive. When a task
     * class is observed under several privacy classes the most restrictive
     * (highest rank) wins, so a single sensitive sample pins the whole class
     * local-first. Unknown labels rank just above the permissive defaults but
     * below the contractual on-device classes.
     *
     * @var array<string, int>
     */
    private const PRIVACY_RANK = [
        'public' => 0,
        'internal' => 1,
        'normal' => 1,
        'confidential' => 2,
        'sensitive' => 3,
        'secret' => 4,
        'cyber' => 5,
    ];

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{
     *     schema_version: string,
     *     training_performed: bool,
     *     candidates: list<array{
     *         task_class_id: string,
     *         recurrence_count: int,
     *         quality_floor: float,
     *         privacy_class: string,
     *         local_first_required: bool,
     *         evidence_refs: list<string>
     *     }>,
     *     blocked_task_classes: list<array{
     *         task_class_id: string,
     *         recurrence_count: int,
     *         block_reason: string
     *     }>,
     *     observed_class_count: int,
     *     recurrence_threshold: int
     * }
     */
    public function mine(array $evidence): array
    {
        $grouped = $this->groupByTaskClass($evidence);

        $candidates = [];
        $blocked = [];

        foreach ($grouped as $taskClassId => $group) {
            // PHP coerces an all-digit array key (e.g. the slug "123") back to an
            // int when it is bound from the array key, which would emit
            // task_class_id as an int and break the list<string>/string contract.
            // Re-cast to string so the slug stays a string for every class.
            $taskClassId = (string) $taskClassId;

            $recurrenceCount = $group['recurrence_count'];

            if ($recurrenceCount < self::MIN_RECURRENCE) {
                $blocked[] = [
                    'task_class_id' => $taskClassId,
                    'recurrence_count' => $recurrenceCount,
                    'block_reason' => 'recurrence_below_threshold',
                ];

                continue;
            }

            $privacyClass = $group['privacy_class'];

            $candidates[] = [
                'task_class_id' => $taskClassId,
                'recurrence_count' => $recurrenceCount,
                'quality_floor' => $group['quality_floor'],
                'privacy_class' => $privacyClass,
                'local_first_required' => in_array($privacyClass, self::LOCAL_FIRST_PRIVACY_CLASSES, true),
                'evidence_refs' => $group['evidence_refs'],
            ];
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => strcmp($a['task_class_id'], $b['task_class_id'])
        );

        usort(
            $blocked,
            static fn (array $a, array $b): int => strcmp($a['task_class_id'], $b['task_class_id'])
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'training_performed' => false,
            'candidates' => array_values($candidates),
            'blocked_task_classes' => array_values($blocked),
            'observed_class_count' => count($grouped),
            'recurrence_threshold' => self::MIN_RECURRENCE,
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, array{
     *     recurrence_count: int,
     *     quality_floor: float,
     *     privacy_class: string,
     *     evidence_refs: list<string>
     * }>
     */
    private function groupByTaskClass(array $evidence): array
    {
        $samples = $this->samples($evidence);

        /** @var array<string, array{recurrence_count: int, quality_floor: float, privacy_class: string, evidence_refs: list<string>}> $grouped */
        $grouped = [];

        foreach ($samples as $sample) {
            if (! is_array($sample)) {
                continue;
            }

            $taskClassId = $this->taskClassId($sample);
            if ($taskClassId === '') {
                continue;
            }

            $quality = $this->quality($sample);
            $privacyClass = $this->privacyClass($sample);

            if (! isset($grouped[$taskClassId])) {
                $grouped[$taskClassId] = [
                    'recurrence_count' => 1,
                    'quality_floor' => $quality,
                    'privacy_class' => $privacyClass,
                    'evidence_refs' => $this->sampleRefs($sample),
                ];

                continue;
            }

            $grouped[$taskClassId]['recurrence_count']++;

            // Worst-case quality across the class is the honest floor.
            if ($quality < $grouped[$taskClassId]['quality_floor']) {
                $grouped[$taskClassId]['quality_floor'] = $quality;
            }

            // Most restrictive privacy class observed wins.
            if ($this->privacyRank($privacyClass) > $this->privacyRank($grouped[$taskClassId]['privacy_class'])) {
                $grouped[$taskClassId]['privacy_class'] = $privacyClass;
            }

            foreach ($this->sampleRefs($sample) as $ref) {
                if (! in_array($ref, $grouped[$taskClassId]['evidence_refs'], true)) {
                    $grouped[$taskClassId]['evidence_refs'][] = $ref;
                }
            }
        }

        // Provenance fallback is class-level, not per-sample: a class with zero
        // real refs across every sample gets the synthetic placeholder, but a
        // class that carries genuine governed refs is never contaminated with it
        // just because one of its samples happened to omit refs. Recurrence is
        // counted from these unique class-level refs so duplicated records of a
        // single governed reference cannot satisfy the recurrence threshold.
        foreach ($grouped as $taskClassId => $group) {
            if ($group['evidence_refs'] === []) {
                $grouped[$taskClassId]['evidence_refs'] = ['task_class:'.$taskClassId];
            }

            $grouped[$taskClassId]['recurrence_count'] = count($grouped[$taskClassId]['evidence_refs']);
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<mixed>
     */
    private function samples(array $evidence): array
    {
        foreach (['samples', 'records', 'runs', 'evidence'] as $key) {
            if (is_array($evidence[$key] ?? null)) {
                return array_values($evidence[$key]);
            }
        }

        if (array_is_list($evidence)) {
            return array_values($evidence);
        }

        return [$evidence];
    }

    /**
     * @param  array<string, mixed>  $sample
     */
    private function taskClassId(array $sample): string
    {
        $raw = $sample['task_class_id']
            ?? $sample['task_class']
            ?? $sample['class']
            ?? $sample['name']
            ?? '';

        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return '';
        }

        return AreaFocusSlugNormalizer::lowerSnakeToken((string) $raw);
    }

    /**
     * Quality clamped to [0.0, 1.0]. A 0..1 score must never exceed its bound.
     *
     * @param  array<string, mixed>  $sample
     */
    private function quality(array $sample): float
    {
        $raw = $sample['quality']
            ?? $sample['quality_score']
            ?? $sample['score']
            ?? null;

        if (is_bool($raw) || (! is_int($raw) && ! is_float($raw) && ! (is_string($raw) && is_numeric($raw)))) {
            return 0.0;
        }

        $quality = (float) $raw;

        // A NaN quality cannot be clamped by min/max: NaN is incomparable, so
        // max(0.0, min(1.0, NaN)) leaks a value straight past the bound — and the
        // exact result depends on the engine's min/max argument-evaluation order
        // (it can surface as NaN, violating the [0,1] contract and breaking
        // === / JSON, or as the optimistic 1.0, failing OPEN against the
        // worst-case honest-floor doctrine). Map NaN to the safe 0.0 floor. (Inf
        // is ordered and clamps deterministically to 1.0 below, so it is left
        // alone.)
        if (is_nan($quality)) {
            return 0.0;
        }

        return max(0.0, min(1.0, $quality));
    }

    /**
     * @param  array<string, mixed>  $sample
     */
    private function privacyClass(array $sample): string
    {
        $raw = $sample['privacy_class']
            ?? $sample['privacy']
            ?? $sample['sensitivity']
            ?? 'normal';

        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return 'normal';
        }

        $slug = AreaFocusSlugNormalizer::lowerSnakeToken((string) $raw);

        return $slug === '' ? 'normal' : $slug;
    }

    private function privacyRank(string $privacyClass): int
    {
        // Known labels use their declared rank; unknown labels rank just above
        // the permissive defaults (2) but below the contractual on-device set.
        return self::PRIVACY_RANK[$privacyClass] ?? 2;
    }

    /**
     * Real evidence refs carried by a single sample, de-duplicated and trimmed.
     * Returns an empty list when the sample carries none; the synthetic
     * provenance placeholder is applied once at the class level (in
     * groupByTaskClass), never per-sample, so genuine refs are never mixed with
     * the placeholder.
     *
     * @param  array<string, mixed>  $sample
     * @return list<string>
     */
    private function sampleRefs(array $sample): array
    {
        $raw = $sample['evidence_refs']
            ?? $sample['source_refs']
            ?? $sample['refs']
            ?? null;

        $refs = [];

        $values = is_array($raw) ? $raw : [$raw];

        foreach ($values as $value) {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $ref = trim((string) $value);
            if ($ref === '' || in_array($ref, $refs, true)) {
                continue;
            }

            $refs[] = $ref;
        }

        return $refs;
    }
}
