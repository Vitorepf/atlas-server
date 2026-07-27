<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\AcceptanceBundle;
use App\Services\Ai\EngineeringKernel\AcceptanceGate;
use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\NonFunctional\ArchitectureRegressionProbe;
use App\Services\Ai\EngineeringKernel\NonFunctional\MigrationSafetyProbe;
use App\Services\Ai\EngineeringKernel\OutcomeProofGate;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\EngineeringKernel\VerificationCourtAcceptanceGate;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationScoreVerdict;

/**
 * Engineering Kernel adapter: promotes the REAL AtlasDev per-delivery machinery — the mutation
 * score verdict and the engineering quality (security) scan — into an AcceptanceBundle and routes
 * it through the sovereign floor. Strangler adapter: the surface stops owning the accept decision;
 * the sovereign gate does.
 *
 * Owns: translating real tool outputs (MutationScoreVerdict, EngineeringQualityScanService::scan())
 * into the bundle shape the floor evaluates, and delegating the decision to the floor.
 * Must never own: the acceptance invariants (SovereignHonestyFloor) or running the tools (the
 * pipeline runs them; this adapter maps their results).
 */
final class AtlasDevGateAdapter implements AcceptanceGate
{
    /** Which security tool a blocking finding belongs to → which floor bucket it hits. */
    private const SECRET_TOOLS = ['gitleaks'];

    private const CVE_TOOLS = ['osv_scanner', 'grype'];

    public function __construct(
        private readonly SovereignHonestyFloor $floor = new SovereignHonestyFloor,
        private readonly OutcomeProofGate $outcomeProof = new OutcomeProofGate,
    ) {}

    /** Pass-through so the adapter itself can be bound as the AcceptanceGate for a surface. */
    public function certify(AcceptanceBundle $bundle, TrustLevel $trust): CertVerdict
    {
        if (array_key_exists('verification_court', $bundle->nonFunctional)) {
            return (new VerificationCourtAcceptanceGate($this->floor))->certify($bundle, $trust);
        }

        return $this->floor->certify($bundle, $trust)->withInvariant(
            'verification_court_migration', 'observe', 'legacy_bundle_without_quality_foundry_court_facts',
        );
    }

    /**
     * Strangler entry: build a bundle from a real Dev delivery's evidence and certify it.
     *
     * @param  array<string,mixed>  $evidence
     */
    public function certifyDevDelivery(array $evidence, TrustLevel $trust = TrustLevel::Dev): CertVerdict
    {
        // Obra 2 / DEV-02: OutcomeProofGate pre-check shares FalseClaimInvariant with the floor.
        $execution = (array) ($evidence['execution'] ?? []);
        if ($execution !== []) {
            $status = (string) ($evidence['status'] ?? 'success');
            $proof = $this->outcomeProof->assess($status, $execution);
            if (($proof['fake_green'] ?? false) === true) {
                $evidence['judges'] = array_values(array_merge(
                    (array) ($evidence['judges'] ?? []),
                    [[
                        'family' => 'outcome_proof',
                        'verdict' => 'fake_green',
                        'reason' => (string) ($proof['reason'] ?? 'fake_green'),
                    ]],
                ));
            }
        }

        return $this->certify($this->bundleFromDevEvidence($evidence), $trust);
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    public function bundleFromDevEvidence(array $evidence): AcceptanceBundle
    {
        $mutationVerdict = $evidence['mutation_verdict'] ?? null;
        $mutationReport = $mutationVerdict instanceof MutationScoreVerdict
            ? self::mutationReportFromVerdict($mutationVerdict, (int) ($evidence['mutants_generated'] ?? 0))
            : (array) ($evidence['mutation_report'] ?? []);

        $scanResult = $evidence['scan_result'] ?? null;
        $securityScan = is_array($scanResult)
            ? self::securityFromScan($scanResult)
            : (array) ($evidence['security_scan'] ?? []);

        return AcceptanceBundle::fromArray([
            'criteria_hash' => $evidence['criteria_hash'] ?? '',
            'frozen_hash' => $evidence['frozen_hash'] ?? '',
            'changed_files' => $evidence['changed_files'] ?? [],
            'changed_public_symbols' => $evidence['changed_public_symbols'] ?? [],
            'execution' => $evidence['execution'] ?? [],
            'mutation_report' => $mutationReport,
            'security_scan' => $securityScan,
            'judges' => $evidence['judges'] ?? [],
            'context_sufficiency' => $evidence['context_sufficiency'] ?? 0,
            'non_functional' => self::nonFunctionalFromEvidence($evidence),
            'repair' => (array) ($evidence['repair'] ?? []),
        ]);
    }

    /**
     * Obra #3 — build the non-functional evidence: run the deterministic probes on raw inputs
     * (migration sources, import edges) and merge over any surface-provided report. The probes win
     * for their slot because they are COMPUTED from the diff, not self-reported.
     *
     * @param  array<string,mixed>  $evidence
     * @return array<string,array<string,mixed>>
     */
    public static function nonFunctionalFromEvidence(array $evidence): array
    {
        $nf = (array) ($evidence['non_functional'] ?? []);

        $migrationSources = $evidence['migration_sources'] ?? null;
        if (is_array($migrationSources)) {
            $nf['migration_safety'] = MigrationSafetyProbe::probe(array_map('strval', $migrationSources));
        }

        $importEdges = $evidence['import_edges'] ?? null;
        if (is_array($importEdges)) {
            $nf['architecture_no_regression'] = [
                'violations' => ArchitectureRegressionProbe::violations(array_values($importEdges)),
            ];
        }

        return $nf;
    }

    /**
     * Map the REAL mutation verdict into the floor's mutation_report. A no-op verdict (off/skip/
     * empty-scope) means no decision surface was added — the floor waives it. The real reported
     * MSI becomes the kill_ratio the floor compares against its sovereign 0.6 piso.
     *
     * @return array{kill_ratio:float,mutants_generated:int,decision_surface_added:bool}
     */
    public static function mutationReportFromVerdict(MutationScoreVerdict $verdict, int $mutantsGenerated): array
    {
        return [
            'kill_ratio' => $verdict->msi ?? 0.0,
            'mutants_generated' => $mutantsGenerated,
            'decision_surface_added' => ! $verdict->isNoOp,
        ];
    }

    /**
     * Map the REAL EngineeringQualityScanService::scan() output into the floor's security_scan.
     * Blocked (AWIS gate refused) => did not run => fail-closed at the floor. Blocking security
     * findings split into secret / sast / cve buckets by tool.
     *
     * @param  array<string,mixed>  $scanResult
     * @return array{ran:bool,secret_free:bool,critical_sast:int,critical_cve:int}
     */
    public static function securityFromScan(array $scanResult): array
    {
        $status = (string) ($scanResult['status'] ?? 'blocked');

        // The aggregate status is NOT enough to prove a security scan ran. A tool
        // that is not installed becomes skippedTool(..., 'tool_missing') with
        // status='skipped', and EngineeringQualityScanService computes
        //   status = failed_count > 0 || timeout_count > 0 || blocking_finding_count > 0
        // which never counts skipped. So a machine with no gitleaks / semgrep /
        // trivy / osv-scanner / grype produces an all-skipped scan that reports
        // 'passed', and reading only that gives ran=true, secret_free=true,
        // critical_sast=0 — a green security invariant with zero scanners run.
        // That is the case on this machine today: none of the five is installed.
        //
        // Require at least one SECURITY-category tool to have actually executed.
        $securityToolRan = false;
        foreach ((array) ($scanResult['tools'] ?? []) as $tool) {
            if (! is_array($tool) || ($tool['category'] ?? '') !== 'security') {
                continue;
            }
            if (in_array((string) ($tool['status'] ?? ''), ['passed', 'failed'], true)) {
                $securityToolRan = true;
                break;
            }
        }
        $ran = in_array($status, ['passed', 'failed'], true) && $securityToolRan;

        $secretHits = 0;
        $sast = 0;
        $cve = 0;
        foreach ((array) ($scanResult['findings'] ?? []) as $finding) {
            if (! is_array($finding) || ($finding['blocks_resolved'] ?? false) !== true) {
                continue;
            }
            if (($finding['category'] ?? 'quality') !== 'security') {
                continue;
            }
            $tool = strtolower((string) ($finding['tool'] ?? ''));
            if (in_array($tool, self::SECRET_TOOLS, true) || str_contains((string) ($finding['rule_id'] ?? ''), 'secret')) {
                $secretHits++;
            } elseif (in_array($tool, self::CVE_TOOLS, true)) {
                $cve++;
            } else { // semgrep, trivy, and any other blocking security finding => SAST bucket
                $sast++;
            }
        }

        return [
            'ran' => $ran,
            'secret_free' => $secretHits === 0,
            'critical_sast' => $sast,
            'critical_cve' => $cve,
        ];
    }
}
