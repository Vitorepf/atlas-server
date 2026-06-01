<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Kernel Static Scans — pure, deterministic read model that turns the
 * Kernel "static scans" doctrine into an enforceable, normalized contract.
 *
 * The doc states a simple but strict law: doctrine is not accepted unless a
 * scan, test, gate or runtime guard can enforce it; static scans must prevent
 * bypasses BEFORE runtime; and compliance tests must include at least one
 * negative regression case when possible. This service does not run the scans
 * (the codebase corpus scanner does that) — it normalizes a raw scan result
 * into the documented envelope and classifies scans into the documented
 * families so CI/agents can consume a stable, machine-friendly shape.
 *
 * Contract (from the doc sections "Scan Families", "Required Scan Behavior"
 * and "Negative Regression Examples"):
 *
 *   normalizeScan(raw):
 *     Required Scan Behavior — each scan MUST surface, in order:
 *       1. stable scan id;
 *       2. pass/fail;
 *       3. violation count;
 *       4. paths and symbols involved;
 *       5. owner area;
 *       6. remediation hint;
 *       7. whether failure blocks merge.
 *     The envelope is filled deterministically: violation_count is derived
 *     from the violations list (never trusted from input), pass is the inverse
 *     of "has violations", and a failing scan in a merge-blocking family is
 *     reported as blocks_merge=true.
 *
 *   classifyFamily(scanId): maps a scan id to its documented Scan Family,
 *     what that family prevents, its owner area and whether a failure blocks
 *     merge — covering the families in the doc table including AP-201 runtime
 *     language boundary, AP-178 provider release anti-wrapper, the voice
 *     governance families (AP-185/686/687), the graph/RAG/Lens review families
 *     (AP-683/684/685) and the productive/predictive failure + worked-example
 *     privacy families (AP-168/169/170).
 *
 *   summarize(scans): aggregates normalized scans into a compliance verdict.
 *     Doctrine rule enforced: "Compliance tests must include at least one
 *     negative regression case when possible" — the summary reports
 *     negative_regression_present and, when a scan family is exercised without
 *     any negative regression, flags it as a doctrine gap (not silently green).
 *     A failing scan that blocks merge flips the overall merge gate to blocked.
 *
 * Pure: no I/O, no DB, no filesystem. Every method returns a typed array and
 * is a deterministic function of its input.
 *
 * @see docs/engineering-knowledge-base/kernel/static-scans.md
 */
final class AtlasKernelStaticScansService
{
    /** Stable evidence schema id this read model emits. */
    public const SCHEMA = 'atlas.kernel.static_scans.v1';

    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    public const MERGE_GATE_OPEN = 'open';
    public const MERGE_GATE_BLOCKED = 'blocked';

    /**
     * Documented Scan Families from the doc "Scan Families" + "Required Scan
     * Behavior" tables. Each family declares what it prevents, its owner area
     * and whether a failure blocks merge.
     *
     * blocks_merge encodes doctrine severity: structural bypasses (surface →
     * provider/context, missing Decision Receipt at runtime, provider identity,
     * runtime language boundary, the voice production promotion gate) are
     * merge-blocking; parity / drift / review-window families surface findings
     * but do not, by themselves, block merge.
     *
     * @var array<string,array{prevents:string,owner_area:string,blocks_merge:bool}>
     */
    private const FAMILIES = [
        'surface_provider_bypass' => [
            'prevents' => 'surfaces calling providers or tools directly',
            'owner_area' => 'kernel',
            'blocks_merge' => true,
        ],
        'context_bypass' => [
            'prevents' => 'surfaces building privileged context outside Context Builder',
            'owner_area' => 'kernel',
            'blocks_merge' => true,
        ],
        'receipt_propagation' => [
            'prevents' => 'runtime execution without Decision Receipt',
            'owner_area' => 'kernel',
            'blocks_merge' => true,
        ],
        'capability_parity' => [
            'prevents' => 'capability supported in one surface but missing in required peers',
            'owner_area' => 'kernel',
            'blocks_merge' => false,
        ],
        'provider_identity' => [
            'prevents' => 'provider calls without Atlas identity fragment',
            'owner_area' => 'kernel',
            'blocks_merge' => true,
        ],
        'ledger_projection_drift' => [
            'prevents' => 'read models diverging from append-only event truth',
            'owner_area' => 'kernel',
            'blocks_merge' => false,
        ],
        'inbox_proposal_parity' => [
            'prevents' => 'Curator proposals missing review surfaces',
            'owner_area' => 'curator',
            'blocks_merge' => false,
        ],
        'documentation_health' => [
            'prevents' => 'oversized or malformed docs entering canonical KB',
            'owner_area' => 'knowledge_governance',
            'blocks_merge' => false,
        ],
        'runtime_language_boundary' => [
            'prevents' => 'Python/Go/Swift scope drifting into the wrong layer through services, controllers, jobs, commands, shell, Laravel Process or Symfony Process',
            'owner_area' => 'kernel',
            'blocks_merge' => true,
        ],
        'provider_release_anti_wrapper' => [
            'prevents' => 'provider launches changing defaults, domain maturity, credentials or direct channels without review',
            'owner_area' => 'kernel',
            'blocks_merge' => false,
        ],
        'voice_runtime_certification' => [
            'prevents' => 'certification artifacts leaking tokens, raw audio, raw text, tool calls or provider secrets',
            'owner_area' => 'kernel',
            'blocks_merge' => true,
        ],
        'voice_python_runtime_boundary' => [
            'prevents' => 'Python voice runtime importing provider SDKs, executing tools/shell, persisting raw audio or bypassing the Kernel-only callback contract',
            'owner_area' => 'kernel',
            'blocks_merge' => true,
        ],
        'voice_production_promotion' => [
            'prevents' => 'voice runtime promoted to production without real SDK certification, token issuer readiness, smoke proof and human review',
            'owner_area' => 'kernel',
            'blocks_merge' => true,
        ],
        'local_rag_promotion_review' => [
            'prevents' => 'Local RAG evaluation result treated as Graph RAG/Python promotion permission',
            'owner_area' => 'architecture_operations',
            'blocks_merge' => false,
        ],
        'external_graph_harness' => [
            'prevents' => 'external graph candidates jumping into Memory, Context, Constelacao, Decide or runtime',
            'owner_area' => 'architecture_operations',
            'blocks_merge' => false,
        ],
        'constelacao_lens1_usage_review' => [
            'prevents' => 'Lente 1 promoted to Command Sky, Graph RAG, lineage or operational UI before usage review',
            'owner_area' => 'architecture_operations',
            'blocks_merge' => false,
        ],
        'productive_failure_governance' => [
            'prevents' => 'Productive Failure sessions without explicit topic, storage backing or prediction-error basis, plus random frustration',
            'owner_area' => 'cognition',
            'blocks_merge' => false,
        ],
        'personal_worked_examples_privacy' => [
            'prevents' => 'raw personal source content leaking into Ledger summaries or the extraction read model',
            'owner_area' => 'cognition',
            'blocks_merge' => true,
        ],
        'predictive_failure_governance' => [
            'prevents' => 'predictive failure without opt-in, specific target, calibration/safety gates or outcome tracking, plus random frustration',
            'owner_area' => 'cognition',
            'blocks_merge' => false,
        ],
    ];

    /** Family used when a scan id is not in the documented registry. */
    private const UNKNOWN_FAMILY = 'unknown';

    /**
     * Required Scan Behavior — normalize a raw scan result into the documented
     * 8-field envelope. The envelope is a deterministic function of the input:
     * violation_count is recomputed from the violations list (input is never
     * trusted to self-report its count) and pass/fail is derived from whether
     * any violation exists.
     *
     * @param  array{
     *     scan_id?:string,
     *     family?:string,
     *     violations?:array<int,string>,
     *     paths?:array<int,string>,
     *     symbols?:array<int,string>,
     *     remediation?:string,
     *     owner_area?:string
     * }  $raw
     * @return array{
     *     scan_id:string,
     *     status:string,
     *     pass:bool,
     *     violation_count:int,
     *     family:string,
     *     prevents:string,
     *     paths:array<int,string>,
     *     symbols:array<int,string>,
     *     owner_area:string,
     *     remediation_hint:string,
     *     blocks_merge:bool,
     *     contract_complete:bool
     * }
     */
    public function normalizeScan(array $raw): array
    {
        $scanId = $this->cleanString($raw['scan_id'] ?? '');
        if ($scanId === '') {
            $scanId = 'unknown_scan';
        }

        $family = $this->cleanString($raw['family'] ?? '');
        if ($family === '') {
            $family = $this->familyForScanId($scanId);
        }
        $familyMeta = self::FAMILIES[$family] ?? null;

        $violations = $this->cleanList($raw['violations'] ?? []);
        $violationCount = count($violations);
        $pass = $violationCount === 0;

        $paths = $this->cleanList($raw['paths'] ?? []);
        $symbols = $this->cleanList($raw['symbols'] ?? []);

        $ownerArea = $this->cleanString($raw['owner_area'] ?? '');
        if ($ownerArea === '') {
            $ownerArea = $familyMeta['owner_area'] ?? 'kernel';
        }

        $remediation = $this->cleanString($raw['remediation'] ?? '');
        if ($remediation === '') {
            $remediation = $familyMeta !== null
                ? 'Resolve violations so this scan prevents: '.$familyMeta['prevents'].'.'
                : 'Resolve reported violations and re-run the static scan.';
        }

        // A scan only "blocks merge" when it is FAILING and belongs to a
        // merge-blocking family. A passing scan never blocks merge.
        $familyBlocks = $familyMeta['blocks_merge'] ?? false;
        $blocksMerge = (! $pass) && $familyBlocks;

        // The documented contract is complete only when every required field is
        // surfaced. A failing scan additionally requires a remediation hint and
        // at least one path or symbol to be actionable per the doc.
        $contractComplete = $scanId !== 'unknown_scan'
            && $family !== self::UNKNOWN_FAMILY
            && ($pass || ($remediation !== '' && ($paths !== [] || $symbols !== [])));

        return [
            'scan_id' => $scanId,
            'status' => $pass ? self::STATUS_PASS : self::STATUS_FAIL,
            'pass' => $pass,
            'violation_count' => $violationCount,
            'family' => $family,
            'prevents' => $familyMeta['prevents'] ?? 'unknown doctrine',
            'paths' => $paths,
            'symbols' => $symbols,
            'owner_area' => $ownerArea,
            'remediation_hint' => $remediation,
            'blocks_merge' => $blocksMerge,
            'contract_complete' => $contractComplete,
        ];
    }

    /**
     * Classify a scan id into its documented Scan Family, returning what the
     * family prevents, its owner area and whether failure blocks merge.
     *
     * @return array{
     *     scan_id:string,
     *     family:string,
     *     known:bool,
     *     prevents:string,
     *     owner_area:string,
     *     blocks_merge:bool
     * }
     */
    public function classifyFamily(string $scanId): array
    {
        $scanId = $this->cleanString($scanId);
        $family = $this->familyForScanId($scanId);
        $known = $family !== self::UNKNOWN_FAMILY;
        $meta = self::FAMILIES[$family] ?? null;

        return [
            'scan_id' => $scanId,
            'family' => $family,
            'known' => $known,
            'prevents' => $meta['prevents'] ?? 'unknown doctrine',
            'owner_area' => $meta['owner_area'] ?? 'kernel',
            'blocks_merge' => $meta['blocks_merge'] ?? false,
        ];
    }

    /**
     * Aggregate raw scan results into a compliance verdict.
     *
     * Doctrine enforced here:
     *  - "Static scans prevent bypasses before runtime": any failing scan in a
     *    merge-blocking family flips merge_gate to blocked.
     *  - "Compliance tests must include at least one negative regression case
     *    when possible": the summary reports whether a negative regression was
     *    exercised; absence is flagged as a doctrine gap, never silently green.
     *
     * @param  array<int,array<string,mixed>>  $rawScans
     * @param  bool  $negativeRegressionPresent  whether the suite exercised at
     *     least one negative regression case (a scan that intentionally fails).
     * @return array{
     *     schema:string,
     *     total_count:int,
     *     passed_count:int,
     *     failed_count:int,
     *     violation_count:int,
     *     blocking_failures:array<int,string>,
     *     merge_gate:string,
     *     compliant:bool,
     *     negative_regression_present:bool,
     *     doctrine_gaps:array<int,string>,
     *     scans:array<int,array<string,mixed>>
     * }
     */
    public function summarize(array $rawScans, bool $negativeRegressionPresent = false): array
    {
        $scans = [];
        $passed = 0;
        $failed = 0;
        $violationCount = 0;
        $blocking = [];

        foreach ($rawScans as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            /** @var array<string,mixed> $raw */
            $scan = $this->normalizeScan($raw);
            $scans[] = $scan;
            $violationCount += $scan['violation_count'];

            if ($scan['pass']) {
                $passed++;
            } else {
                $failed++;
                if ($scan['blocks_merge']) {
                    $blocking[] = $scan['scan_id'];
                }
            }
        }

        $doctrineGaps = [];

        // Doctrine: a non-empty scan run without any negative regression is a
        // gap — the suite cannot prove it would catch a real bypass.
        if ($scans !== [] && ! $negativeRegressionPresent) {
            $doctrineGaps[] = 'no_negative_regression_case';
        }

        // Doctrine: an incomplete scan envelope cannot enforce anything.
        foreach ($scans as $scan) {
            if (! $scan['contract_complete']) {
                $doctrineGaps[] = 'incomplete_scan_contract:'.$scan['scan_id'];
            }
        }

        $mergeGate = $blocking === [] ? self::MERGE_GATE_OPEN : self::MERGE_GATE_BLOCKED;

        // Compliant only when nothing blocks merge AND there are no doctrine
        // gaps (so the result is both green and trustworthy).
        $compliant = $mergeGate === self::MERGE_GATE_OPEN && $doctrineGaps === [];

        return [
            'schema' => self::SCHEMA,
            'total_count' => count($scans),
            'passed_count' => $passed,
            'failed_count' => $failed,
            'violation_count' => $violationCount,
            'blocking_failures' => array_values($blocking),
            'merge_gate' => $mergeGate,
            'compliant' => $compliant,
            'negative_regression_present' => $negativeRegressionPresent,
            'doctrine_gaps' => array_values(array_unique($doctrineGaps)),
            'scans' => $scans,
        ];
    }

    /**
     * The documented Scan Families registry, exposed as evidence.
     *
     * @return array<string,array{prevents:string,owner_area:string,blocks_merge:bool}>
     */
    public function families(): array
    {
        return self::FAMILIES;
    }

    /**
     * Map a scan id to its documented family. Matches both the canonical family
     * key and the AP-numbered scan ids the corpus scanner emits (e.g.
     * `ap201_runtime_language_boundary_contract` → runtime_language_boundary).
     */
    private function familyForScanId(string $scanId): string
    {
        $id = strtolower($this->cleanString($scanId));
        if ($id === '') {
            return self::UNKNOWN_FAMILY;
        }

        // Direct family key (already canonical).
        if (isset(self::FAMILIES[$id])) {
            return $id;
        }

        // AP-numbered scan ids → family, by documented substring markers.
        foreach (self::AP_MARKERS as $marker => $family) {
            if (str_contains($id, $marker)) {
                return $family;
            }
        }

        return self::UNKNOWN_FAMILY;
    }

    /**
     * Substring markers that map AP-numbered scan ids onto documented families.
     * Order matters: more specific markers are listed before broader ones.
     *
     * @var array<string,string>
     */
    private const AP_MARKERS = [
        'runtime_language_boundary' => 'runtime_language_boundary',
        'ap201' => 'runtime_language_boundary',
        'provider_release_anti_wrapper' => 'provider_release_anti_wrapper',
        'ap178' => 'provider_release_anti_wrapper',
        'voice_realtime_python_runtime_boundary' => 'voice_python_runtime_boundary',
        'ap686' => 'voice_python_runtime_boundary',
        'voice_realtime_production_promotion' => 'voice_production_promotion',
        'ap687' => 'voice_production_promotion',
        'voice_realtime_runtime_certification' => 'voice_runtime_certification',
        'ap185' => 'voice_runtime_certification',
        'local_rag_graph_promotion' => 'local_rag_promotion_review',
        'ap683' => 'local_rag_promotion_review',
        'external_graph_harness' => 'external_graph_harness',
        'ap684' => 'external_graph_harness',
        'constelacao_lens1_usage_review' => 'constelacao_lens1_usage_review',
        'ap685' => 'constelacao_lens1_usage_review',
        'productive_failure_governance' => 'productive_failure_governance',
        'ap168' => 'productive_failure_governance',
        'personal_worked_example_privacy' => 'personal_worked_examples_privacy',
        'ap169' => 'personal_worked_examples_privacy',
        'predictive_failure_governance' => 'predictive_failure_governance',
        'ap170' => 'predictive_failure_governance',
        'surface_provider_bypass' => 'surface_provider_bypass',
        'ap1_' => 'surface_provider_bypass',
        'surface_context_bypass' => 'context_bypass',
        'ap2_' => 'context_bypass',
        'decision_receipt' => 'receipt_propagation',
        'provider_driver_identity' => 'provider_identity',
        'ap12_' => 'provider_identity',
        'surface_capability_parity' => 'capability_parity',
        'capability_surface_coverage' => 'capability_parity',
        'ledger_projection' => 'ledger_projection_drift',
        'proposal_inbox' => 'inbox_proposal_parity',
        'documentation_health' => 'documentation_health',
        'docs_health' => 'documentation_health',
    ];

    private function cleanString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return array<int,string>
     */
    private function cleanList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $clean = $this->cleanString($item);
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return array_values(array_unique($out));
    }
}
