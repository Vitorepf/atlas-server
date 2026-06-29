<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * CYCLE CAPSULE — the replayable record of ONE external evolution cycle (brain → task → muscle → validation →
 * evidence). It captures everything needed to REPLAY or LEARN from the cycle: the prompt/receipt, the task
 * spec, the brain's decision, the provider that executed, the files touched, the evidence, the validation
 * verdict, the metrics, the failures, and the learning. This is the substrate the Internalization Pipeline
 * replays to turn external cycles into internal capability candidates.
 *
 * Pure builder: capture() normalizes a raw cycle into the canonical, validated capsule shape and is
 * deterministic. Fail-closed: a cycle without a task_packet_id is unattributable and yields null (an
 * un-replayable capsule is worse than none). The {@see AtlasBrainCycleCapsuleLedger} does the append-only IO.
 */
final class AtlasBrainCycleCapsule
{
    public const SCHEMA = 'atlas.brain.cycle_capsule.v1';

    /**
     * Normalize a raw cycle into the canonical replayable capsule, or null when unattributable.
     *
     * @param  array<string,mixed>  $cycle
     * @return array{schema:string, task_packet_id:string, scope:string, objective:string, prompt_receipt:string, spec:array<string,mixed>, decision:string, provider:string, files_touched:list<string>, evidence:array<string,mixed>, validation:array{certified:bool,reasons:list<string>}, metrics:array<string,mixed>, failures:list<string>, learning:string, certified:bool}|null
     */
    public static function capture(array $cycle): ?array
    {
        $taskPacketId = trim((string) ($cycle['task_packet_id'] ?? ''));
        if ($taskPacketId === '') {
            return null; // unattributable → not a replayable capsule.
        }

        $validation = (array) ($cycle['validation'] ?? []);
        $certified = (bool) ($validation['certified'] ?? ($cycle['certified'] ?? false));
        $reasons = self::stringList($validation['reasons'] ?? []);
        $filesTouched = self::stringList($cycle['files_touched'] ?? []);
        $evidence = (array) ($cycle['evidence'] ?? []);

        // CERTIFIED-EVIDENCE GUARD: a capsule certified=true that changed files but carries NO
        // tests_or_gates_result evidence is not honestly certified — the Internalization Pipeline would mint a
        // `wiring` candidate from an UNPROVEN change (a fabricated capability). Normalize it uncertified here so
        // no wiring candidate is derived from unverified work; a capsule whose proof evidence IS present is
        // unchanged.
        if ($certified && $filesTouched !== [] && ! self::hasTestsOrGatesEvidence($evidence)) {
            $certified = false;
            $reasons[] = 'uncertified_missing_tests_or_gates_evidence';
        }
        $reasons = array_values(array_unique($reasons));

        return [
            'schema' => self::SCHEMA,
            'task_packet_id' => $taskPacketId,
            'scope' => trim((string) ($cycle['scope'] ?? '')),
            'objective' => trim((string) ($cycle['objective'] ?? '')),
            'prompt_receipt' => trim((string) ($cycle['prompt_receipt'] ?? ($cycle['receipt'] ?? ''))),
            'spec' => (array) ($cycle['spec'] ?? []),
            'decision' => trim((string) ($cycle['decision'] ?? '')),
            'provider' => trim((string) ($cycle['provider'] ?? '')),
            'files_touched' => $filesTouched,
            'evidence' => $evidence,
            'validation' => ['certified' => $certified, 'reasons' => $reasons],
            'metrics' => (array) ($cycle['metrics'] ?? []),
            'failures' => self::stringList($cycle['failures'] ?? []),
            'learning' => trim((string) ($cycle['learning'] ?? '')),
            'certified' => $certified,
        ];
    }

    /**
     * Does the evidence carry a `tests_or_gates_result` proof? Accepts the map shape
     * (`['tests_or_gates_result' => 'OK']`, non-empty value) and the list shape (`['tests_or_gates_result']`).
     *
     * @param  array<string,mixed>  $evidence
     */
    private static function hasTestsOrGatesEvidence(array $evidence): bool
    {
        if (array_key_exists('tests_or_gates_result', $evidence)) {
            return ! in_array($evidence['tests_or_gates_result'], [null, '', false, []], true);
        }

        foreach ($evidence as $value) {
            if (is_scalar($value) && trim((string) $value) === 'tests_or_gates_result') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $v) {
            $s = is_scalar($v) ? trim((string) $v) : '';
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }
}
