<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\Obra\AtlasObraReceiptStamp;
use Throwable;

/**
 * L4-10 REAL-EXECUTION proof for a LOOP MULTI-FILE REFACTOR obra — the honest gate the obra bridge
 * consults before a coordinated refactor can be packaged for operator review.
 *
 * WHY a sibling of {@see \App\Services\Ai\Programming\AtlasForgeMultiNodeL410ProofService}: that one
 * is hard-wired to the L4-6 morning-digest deliverable (ITEM_ID='L4-6', a fixed 6–10 node count, a
 * fixed material-file set, the digest command). A 2-file refactor obra has node_count=2, no L4-6
 * files, and runs phpunit — so it ALWAYS reports `real_execution_blocked` there. This proof keeps the
 * SAME report() contract ({certified, status, blockers}) but validates GENERIC refactor facts.
 *
 * UNGAMEABLE BY CONSTRUCTION: it trusts ONLY the fields sealed inside the executor's HMAC receipt
 * ({@see AtlasObraReceiptStamp}). The outer evidence envelope is unsigned and ignored for the verdict.
 * A FIXTURE run is sealed execution_mode='fixture_obra_run' and is REJECTED — a deterministic stub can
 * never mint a "real" L4-10. A hand-edit of any sealed fact (status/certified/provider/execution_mode/
 * node_count/delivered_files/...) breaks the signature → verify=false → blocked. Fail-closed at every
 * missing/odd input. No provider call, no DB, no mutation — a pure read+verify.
 */
final class AtlasLoopRefactorObraL410ProofService
{
    public const SCHEMA_VERSION = 'atlas.loop.refactor_obra_l4_10.v1';

    /** The ONLY execution mode that proves a real provider run produced this obra (fixtures excluded). */
    private const REQUIRED_EXECUTION_MODE = 'real_provider_obra_run';

    private const REQUIRED_PROVIDER = 'hermes_cli';

    /** A refactor obra is >=2 nodes (one per allowed file); generic, NOT the L4-6 6–10 band. */
    private const MIN_NODE_COUNT = 2;

    private const DONE_STATUSES = ['done', 'completed', 'certified', 'success', 'succeeded'];

    public function __construct(
        private readonly ?AtlasObraReceiptStamp $receiptStamp = null,
    ) {}

    /**
     * @param  array{evidence_path?:string|null, allowed_files?:list<string>, hours?:int}  $options
     * @return array{schema_version:string, status:string, certified:bool, evidence:array<string,mixed>, blockers:list<string>}
     */
    public function report(array $options = []): array
    {
        $allowed = $this->normPaths($options['allowed_files'] ?? []);
        $verdict = $this->validate($options['evidence_path'] ?? null, $allowed);

        $certified = (bool) ($verdict['certified'] ?? false);
        $blockers = $certified ? [] : array_values(array_unique((array) ($verdict['blockers'] ?? [])));
        $status = match (true) {
            $certified => 'certified',
            ($verdict['loaded'] ?? false) === true => 'real_execution_evidence_rejected',
            default => 'real_execution_blocked',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'certified' => $certified,
            'evidence' => [
                'execution_mode' => $verdict['execution_mode'] ?? null,
                'provider' => $verdict['provider'] ?? null,
                'model' => $verdict['model'] ?? null,
                'node_count' => $verdict['node_count'] ?? null,
                'delivered_nodes' => $verdict['delivered_nodes'] ?? null,
                'delivered_files' => $verdict['delivered_files'] ?? [],
                'allowed_files' => $allowed,
                'hmac_verified' => $verdict['hmac_verified'] ?? false,
            ],
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string,mixed>
     */
    private function validate(?string $evidencePath, array $allowed): array
    {
        $path = is_string($evidencePath) ? trim($evidencePath) : '';
        if ($path === '' || ! is_file($path)) {
            return ['certified' => false, 'loaded' => false, 'blockers' => ['l4_10_evidence_path_missing']];
        }

        try {
            $raw = (string) @file_get_contents($path);
            /** @var array<string,mixed>|null $env */
            $env = json_decode($raw, true);
        } catch (Throwable) {
            $env = null;
        }
        if (! is_array($env)) {
            return ['certified' => false, 'loaded' => false, 'blockers' => ['l4_10_evidence_unreadable']];
        }

        // The verdict rests SOLELY on the HMAC-sealed executor receipt — the outer envelope is unsigned.
        $receipt = is_array($env['executor_receipt'] ?? null) ? (array) $env['executor_receipt'] : [];
        if ($receipt === []) {
            return ['certified' => false, 'loaded' => true, 'blockers' => ['executor_receipt_missing']];
        }

        $stamp = $this->receiptStamp ?? new AtlasObraReceiptStamp();
        $sig = $stamp->verify($receipt);
        if (($sig['verified'] ?? false) !== true) {
            return ['certified' => false, 'loaded' => true, 'hmac_verified' => false, 'blockers' => ['executor_receipt_signature_invalid:'.(string) ($sig['reason'] ?? '?')]];
        }

        // Read the SEALED facts (everything below is signature-protected).
        $status = (string) ($receipt['status'] ?? '');
        $executionMode = (string) ($receipt['execution_mode'] ?? '');
        $provider = (string) ($receipt['provider'] ?? '');
        $model = (string) ($receipt['model'] ?? '');
        $nodeCount = (int) ($receipt['node_count'] ?? 0);
        $deliveredNodes = (int) ($receipt['delivered_nodes'] ?? 0);
        $deliveredFiles = $this->normPaths($receipt['delivered_files'] ?? []);

        $base = [
            'loaded' => true, 'hmac_verified' => true, 'execution_mode' => $executionMode,
            'provider' => $provider, 'model' => $model, 'node_count' => $nodeCount,
            'delivered_nodes' => $deliveredNodes, 'delivered_files' => $deliveredFiles,
        ];

        $blockers = [];
        // THE anti-fixture lock: only a real provider run earns the proof. A fixture obra is sealed
        // 'fixture_obra_run' and is rejected here — deterministic stubs can never mint a real L4-10.
        if ($executionMode !== self::REQUIRED_EXECUTION_MODE) {
            $blockers[] = 'not_a_real_provider_run:'.($executionMode !== '' ? $executionMode : 'absent');
        }
        if (! in_array($status, self::DONE_STATUSES, true)) {
            $blockers[] = 'obra_not_done:'.($status !== '' ? $status : 'absent');
        }
        if (($receipt['certified'] ?? false) !== true) {
            $blockers[] = 'obra_not_certified';
        }
        if (($receipt['main_untouched'] ?? false) !== true) {
            $blockers[] = 'main_not_untouched';
        }
        if (($receipt['never_merged'] ?? true) !== true) {
            $blockers[] = 'already_merged';
        }
        if ($provider !== self::REQUIRED_PROVIDER) {
            $blockers[] = 'provider_not_'.self::REQUIRED_PROVIDER.':'.($provider !== '' ? $provider : 'absent');
        }
        if (! str_contains(mb_strtolower($model), 'gpt-5.5')) {
            $blockers[] = 'model_not_gpt_5_5:'.($model !== '' ? $model : 'absent');
        }
        if ($nodeCount < self::MIN_NODE_COUNT) {
            $blockers[] = 'node_count_below_min:'.$nodeCount;
        }
        if ($deliveredNodes < 1 || $deliveredNodes !== $nodeCount) {
            $blockers[] = 'not_all_nodes_delivered:'.$deliveredNodes.'/'.$nodeCount;
        }
        if ($deliveredFiles === []) {
            $blockers[] = 'no_delivered_files';
        }
        // SCOPE: every delivered file must be within the obra's allowed set (when supplied) — a real
        // obra that touched a file outside its cluster is not a clean refactor proof.
        if ($allowed !== []) {
            $outside = array_values(array_diff($deliveredFiles, $allowed));
            if ($outside !== []) {
                $blockers[] = 'delivered_files_outside_allowed:'.implode(',', array_slice($outside, 0, 5));
            }
        }

        return $base + ['certified' => $blockers === [], 'blockers' => $blockers];
    }

    /**
     * @param  mixed  $paths
     * @return list<string>
     */
    private function normPaths($paths): array
    {
        $out = [];
        foreach ((array) $paths as $p) {
            if (is_string($p) && trim($p) !== '') {
                $out[ltrim(trim($p), '/')] = true;
            }
        }

        return array_keys($out);
    }
}
