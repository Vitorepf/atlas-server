<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

/**
 * PART 2 · the SWARM PROOF — the conflict-free X-RAY (the judging instrument the operator's loop demands).
 *
 * The A7 contract test proves disjoint packets SEQUENTIALLY (two `next` calls, one process, one after the
 * other). That proves the LOGIC, never the concurrency: the whole point of A1 (flock) + A2 (CAS) + the lease
 * conflict-rejection is to survive N clients hitting the SAME claimable packet at the SAME instant. This class
 * is the pure, deterministic ANALYZER over a multi-client run: given what each concurrent client observed, it
 * decides — without re-running anything — whether the serving stayed conflict-free, never failed (R2), and
 * never invented or lost a packet.
 *
 * Provider-free, side-effect-free, frozen-testable: the crystallized intelligence, not the runtime model. The
 * matching coordinator/worker that actually spawns the N real OS processes lives in
 * {@see \App\Console\Commands\AtlasTaskSwarmProofCommand}; this class only judges its observations.
 *
 * The invariants (each a FACT about the observed run, never a score):
 *   - EXACTLY-ONCE (double-claim free): within a round, no task_packet_id is `served` to more than one client.
 *     This is the core A1/A2 guarantee — two clients NEVER hold the same packet.
 *   - HELD-OVERLAP free: within a round, no two concurrently-`served` packets have overlapping write-touched
 *     paths ({@see WriteSetOverlap::conflict}). The lease repo must reject the second of any colliding pair.
 *   - R2 (serving never fails): every client process exits cleanly with an HONEST envelope status (`served` or
 *     `no_claimable_task`). `disabled`/`invalid`/`error`/missing/non-zero-exit = a serving breach.
 *   - NO PHANTOM: every `served` packet id was actually enqueued that round (no fabricated work).
 */
final class AtlasTaskSwarmProofService
{
    use NormalizesToStringList;
    public const SCHEMA = 'atlas.task_serving.swarm_proof.v1';

    /** Envelope statuses that are HONEST for a `next` call (empty/all-doomed queue is honest; an error is not). */
    public const HONEST_NEXT_STATUSES = ['served', 'no_claimable_task', 'no_self_sufficient_task'];

    /**
     * Judge a multi-round, multi-client run. Pure: same observations ⇒ byte-identical verdict.
     *
     * @param  list<array{round?:int, enqueued?:list<array<string,mixed>>, observations?:list<array<string,mixed>>}>  $rounds
     * @return array<string, mixed>
     */
    public function analyze(array $rounds): array
    {
        $doubleClaims = [];
        $heldOverlaps = [];
        $r2Breaches = [];
        $phantomServes = [];

        $totalObservations = 0;
        $totalServed = 0;
        $totalNoClaimable = 0;
        $servedDistinctSum = 0;

        foreach ($rounds as $roundIndex => $round) {
            $roundNo = (int) ($round['round'] ?? $roundIndex);
            $enqueued = $this->indexEnqueued((array) ($round['enqueued'] ?? []));
            $observations = array_values((array) ($round['observations'] ?? []));

            // Group served observations by packet id; collect honesty/exit breaches.
            $servedBy = [];   // task_packet_id => list<client_id>
            foreach ($observations as $obs) {
                $totalObservations++;
                $client = (string) ($obs['client_id'] ?? '');
                $exit = (int) ($obs['exit_code'] ?? 0);
                $envelope = (array) ($obs['envelope'] ?? []);
                $status = (string) ($envelope['status'] ?? 'missing');

                if ($exit !== 0 || ! in_array($status, self::HONEST_NEXT_STATUSES, true)) {
                    $r2Breaches[] = [
                        'round' => $roundNo,
                        'client_id' => $client,
                        'exit_code' => $exit,
                        'status' => $status,
                        'reason' => $exit !== 0 ? 'nonzero_exit' : 'dishonest_status',
                    ];

                    continue;
                }

                if ($status !== 'served') {
                    $totalNoClaimable++;

                    continue;
                }

                $totalServed++;
                $packetId = (string) (data_get($envelope, 'task.task_packet_id', ''));
                if ($packetId === '') {
                    $r2Breaches[] = [
                        'round' => $roundNo, 'client_id' => $client, 'exit_code' => $exit,
                        'status' => 'served', 'reason' => 'served_without_task_packet_id',
                    ];

                    continue;
                }
                if (! array_key_exists($packetId, $enqueued)) {
                    $phantomServes[] = ['round' => $roundNo, 'client_id' => $client, 'task_packet_id' => $packetId];
                }
                $servedBy[$packetId][] = $client;
            }

            // EXACTLY-ONCE: any packet served to >1 distinct client this round is a double-claim.
            foreach ($servedBy as $packetId => $clients) {
                $distinct = array_values(array_unique($clients));
                if (count($distinct) > 1) {
                    $doubleClaims[] = ['round' => $roundNo, 'task_packet_id' => (string) $packetId, 'clients' => $distinct];
                }
            }

            // HELD-OVERLAP free: the set of distinct packets concurrently held must be pairwise non-conflicting.
            $heldIds = array_keys($servedBy);
            $servedDistinctSum += count($heldIds);
            for ($i = 0; $i < count($heldIds); $i++) {
                for ($j = $i + 1; $j < count($heldIds); $j++) {
                    $a = $enqueued[$heldIds[$i]] ?? null;
                    $b = $enqueued[$heldIds[$j]] ?? null;
                    if ($a === null || $b === null) {
                        continue; // phantom already flagged
                    }
                    $paths = WriteSetOverlap::conflicts($a['write_set'], $a['read_set'], $b['write_set'], $b['read_set']);
                    if ($paths !== []) {
                        $heldOverlaps[] = [
                            'round' => $roundNo,
                            'task_packet_id_a' => (string) $heldIds[$i],
                            'task_packet_id_b' => (string) $heldIds[$j],
                            'colliding_paths' => $paths,
                        ];
                    }
                }
            }
        }

        $passed = $doubleClaims === [] && $heldOverlaps === [] && $r2Breaches === [] && $phantomServes === [];

        return [
            'schema' => self::SCHEMA,
            'rounds' => count($rounds),
            'conflict_free' => $doubleClaims === [] && $heldOverlaps === [],
            'passed' => $passed,
            'double_claims' => $doubleClaims,
            'held_overlaps' => $heldOverlaps,
            'r2_breaches' => $r2Breaches,
            'phantom_serves' => $phantomServes,
            'totals' => [
                'observations' => $totalObservations,
                'served' => $totalServed,
                'no_claimable_task' => $totalNoClaimable,
                'served_distinct_across_rounds' => $servedDistinctSum,
            ],
        ];
    }

    /**
     * Judge a two-phase give-back→reclaim run: phase A clients claim AND report give_back (releasing the
     * task), phase B clients pull. Proves the live serving path re-admits a given-back task instead of
     * stranding it — every packet given back in A must be reclaimed (re-served) in B, each phase conflict-free.
     *
     * @param  list<array<string,mixed>>  $enqueued
     * @param  list<array<string,mixed>>  $phaseAObservations
     * @param  list<array<string,mixed>>  $phaseBObservations
     * @return array<string, mixed>
     */
    public function analyzeReclaim(array $enqueued, array $phaseAObservations, array $phaseBObservations): array
    {
        $phaseA = $this->analyze([['round' => 0, 'enqueued' => $enqueued, 'observations' => $phaseAObservations]]);
        $phaseB = $this->analyze([['round' => 1, 'enqueued' => $enqueued, 'observations' => $phaseBObservations]]);

        $givenBack = $this->distinctServedPackets($phaseAObservations);
        $reclaimed = $this->distinctServedPackets($phaseBObservations);
        $missing = array_values(array_diff($givenBack, $reclaimed));

        // Phase A clients give back, so the SAME packet is legitimately re-served SERIALLY within the phase
        // (claim → give_back → another client reclaims). The lease repo STRUCTURALLY forbids two ACTIVE leases
        // on one packet (hasActiveLeaseFor + flock), so a re-serve can only be serial, never a simultaneous
        // double-hold — hence exactly-once does NOT apply to phase A. Its honest invariants are: no held-overlap
        // (no two write-colliding packets), no R2 breach, no phantom. Phase B is a pure pull (everyone HOLDS at
        // once), so exactly-once DOES apply there — that is where the reclaim conflict-freedom is proven.
        $phaseAClean = $phaseA['held_overlaps'] === [] && $phaseA['r2_breaches'] === [] && $phaseA['phantom_serves'] === [];

        $passed = $missing === [] && $givenBack !== [] && $phaseAClean && $phaseB['passed'];

        return [
            'schema' => self::SCHEMA,
            'mode' => 'give_back_reclaim',
            'passed' => $passed,
            'given_back' => $givenBack,
            'reclaimed' => $reclaimed,
            'missing_reclaim' => $missing,
            'phase_a_clean' => $phaseAClean,
            'phase_a' => $phaseA,
            'phase_b' => $phaseB,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $observations
     * @return list<string>
     */
    private function distinctServedPackets(array $observations): array
    {
        $ids = [];
        foreach ($observations as $obs) {
            $envelope = (array) ($obs['envelope'] ?? []);
            if ((string) ($envelope['status'] ?? '') !== 'served') {
                continue;
            }
            $id = (string) data_get($envelope, 'task.task_packet_id', '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        $out = array_keys($ids);
        sort($out);

        return $out;
    }

    /**
     * Index enqueued specs by task_packet_id with normalized write/read sets.
     *
     * @param  list<array<string,mixed>>  $enqueued
     * @return array<string, array{write_set:list<string>, read_set:list<string>}>
     */
    private function indexEnqueued(array $enqueued): array
    {
        $out = [];
        foreach ($enqueued as $spec) {
            $id = (string) ($spec['task_packet_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[$id] = [
                'write_set' => $this->stringList((array) ($spec['write_set'] ?? [])),
                'read_set' => $this->stringList((array) ($spec['read_set'] ?? [])),
            ];
        }

        return $out;
    }

}
