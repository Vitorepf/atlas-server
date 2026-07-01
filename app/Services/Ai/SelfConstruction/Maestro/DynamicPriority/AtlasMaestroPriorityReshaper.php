<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\DynamicPriority;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use RuntimeException;

/**
 * Deterministic, scoring-free re-orderer for the maestro pending queue.
 *
 * Comparator (lexicographic, FACTS only — no weights, no floats, no learned scores):
 *   1) higher dependency_criticality first (unblocks more downstream work)
 *   2) higher value_tier first (AC: old high-value packets must never be buried under fresh
 *      low-value ones — this stage runs before age, so value always outranks freshness)
 *   3) worker_starvation_unblock: true before false (unblocks idle workers)
 *   4) worker_fit: true before false (a packet a currently-available worker can actually serve)
 *   5) poison: non-poison before poison (push quarantined families down)
 *   6) high_give_back_risk: clean before risky
 *   7) lower proof_demand first (lighter-proof packets clear the queue faster; weaker signal
 *      than give_back_risk — a heavy-proof but otherwise clean packet still outranks a risky one)
 *   8) older enqueued_at first (FIFO tiebreak)
 *   9) lexical task_packet_id (deterministic final tiebreak)
 * All new facts default to 0/false when absent, so callers that never set them keep identical
 * ordering to before these facts existed.
 *
 * MASTER-OFF byte-identical no-op: when ATLAS_LOOP_MASTER_ENABLED=false, reshape() returns the
 * input order unchanged and writes NOTHING to disk.
 *
 * FORBIDDEN scope adversarial guard: a queue carrying a tag in {marketing, marketingdomain,
 * aaeos, forge, desktop} is REFUSED with a contract violation — the loop only reshapes its own
 * scope.
 */
final class AtlasMaestroPriorityReshaper
{
    public const SCHEMA = 'atlas.maestro.priority_reshape.v1';

    public const FORBIDDEN_SCOPE_TAGS = [
        'marketing',
        'marketingdomain',
        'aaeos',
        'forge',
        'desktop',
    ];

    /** @var callable():bool|null */
    private $masterGate;

    public function __construct(
        private readonly string $sequencePath,
        ?callable $masterGate = null,
    ) {
        $this->masterGate = $masterGate;
    }

    /**
     * @param  list<array<string,mixed>>  $packets  each: {task_packet_id, enqueued_at (ISO-8601), tag}
     * @param  array<string,mixed>        $snapshot fact snapshot consulted keys (all default to empty/false):
     *                                              facts.dependency_criticality_by_task_id (int, higher wins),
     *                                              facts.worker_starvation_unblock_by_task_id (bool, true rises above FIFO),
     *                                              facts.poison_family_by_task_id (bool, true sinks below clean peers),
     *                                              facts.high_give_back_risk_by_task_id (bool, true sinks below clean
     *                                              peers, weaker than poison; dependency-critical/starvation-unblock
     *                                              still override it via the earlier comparator stages)
     * @param  string                     $queueTag  for the forbidden-scope guard
     * @return list<array<string,mixed>>  ordered packets (input order if master-off)
     */
    public function reshape(array $packets, array $snapshot, string $queueTag): array
    {
        $this->assertNotForbidden($queueTag);

        if (! $this->armed()) {
            return array_values($packets);
        }

        $facts = (array) ($snapshot['facts'] ?? []);
        $criticality = (array) ($facts['dependency_criticality_by_task_id'] ?? []);
        $poison = (array) ($facts['poison_family_by_task_id'] ?? []);
        $starvation = (array) ($facts['worker_starvation_unblock_by_task_id'] ?? $facts['fairness_starvation_unblock_by_task_id'] ?? []);
        $giveBackRisk = (array) ($facts['high_give_back_risk_by_task_id'] ?? []);
        $value = (array) ($facts['value_tier_by_task_id'] ?? []);
        $workerFit = (array) ($facts['worker_fit_by_task_id'] ?? []);
        $proofDemand = (array) ($facts['proof_demand_by_task_id'] ?? []);

        $augmented = array_map(static function (array $packet) use ($criticality, $poison, $starvation, $giveBackRisk, $value, $workerFit, $proofDemand): array {
            $id = (string) ($packet['task_packet_id'] ?? '');

            return [
                'packet' => $packet,
                '_criticality' => (int) ($criticality[$id] ?? 0),
                '_value' => (int) ($value[$id] ?? 0),
                '_starvation' => (bool) ($starvation[$id] ?? false),
                '_worker_fit' => (bool) ($workerFit[$id] ?? false),
                '_poison' => (bool) ($poison[$id] ?? false),
                '_give_back_risk' => (bool) ($giveBackRisk[$id] ?? false),
                '_proof_demand' => (int) ($proofDemand[$id] ?? 0),
                '_enqueued_at' => (string) ($packet['enqueued_at'] ?? ''),
                '_id' => $id,
            ];
        }, array_values($packets));

        usort($augmented, static function (array $a, array $b): int {
            // (1) higher criticality first
            $c = $b['_criticality'] <=> $a['_criticality'];
            if ($c !== 0) {
                return $c;
            }
            // (2) higher value_tier first — old high-value packets never buried under fresh
            // low-value ones (this stage runs before age).
            $c = $b['_value'] <=> $a['_value'];
            if ($c !== 0) {
                return $c;
            }
            // (3) starvation-unblock: true before false (unblocks idle workers)
            $c = ($b['_starvation'] ? 1 : 0) <=> ($a['_starvation'] ? 1 : 0);
            if ($c !== 0) {
                return $c;
            }
            // (4) worker_fit: true before false (a packet an available worker can actually serve)
            $c = ($b['_worker_fit'] ? 1 : 0) <=> ($a['_worker_fit'] ? 1 : 0);
            if ($c !== 0) {
                return $c;
            }
            // (5) poison: non-poison before poison (push quarantined families down)
            $c = ($a['_poison'] ? 1 : 0) <=> ($b['_poison'] ? 1 : 0);
            if ($c !== 0) {
                return $c;
            }
            // (6) high give_back risk: clean before risky (sinks behind clean work, weaker than
            // poison; criticality/starvation above already override this for explicit cases)
            $c = ($a['_give_back_risk'] ? 1 : 0) <=> ($b['_give_back_risk'] ? 1 : 0);
            if ($c !== 0) {
                return $c;
            }
            // (7) lower proof_demand first — weaker than give_back_risk, breaks ties among
            // otherwise-clean packets by lighter proof burden.
            $c = $a['_proof_demand'] <=> $b['_proof_demand'];
            if ($c !== 0) {
                return $c;
            }
            // (8) older enqueued_at first (strcmp on ISO-8601 sorts chronologically)
            $c = strcmp($a['_enqueued_at'], $b['_enqueued_at']);
            if ($c !== 0) {
                return $c;
            }

            // (9) lexical task_packet_id
            return strcmp($a['_id'], $b['_id']);
        });

        $ordered = array_map(static fn (array $row): array => (array) $row['packet'], $augmented);

        $this->writeSequence($queueTag, $ordered);

        return $ordered;
    }

    /** Base priority score every packet starts from before boosts/downranks apply. */
    private const BASE_PRIORITY = 5;

    /**
     * Explains a SINGLE packet's dynamic priority as compound impact facts, not a static label —
     * boosts for unblocking downstream chains, reducing poison risk, or repairing queue health;
     * downranks for stale low-yield families or repeated weak-green shapes.
     *
     * @param  array{
     *   unblocks_downstream_count?:int, reduces_poison_risk?:bool, repairs_queue_health?:bool,
     *   stale_low_yield_family?:bool, repeated_weak_green_shape?:bool,
     * }  $facts
     * @return array{reshaped_priority:int, boost_reasons:list<string>, downrank_reasons:list<string>, priority_delta:int}
     */
    public function explainPriority(array $facts): array
    {
        $unblocksDownstream = max(0, (int) ($facts['unblocks_downstream_count'] ?? 0));
        $reducesPoisonRisk = (bool) ($facts['reduces_poison_risk'] ?? false);
        $repairsQueueHealth = (bool) ($facts['repairs_queue_health'] ?? false);
        $staleLowYieldFamily = (bool) ($facts['stale_low_yield_family'] ?? false);
        $repeatedWeakGreenShape = (bool) ($facts['repeated_weak_green_shape'] ?? false);

        $boostReasons = [];
        $downrankReasons = [];
        $delta = 0;

        if ($unblocksDownstream > 0) {
            $delta += 2;
            $boostReasons[] = 'unblocks_downstream_chain';
        }
        if ($reducesPoisonRisk) {
            $delta += 2;
            $boostReasons[] = 'reduces_poison_risk';
        }
        if ($repairsQueueHealth) {
            $delta += 1;
            $boostReasons[] = 'repairs_queue_health';
        }
        if ($staleLowYieldFamily) {
            $delta -= 2;
            $downrankReasons[] = 'stale_low_yield_family';
        }
        if ($repeatedWeakGreenShape) {
            $delta -= 1;
            $downrankReasons[] = 'repeated_weak_green_shape';
        }

        return [
            'reshaped_priority' => self::BASE_PRIORITY + $delta,
            'boost_reasons' => $boostReasons,
            'downrank_reasons' => $downrankReasons,
            'priority_delta' => $delta,
        ];
    }

    /**
     * Idempotency surface: the digest of the canonical sequence row file. Identical calls write
     * identical bytes ⇒ identical digest.
     */
    public function sequenceDigest(string $queueTag): string
    {
        $path = $this->pathFor($queueTag);
        if (! is_file($path)) {
            return '';
        }

        return (string) hash_file('sha256', $path);
    }

    /**
     * @param  list<array<string,mixed>>  $ordered
     */
    private function writeSequence(string $queueTag, array $ordered): void
    {
        $row = [
            'schema' => self::SCHEMA,
            'queue_tag' => $queueTag,
            'serving_sequence' => array_map(
                static fn (array $p): string => (string) ($p['task_packet_id'] ?? ''),
                $ordered,
            ),
        ];
        ksort($row, SORT_STRING);

        $path = $this->pathFor($queueTag);
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('priority_reshaper_mkdir_failed:'.$dir);
        }

        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
        $bytes = (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('priority_reshaper_write_failed');
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('priority_reshaper_rename_failed');
        }
    }

    private function pathFor(string $queueTag): string
    {
        $safeTag = preg_replace('/[^A-Za-z0-9._-]+/', '_', $queueTag) ?? 'default';
        $safeTag = $safeTag === '' ? 'default' : $safeTag;

        return rtrim($this->sequencePath, '/').'/queue_'.$safeTag.'.json';
    }

    private function assertNotForbidden(string $queueTag): void
    {
        if (in_array(strtolower($queueTag), self::FORBIDDEN_SCOPE_TAGS, true)) {
            throw new RuntimeException('priority_reshaper_forbidden_scope_tag:'.$queueTag);
        }
    }

    private function armed(): bool
    {
        if (is_callable($this->masterGate)) {
            return (bool) call_user_func($this->masterGate);
        }

        return AtlasLoopMasterSwitch::enabled();
    }
}
