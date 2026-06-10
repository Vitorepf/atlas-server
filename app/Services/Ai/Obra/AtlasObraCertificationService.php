<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

/**
 * AOBG N3.F3 — INTEGRATION CERTIFICATION of the WHOLE obra.
 *
 * F2 walks the plan-DAG node by node onto ONE accumulating branch, certifying EACH
 * step in isolation (delivery php -l / self-test + an optional per-node Forge gate).
 * But a per-step pass does NOT imply the assembled obra integrates: step 3 may break
 * what step 1 built; two independently-green changes can conflict once on the same
 * branch. F3 closes that gap — it certifies the ASSEMBLED branch AS A WHOLE.
 *
 * This service is PURE assembly (no IO of its own): the executor runs the INTEGRATED
 * measure on the held obra worktree (via {@see \App\Services\Ai\RealExecution\GovernedBranchMaterializationService::measureObra()},
 * reusing the SAME measure the single-shot materializer uses) and hands the result
 * here together with the per-step receipts. This service decides certified-or-not and
 * builds the deterministic evidence envelope. Keeping it pure makes it cost-free and
 * trivially testable, and keeps the one place that touches git (the materializer) the
 * single git authority.
 *
 * HARD CONTRACTS (non-negotiable):
 *  - WHOLE > PARTS: certified === true ONLY when every step succeeded (no halt) AND
 *    the integrated measure RAN and PASSED. A per-step-green obra whose integrated
 *    test fails is certified=false → needs_review (the F3 invariant: prove a per-step
 *    pass does not imply the whole integrates).
 *  - HONEST DEGRADE: an integrated measure that WAS SUPPLIED but could NOT run
 *    (unrunnable worktree) — or that ran and FAILED — is NOT a certified pass; it is
 *    needs_review with an explicit disposition/reason. Certification is fail-CLOSED:
 *    a SUPPLIED integrated check that is not green never reads as certified.
 *  - NO-CHECK POLICY (explicit + honest): when NO integrated measure is supplied at
 *    all, the obra is the F2 baseline — every step certified in isolation and all
 *    applied to ONE branch. That IS a real, complete result, so it is delivered
 *    certified=true with disposition=no_integrated_check, which DOCUMENTS that no
 *    whole-branch proof was run (the operator knows the obra completed but was not
 *    integration-tested as a unit). The honest gradient is: no_integrated_check
 *    (complete, un-integration-tested) < certified (complete AND integration-proven).
 *    The F3 teeth bite the moment a check IS supplied and is not green.
 *  - DETERMINISTIC ENVELOPE: {obra_id, branch, certified, disposition, nodes:[per-step
 *    receipts], integrated_test_result, files_total, reason?, receipt_hash}. The
 *    receipt_hash is a sha256 over the load-bearing facts (obra_id, branch, certified,
 *    each step's id+commit+gate_receipt, the integrated result) — re-certifying the
 *    same assembled state yields the same hash.
 *  - PRIVACY: the envelope carries ids / commits / receipts / file paths / a bounded
 *    measure output tail — never source, never diffs.
 */
final class AtlasObraCertificationService
{
    public const SCHEMA = 'atlas.obra.certification.v1';

    /** Every step succeeded AND the integrated measure ran and passed. */
    public const DISPOSITION_CERTIFIED = 'certified';

    /** The obra halted on a step (F2 already marked it not-certified). */
    public const DISPOSITION_HALTED = 'halted';

    /** Steps passed but the integrated measure FAILED on the assembled branch. */
    public const DISPOSITION_INTEGRATION_FAILED = 'integration_failed';

    /** Steps passed but a SUPPLIED integrated measure could not RUN (unrunnable). */
    public const DISPOSITION_INTEGRATION_UNRUNNABLE = 'integration_unrunnable';

    /**
     * No integrated check was supplied — the F2 baseline (all steps certified +
     * applied to one branch), certified=true but NOT integration-tested as a unit.
     */
    public const DISPOSITION_NO_INTEGRATED_CHECK = 'no_integrated_check';

    /**
     * Certify the assembled obra.
     *
     * @param  array{
     *     obra_id:string,
     *     branch:string,
     *     halted?:bool,
     *     failed_node?:?string,
     *     nodes?:list<array<string,mixed>>,
     *     integrated?:?array<string,mixed>,
     *     integrated_supplied?:bool,
     * }  $input  the executor's run state: which steps ran (the per-step receipts),
     *           whether the obra halted, and the integrated measure RESULT (or null /
     *           integrated_supplied=false when none was run)
     * @return array<string,mixed> the evidence envelope (see class docblock)
     */
    public function certify(array $input): array
    {
        $obraId = trim((string) ($input['obra_id'] ?? ''));
        $branch = trim((string) ($input['branch'] ?? ''));
        $halted = (bool) ($input['halted'] ?? false);
        $failedNode = isset($input['failed_node']) && is_string($input['failed_node']) ? $input['failed_node'] : null;
        $integratedSupplied = (bool) ($input['integrated_supplied'] ?? ($input['integrated'] ?? null) !== null);

        // Per-step receipts (provider-safe: id / seq / status / commit / gate_receipt /
        // files_changed) — the proof EACH step certified in isolation.
        $nodes = $this->normalizeNodeReceipts((array) ($input['nodes'] ?? []));
        $filesTotal = $this->countDistinctFiles($nodes);

        // The integrated measure RESULT on the assembled branch (ran/passed/exit/tail).
        $integrated = is_array($input['integrated'] ?? null) ? (array) $input['integrated'] : null;
        $integratedResult = $this->normalizeIntegrated($integrated, $integratedSupplied);

        // --- DECIDE the disposition (fail-closed; whole > parts). ---
        [$certified, $disposition, $reason] = $this->decide($halted, $failedNode, $integratedResult);

        $envelope = [
            'schema' => self::SCHEMA,
            'obra_id' => $obraId,
            'branch' => $branch !== '' ? $branch : null,
            'certified' => $certified,
            'disposition' => $disposition,
            'reason' => $reason,
            'nodes' => $nodes,
            'node_count' => count($nodes),
            'integrated_test_result' => $integratedResult,
            'files_total' => $filesTotal,
            // Honest review verdict the executor surfaces in its envelope + the brain.
            'status' => $certified ? 'certified' : 'needs_review',
        ];

        $envelope['receipt_hash'] = $this->receiptHash($envelope);

        return $envelope;
    }

    /**
     * Fail-closed decision: certified ONLY when not halted AND the integrated measure
     * ran and passed. Returns [certified, disposition, reason].
     *
     * @param  array<string,mixed>  $integrated  the normalized integrated result
     * @return array{0:bool,1:string,2:?string}
     */
    private function decide(bool $halted, ?string $failedNode, array $integrated): array
    {
        if ($halted) {
            // F2 already halted on a failed/ungated step — the partial branch is kept
            // but the obra is not certified as a whole.
            return [false, self::DISPOSITION_HALTED, 'halted_on_node:'.((string) ($failedNode ?? '?'))];
        }

        if (! (bool) ($integrated['supplied'] ?? false)) {
            // NO integrated check supplied — the F2 baseline: every step certified in
            // isolation and all applied to ONE branch. That is a real, complete result
            // (certified=true), explicitly DOCUMENTED as not integration-tested as a
            // unit. The F3 teeth (below) bite the moment a check IS supplied + fails.
            return [true, self::DISPOSITION_NO_INTEGRATED_CHECK, null];
        }

        if (! (bool) ($integrated['ran'] ?? false)) {
            // The check was supplied but could not RUN — fail-closed (not a pass).
            return [false, self::DISPOSITION_INTEGRATION_UNRUNNABLE, 'integrated_check_unrunnable'];
        }

        if (! (bool) ($integrated['passed'] ?? false)) {
            // Per-step passes did NOT integrate (the F3 invariant made visible).
            return [false, self::DISPOSITION_INTEGRATION_FAILED, 'integrated_check_failed'];
        }

        return [true, self::DISPOSITION_CERTIFIED, null];
    }

    /**
     * Normalize the per-step receipts into provider-safe envelope rows. Only ids /
     * commits / receipts / paths — never source.
     *
     * @param  list<array<string,mixed>>  $nodes
     * @return list<array<string,mixed>>
     */
    private function normalizeNodeReceipts(array $nodes): array
    {
        $rows = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $rows[] = array_filter([
                'id' => isset($node['id']) ? (string) $node['id'] : null,
                'seq' => isset($node['seq']) ? (int) $node['seq'] : null,
                'status' => isset($node['status']) ? (string) $node['status'] : null,
                'commit' => isset($node['commit']) && is_string($node['commit']) ? $node['commit'] : null,
                'gate_receipt' => isset($node['gate_receipt']) && is_string($node['gate_receipt']) ? $node['gate_receipt'] : null,
                'files_changed' => isset($node['files_changed'])
                    ? array_values(array_filter((array) $node['files_changed'], 'is_string'))
                    : null,
                'provider' => isset($node['provider']) && is_string($node['provider']) ? $node['provider'] : null,
            ], static fn ($v): bool => $v !== null);
        }

        return $rows;
    }

    /**
     * Count distinct files touched across all certified steps (the obra's blast radius).
     *
     * @param  list<array<string,mixed>>  $nodes
     */
    private function countDistinctFiles(array $nodes): int
    {
        $seen = [];
        foreach ($nodes as $node) {
            foreach ((array) ($node['files_changed'] ?? []) as $f) {
                if (is_string($f) && $f !== '') {
                    $seen[$f] = true;
                }
            }
        }

        return count($seen);
    }

    /**
     * Normalize the integrated measure result into a stable, provider-safe shape.
     *
     * @param  array<string,mixed>|null  $integrated
     * @return array<string,mixed>
     */
    private function normalizeIntegrated(?array $integrated, bool $supplied): array
    {
        if (! $supplied || $integrated === null) {
            return ['supplied' => false, 'ran' => false, 'passed' => false, 'cmd' => null, 'exit_code' => null, 'output_tail' => null];
        }

        return [
            'supplied' => true,
            'ran' => (bool) ($integrated['ran'] ?? false),
            'passed' => (bool) ($integrated['passed'] ?? false),
            'cmd' => isset($integrated['cmd']) && is_string($integrated['cmd']) && $integrated['cmd'] !== '' ? $integrated['cmd'] : null,
            'exit_code' => array_key_exists('exit_code', $integrated) && $integrated['exit_code'] !== null
                ? (int) $integrated['exit_code']
                : null,
            // Bounded tail only — never the full output, never source.
            'output_tail' => isset($integrated['output_tail']) && is_string($integrated['output_tail'])
                ? mb_substr($integrated['output_tail'], -400)
                : null,
        ];
    }

    /**
     * Deterministic receipt hash over the load-bearing facts — re-certifying the same
     * assembled obra state yields the same hash (the envelope's tamper-evident seal).
     *
     * @param  array<string,mixed>  $envelope
     */
    private function receiptHash(array $envelope): string
    {
        $stepFacts = [];
        foreach ((array) ($envelope['nodes'] ?? []) as $node) {
            $stepFacts[] = [
                'id' => (string) ($node['id'] ?? ''),
                'status' => (string) ($node['status'] ?? ''),
                'commit' => (string) ($node['commit'] ?? ''),
                'gate_receipt' => (string) ($node['gate_receipt'] ?? ''),
            ];
        }
        $integrated = (array) ($envelope['integrated_test_result'] ?? []);

        return hash('sha256', (string) json_encode([
            'schema' => self::SCHEMA,
            'obra_id' => (string) ($envelope['obra_id'] ?? ''),
            'branch' => (string) ($envelope['branch'] ?? ''),
            'certified' => (bool) ($envelope['certified'] ?? false),
            'disposition' => (string) ($envelope['disposition'] ?? ''),
            'steps' => $stepFacts,
            'integrated' => [
                'supplied' => (bool) ($integrated['supplied'] ?? false),
                'ran' => (bool) ($integrated['ran'] ?? false),
                'passed' => (bool) ($integrated['passed'] ?? false),
                'exit_code' => $integrated['exit_code'] ?? null,
            ],
        ], JSON_UNESCAPED_SLASHES));
    }
}
