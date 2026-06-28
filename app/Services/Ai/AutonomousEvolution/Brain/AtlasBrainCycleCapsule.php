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

        return [
            'schema' => self::SCHEMA,
            'task_packet_id' => $taskPacketId,
            'scope' => trim((string) ($cycle['scope'] ?? '')),
            'objective' => trim((string) ($cycle['objective'] ?? '')),
            'prompt_receipt' => trim((string) ($cycle['prompt_receipt'] ?? ($cycle['receipt'] ?? ''))),
            'spec' => (array) ($cycle['spec'] ?? []),
            'decision' => trim((string) ($cycle['decision'] ?? '')),
            'provider' => trim((string) ($cycle['provider'] ?? '')),
            'files_touched' => self::stringList($cycle['files_touched'] ?? []),
            'evidence' => (array) ($cycle['evidence'] ?? []),
            'validation' => ['certified' => $certified, 'reasons' => self::stringList($validation['reasons'] ?? [])],
            'metrics' => (array) ($cycle['metrics'] ?? []),
            'failures' => self::stringList($cycle['failures'] ?? []),
            'learning' => trim((string) ($cycle['learning'] ?? '')),
            'certified' => $certified,
        ];
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
