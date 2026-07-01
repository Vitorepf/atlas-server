<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proof-backed dependency invariant: turns a destructive compression candidate into an ordered
 * task graph — extract_contracts → prove_equivalence → add_mutation_guards → compress →
 * knowledge_sync — and blocks the compress node whenever any upstream proof node is missing or
 * failed. Only a candidate whose action is merge, delete, or simplify is ever graphed; a 'keep'
 * candidate has nothing destructive to gate.
 *
 * Input contract:
 *   candidates: list<array{
 *     candidate_id?:            string,
 *     action?:                  string  ('delete'|'merge'|'simplify'|'keep'),
 *     contracts_extracted?:     bool,    (absent = missing, true = passed, false = failed)
 *     equivalence_proven?:      bool,
 *     mutation_guards_added?:   bool,
 *   }>
 *
 * A fact key that is simply absent is treated as 'missing' evidence, not as an implicit pass —
 * the compress node is fail-closed: it is admitted only when every upstream proof node's
 * status is 'passed'.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionTaskGraphBuilder
{
    public const SCHEMA = 'atlas.external_brain.compression_task_graph_builder.v1';

    public const STAGE_EXTRACT_CONTRACTS   = 'extract_contracts';
    public const STAGE_PROVE_EQUIVALENCE   = 'prove_equivalence';
    public const STAGE_ADD_MUTATION_GUARDS = 'add_mutation_guards';
    public const STAGE_COMPRESS            = 'compress';
    public const STAGE_KNOWLEDGE_SYNC      = 'knowledge_sync';

    private const STAGE_SEQUENCE = [
        self::STAGE_EXTRACT_CONTRACTS,
        self::STAGE_PROVE_EQUIVALENCE,
        self::STAGE_ADD_MUTATION_GUARDS,
        self::STAGE_COMPRESS,
        self::STAGE_KNOWLEDGE_SYNC,
    ];

    private const STATUS_PASSED  = 'passed';
    private const STATUS_FAILED  = 'failed';
    private const STATUS_MISSING = 'missing';

    /** @var list<string> */
    private const DESTRUCTIVE_ACTIONS = ['delete', 'merge', 'simplify'];

    private const PROOF_FACT_BY_STAGE = [
        self::STAGE_EXTRACT_CONTRACTS   => 'contracts_extracted',
        self::STAGE_PROVE_EQUIVALENCE   => 'equivalence_proven',
        self::STAGE_ADD_MUTATION_GUARDS => 'mutation_guards_added',
    ];

    /**
     * @param  array{candidates?: list<array<string,mixed>>}  $input
     * @return array{schema:string, nodes:list<array<string,mixed>>, blocked_compress_nodes:list<string>}
     */
    public function build(array $input): array
    {
        $candidates = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];

        $nodes = [];
        $blockedCompressNodes = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $candidateId = trim((string) ($candidate['candidate_id'] ?? ''));
            $action      = (string) ($candidate['action'] ?? '');
            if ($candidateId === '' || ! in_array($action, self::DESTRUCTIVE_ACTIONS, true)) {
                continue;
            }

            $proofStages   = [];
            $blockingProof = [];
            $previousNodeId = null;

            foreach (self::STAGE_SEQUENCE as $stage) {
                $nodeId = "{$stage}:{$candidateId}";

                if ($stage === self::STAGE_COMPRESS) {
                    $blockingProof = $this->collectBlockingProof($proofStages);
                    $admitted = $blockingProof === [];
                    if (! $admitted) {
                        $blockedCompressNodes[] = $nodeId;
                    }

                    $nodes[] = [
                        'node_id'       => $nodeId,
                        'stage'         => $stage,
                        'candidate_id'  => $candidateId,
                        'depends_on'    => $previousNodeId !== null ? [$previousNodeId] : [],
                        'admitted'      => $admitted,
                        'blocking_reasons' => $blockingProof,
                    ];
                    $previousNodeId = $nodeId;

                    continue;
                }

                if (array_key_exists($stage, self::PROOF_FACT_BY_STAGE)) {
                    $status = $this->proofStatus($candidate, self::PROOF_FACT_BY_STAGE[$stage]);
                    $proofStages[$stage] = $status;

                    $nodes[] = [
                        'node_id'      => $nodeId,
                        'stage'        => $stage,
                        'candidate_id' => $candidateId,
                        'depends_on'   => $previousNodeId !== null ? [$previousNodeId] : [],
                        'status'       => $status,
                    ];
                    $previousNodeId = $nodeId;

                    continue;
                }

                // knowledge_sync: always depends on compress, carries no proof status of its own.
                $nodes[] = [
                    'node_id'      => $nodeId,
                    'stage'        => $stage,
                    'candidate_id' => $candidateId,
                    'depends_on'   => $previousNodeId !== null ? [$previousNodeId] : [],
                ];
                $previousNodeId = $nodeId;
            }
        }

        return [
            'schema'                  => self::SCHEMA,
            'nodes'                   => $nodes,
            'blocked_compress_nodes'  => $blockedCompressNodes,
        ];
    }

    /** @param  array<string,mixed>  $candidate */
    private function proofStatus(array $candidate, string $factKey): string
    {
        if (! array_key_exists($factKey, $candidate)) {
            return self::STATUS_MISSING;
        }

        return (bool) $candidate[$factKey] ? self::STATUS_PASSED : self::STATUS_FAILED;
    }

    /**
     * @param  array<string,string>  $proofStages
     * @return list<string>
     */
    private function collectBlockingProof(array $proofStages): array
    {
        $reasons = [];
        foreach (self::PROOF_FACT_BY_STAGE as $stage => $factKey) {
            $status = $proofStages[$stage] ?? self::STATUS_MISSING;
            if ($status !== self::STATUS_PASSED) {
                $reasons[] = "{$stage}_{$status}";
            }
        }

        return $reasons;
    }
}
