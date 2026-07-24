<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;

/**
 * MAXJ-03 — read-model that groups `ai_learning_candidates` rows by signature
 * `{memory_type, caused_by.primary_cause, scope}` and, for each signature with
 * `case_count >= floor`, proposes ONE abstract lesson carrying the union of
 * evidence refs and the case_count as a strength signal.
 *
 * Invariants:
 * - Deterministic: signature grouping is a pure function of the persisted
 *   candidate row; ordering is stable (memory_type asc, primary_cause asc,
 *   scope asc, then candidate_hash asc within a group).
 * - Dedup by candidate_hash: two rows with the same hash never inflate the
 *   `case_count` of a signature — the plan is explicit about "case_count
 *   nunca inflado por duplicados".
 * - Floor is pétreo `> 2` (hard-floor 3) and default 8 — a group with 2
 *   candidates produces NO proposal even if the caller passes floor=2.
 * - Heterogeneous groups (different memory_type OR primary_cause OR scope)
 *   never fuse — the signature is the join key, not similarity.
 * - Shadow-first / reversible: this service returns a list of PROPOSALS
 *   (never writes memory, never touches AiCompoundingMemory, never routes
 *   through the ASI-02 chokepoint). Callers decide when to promote via
 *   the existing AtlasLearningProposalService / AtlasMemoryRegistryService
 *   in a follow-up slice; MAXJ-03 landing is the read-model.
 * - Fail-open: tables missing / candidates absent ⇒ `insufficient_signal`
 *   status with empty proposals; NEVER throws.
 */
final class AtlasLearningCandidateGeneralizationService
{
    public const SCHEMA_VERSION = 'atlas.ai.learning_candidate_generalization.v1';

    public const DEFAULT_FLOOR = 8;

    /**
     * Hard floor (pétreo) — the plan pins "mínimo pétreo > 2". The service
     * NEVER emits a proposal for a group with fewer than 3 UNIQUE candidates,
     * regardless of what the caller passes.
     */
    public const HARD_FLOOR_MIN = 3;

    public const UNKNOWN_PRIMARY_CAUSE = 'unknown';

    /**
     * @return array<string,mixed>
     */
    public function propose(?int $floor = null): array
    {
        $effectiveFloor = max(self::HARD_FLOOR_MIN, (int) ($floor ?? self::DEFAULT_FLOOR));

        if (! DatabaseTableAvailability::has('ai_learning_candidates')) {
            return $this->emptyReport($effectiveFloor, 'table_missing');
        }

        /** @var list<AiLearningCandidate> $rows */
        $rows = AiLearningCandidate::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        if ($rows === []) {
            return $this->emptyReport($effectiveFloor, 'insufficient_signal');
        }

        [$groups, $stats] = $this->groupBySignature($rows);

        $proposals = [];
        foreach ($groups as $signatureKey => $group) {
            if ($group['case_count'] < $effectiveFloor) {
                continue;
            }
            $proposals[] = $this->buildProposal($signatureKey, $group);
        }

        usort($proposals, static fn (array $a, array $b): int => strcmp((string) $a['signature_key'], (string) $b['signature_key']));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $proposals !== [] ? 'ok' : 'insufficient_signal',
            'generated_at' => Carbon::now()->toIso8601String(),
            'floor' => $effectiveFloor,
            'default_floor' => self::DEFAULT_FLOOR,
            'hard_floor_min' => self::HARD_FLOOR_MIN,
            'totals' => [
                'rows_scanned' => $stats['rows_scanned'],
                'duplicates_collapsed' => $stats['duplicates_collapsed'],
                'unique_candidates' => $stats['unique_candidates'],
                'signatures' => count($groups),
                'proposals' => count($proposals),
            ],
            'proposals' => $proposals,
            'claim_policy' => [
                'read_only' => true,
                'memory_written' => false,
                'auto_promotion_allowed' => false,
                'shadow_first' => true,
            ],
        ];
    }

    /**
     * @param  list<AiLearningCandidate>  $rows
     * @return array{
     *     0: array<string,array{
     *         memory_type:string,
     *         primary_cause:string,
     *         scope:string,
     *         case_count:int,
     *         candidate_hashes:list<string>,
     *         evidence_refs:list<string>,
     *         member_ids:list<string>,
     *         claim_samples:list<string>
     *     }>,
     *     1: array{rows_scanned:int,duplicates_collapsed:int,unique_candidates:int}
     * }
     */
    private function groupBySignature(array $rows): array
    {
        $groups = [];
        $seenHashes = [];
        $duplicates = 0;

        foreach ($rows as $candidate) {
            $hash = (string) ($candidate->candidate_hash ?? '');
            if ($hash === '') {
                continue;
            }
            if (isset($seenHashes[$hash])) {
                $duplicates++;

                continue;
            }
            $seenHashes[$hash] = true;

            $memoryType = $this->nonEmptyString($candidate->memory_type) ?? 'unknown';
            $scope = $this->nonEmptyString($candidate->scope) ?? 'global';
            $primaryCause = $this->primaryCauseOf($candidate);
            $key = $this->signatureKey($memoryType, $primaryCause, $scope);

            $groups[$key] ??= [
                'memory_type' => $memoryType,
                'primary_cause' => $primaryCause,
                'scope' => $scope,
                'case_count' => 0,
                'candidate_hashes' => [],
                'evidence_refs' => [],
                'member_ids' => [],
                'claim_samples' => [],
            ];

            $groups[$key]['case_count']++;
            $groups[$key]['candidate_hashes'][] = $hash;
            $groups[$key]['member_ids'][] = (string) $candidate->id;
            foreach ($this->evidenceRefsOf($candidate) as $ref) {
                if (! in_array($ref, $groups[$key]['evidence_refs'], true)) {
                    $groups[$key]['evidence_refs'][] = $ref;
                }
            }
            $claim = $this->nonEmptyString($candidate->claim);
            if ($claim !== null && count($groups[$key]['claim_samples']) < 3) {
                $groups[$key]['claim_samples'][] = $claim;
            }
        }

        ksort($groups);
        foreach ($groups as $key => $group) {
            sort($groups[$key]['candidate_hashes']);
            sort($groups[$key]['member_ids']);
            sort($groups[$key]['evidence_refs']);
        }

        return [$groups, [
            'rows_scanned' => count($rows),
            'duplicates_collapsed' => $duplicates,
            'unique_candidates' => count($seenHashes),
        ]];
    }

    /**
     * @param  array{
     *     memory_type:string,
     *     primary_cause:string,
     *     scope:string,
     *     case_count:int,
     *     candidate_hashes:list<string>,
     *     evidence_refs:list<string>,
     *     member_ids:list<string>,
     *     claim_samples:list<string>
     * }  $group
     * @return array<string,mixed>
     */
    private function buildProposal(string $signatureKey, array $group): array
    {
        $claim = sprintf(
            'MAXJ-03 abstract lesson: %d cases of memory_type=%s share primary_cause=%s in scope=%s — treat as one generalized lesson with case_count=%d.',
            $group['case_count'],
            $group['memory_type'],
            $group['primary_cause'],
            $group['scope'],
            $group['case_count'],
        );

        $proposalHash = CompoundingHash::make([
            'schema' => self::SCHEMA_VERSION,
            'signature' => $signatureKey,
            'candidate_hashes' => $group['candidate_hashes'],
        ]);

        return [
            'signature_key' => $signatureKey,
            'memory_type' => $group['memory_type'],
            'primary_cause' => $group['primary_cause'],
            'scope' => $group['scope'],
            'case_count' => $group['case_count'],
            'claim' => $claim,
            'candidate_hashes' => $group['candidate_hashes'],
            'member_ids' => $group['member_ids'],
            'evidence_refs' => $group['evidence_refs'],
            'claim_samples' => $group['claim_samples'],
            'proposal_hash' => $proposalHash,
        ];
    }

    private function primaryCauseOf(AiLearningCandidate $candidate): string
    {
        $payload = $candidate->payload;
        if (! is_array($payload)) {
            return self::UNKNOWN_PRIMARY_CAUSE;
        }
        $causedBy = $payload['caused_by'] ?? null;
        if (! is_array($causedBy)) {
            return self::UNKNOWN_PRIMARY_CAUSE;
        }
        $cause = $this->nonEmptyString($causedBy['primary_cause'] ?? null);

        return $cause ?? self::UNKNOWN_PRIMARY_CAUSE;
    }

    /**
     * @return list<string>
     */
    private function evidenceRefsOf(AiLearningCandidate $candidate): array
    {
        $refs = $candidate->evidence_refs;
        if (! is_array($refs)) {
            return [];
        }
        $out = [];
        foreach ($refs as $ref) {
            if (! is_string($ref)) {
                continue;
            }
            $trim = trim($ref);
            if ($trim !== '') {
                $out[] = $trim;
            }
        }

        return $out;
    }

    private function signatureKey(string $memoryType, string $primaryCause, string $scope): string
    {
        return $memoryType.'|'.$primaryCause.'|'.$scope;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyReport(int $floor, string $status): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'floor' => $floor,
            'default_floor' => self::DEFAULT_FLOOR,
            'hard_floor_min' => self::HARD_FLOOR_MIN,
            'totals' => [
                'rows_scanned' => 0,
                'duplicates_collapsed' => 0,
                'unique_candidates' => 0,
                'signatures' => 0,
                'proposals' => 0,
            ],
            'proposals' => [],
            'claim_policy' => [
                'read_only' => true,
                'memory_written' => false,
                'auto_promotion_allowed' => false,
                'shadow_first' => true,
            ],
        ];
    }
}
