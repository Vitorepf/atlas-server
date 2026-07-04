<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel mechanism: THE sovereign honesty floor. The single implementation of
 * AcceptanceGate every surface routes through. Always-on invariants, identical for all three
 * trust levels; each threshold is max(sovereign_piso, config) so the operator can only TIGHTEN it,
 * never loosen it below the piso. Fail-closed.
 *
 * Owns: evaluating a bundle's REAL evidence against the invariants and sealing promote iff all
 * pass. Refusing fake-green (lint-as-suite, fixed smoke, claim-without-execution) is invariant #1.
 * Must never own: gathering the evidence (adapters do that) or acting on the verdict (MergeActuator).
 * trust_level is read ONLY to stamp the witness-set — never to change what is required.
 */
final class SovereignHonestyFloor implements AcceptanceGate
{
    /** Bumped whenever the invariant set or a piso changes — sealed into every receipt for provenance. */
    public const FLOOR_VERSION = 'atlas.engineering_kernel.sovereign_floor.v1';

    /** The non-overridable sovereign pisos. config() may raise these, never lower them. */
    public const SOVEREIGN_MUTATION_FLOOR = 0.6;

    public const SOVEREIGN_CONTEXT_FLOOR = 80;

    public const MIN_JUDGE_FAMILIES = 2;

    /** Signature of the legacy fake-green kernel's fixed smoke artifact — a hard tell of a lint-as-suite lie. */
    public const FIXED_SMOKE_SIGNATURE = 'atlas_real_execution_smoke';

    /** Reserved non-functional slots: pass until their detector ships (Obra #3+), never block today. */
    private const RESERVED_SLOTS = [
        'performance_budget',
        'migration_safety',
        'architecture_no_regression',
        'property_clean_for_tagged',
    ];

    public function __construct(
        private readonly float $configMutationFloor = 0.0,
        private readonly int $configContextFloor = 0,
    ) {}

    public static function fromConfig(): self
    {
        // Fail-safe to the sovereign piso if config is unavailable (e.g. outside a bootstrapped app):
        // never weaker than the piso, so an unread config can only leave the floor at its strongest.
        try {
            return new self(
                configMutationFloor: (float) config('atlas.loop.mutation_kill_ratio_floor', 0.0),
                configContextFloor: (int) config('atlas.loop.context_sufficiency_floor', 0),
            );
        } catch (\Throwable) {
            return new self();
        }
    }

    /** max(piso, config): config can only tighten. This is what makes the floor non-overridable. */
    public function effectiveMutationFloor(): float
    {
        return max(self::SOVEREIGN_MUTATION_FLOOR, $this->configMutationFloor);
    }

    public function effectiveContextFloor(): int
    {
        return max(self::SOVEREIGN_CONTEXT_FLOOR, $this->configContextFloor);
    }

    public function certify(AcceptanceBundle $bundle, TrustLevel $trust): CertVerdict
    {
        // Invariants are computed WITHOUT reading $trust — bar(dev)=bar(forge)=bar(autonomos).
        $invariants = [
            'false_claim_blocked' => $this->falseClaimBlocked($bundle),
            'context_sufficiency' => $this->contextSufficiency($bundle),
            'mutation_kill_ratio' => $this->mutationKillRatio($bundle),
            'changed_public_symbol_census' => $this->changedPublicSymbolCensus($bundle),
            'security_free' => $this->securityFree($bundle),
            'criteria_hash_frozen' => $this->criteriaHashFrozen($bundle),
            'judge_diversity' => $this->judgeDiversity($bundle),
        ];

        foreach (self::RESERVED_SLOTS as $slot) {
            $invariants[$slot] = ['status' => 'pass', 'detail' => 'reserved_slot_pass_until_detector_exists'];
        }

        $blockers = [];
        foreach ($invariants as $id => $result) {
            if ($result['status'] !== 'pass') {
                $blockers[] = $id;
            }
        }

        $witnessSet = $trust->witnessSet();

        $verdict = $blockers === []
            ? CertVerdict::promote($invariants, $witnessSet)
            : CertVerdict::refuse($blockers, $invariants, $witnessSet);

        // Seal an auditable receipt (Slice 7): witness-set + floor version + hashes, content-addressed.
        $receipt = SovereignReceipt::seal($verdict, $bundle, $trust, $this->effectiveMutationFloor());

        return $verdict->withReceiptRef((string) $receipt['receipt_hash']);
    }

    /**
     * The full sealed receipt for a bundle+trust — for the ReceiptLedger / audit surface.
     *
     * @return array<string,mixed>
     */
    public function receiptFor(AcceptanceBundle $bundle, TrustLevel $trust): array
    {
        return SovereignReceipt::seal($this->certify($bundle, $trust), $bundle, $trust, $this->effectiveMutationFloor());
    }

    /**
     * Invariant #1 — the anti-fake-green core: no "passed" without evidence of a REAL test run.
     *
     * @return array{status:string,detail:string}
     */
    private function falseClaimBlocked(AcceptanceBundle $bundle): array
    {
        $e = $bundle->execution;
        $claimsPass = $e->claimedStatus === 'passed';

        // A claimed pass with zero tests or zero assertions is a lie, full stop.
        if ($claimsPass && $e->testsRun < 1) {
            return $this->fail('claimed_pass_with_zero_tests_run');
        }
        if ($claimsPass && $e->assertionsExecuted < 1) {
            return $this->fail('claimed_pass_with_zero_assertions');
        }

        // The fixed smoke artifact is the legacy fake-green kernel's fingerprint.
        foreach ($e->artifacts as $artifact) {
            if (str_contains($artifact, self::FIXED_SMOKE_SIGNATURE)) {
                return $this->fail('fixed_smoke_artifact_is_not_a_test_run');
            }
        }

        // If it claims a suite (selected_tests) but actually only ran a lint, that is the exact
        // "php -l as suite" lie the legacy fake-green kernel commits.
        $ranALint = false;
        $ranATestRunner = false;
        foreach ($e->commands as $cmd) {
            if ($this->isLintCommand($cmd)) {
                $ranALint = true;
            }
            if ($this->isTestRunnerCommand($cmd)) {
                $ranATestRunner = true;
            }
        }
        if ($claimsPass && $ranALint && ! $ranATestRunner) {
            return $this->fail('lint_only_run_presented_as_suite');
        }
        if ($claimsPass && $e->selectedTests !== [] && ! $ranATestRunner) {
            return $this->fail('claimed_suite_without_running_a_test_runner');
        }

        return $this->pass('real_execution_evidence_present');
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function contextSufficiency(AcceptanceBundle $bundle): array
    {
        $floor = $this->effectiveContextFloor();

        return $bundle->contextSufficiency >= $floor
            ? $this->pass("context_sufficiency {$bundle->contextSufficiency} >= {$floor}")
            : $this->fail("context_sufficiency {$bundle->contextSufficiency} < {$floor}");
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function mutationKillRatio(AcceptanceBundle $bundle): array
    {
        $floor = $this->effectiveMutationFloor();
        $report = $bundle->mutationReport;
        // A change that adds no decision surface has nothing to mutate — waive, but note it.
        $surfaceAdded = (bool) ($report['decision_surface_added'] ?? true);
        if (! $surfaceAdded) {
            return $this->pass('no_decision_surface_added_mutation_waived');
        }

        if (! array_key_exists('kill_ratio', $report)) {
            return $this->fail('decision_surface_added_but_no_mutation_report');
        }

        $killRatio = (float) $report['kill_ratio'];
        $mutants = (int) ($report['mutants_generated'] ?? 0);
        if ($mutants < 1) {
            return $this->fail('mutation_claimed_but_zero_mutants_generated');
        }
        if ($killRatio < $floor) {
            return $this->fail("mutation_kill_ratio {$killRatio} < effective_floor {$floor}");
        }

        return $this->pass("mutation_kill_ratio {$killRatio} >= effective_floor {$floor}");
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function changedPublicSymbolCensus(AcceptanceBundle $bundle): array
    {
        $uncovered = [];
        foreach ($bundle->changedPublicSymbols as $entry) {
            $hasCriterion = (bool) ($entry['has_criterion'] ?? false);
            $hasTest = (bool) ($entry['has_test'] ?? false);
            if (! $hasCriterion || ! $hasTest) {
                $uncovered[] = (string) ($entry['symbol'] ?? 'unknown');
            }
        }

        return $uncovered === []
            ? $this->pass('every_changed_public_symbol_has_criterion_and_test')
            : $this->fail('uncovered_public_symbols:'.implode(',', $uncovered));
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function securityFree(AcceptanceBundle $bundle): array
    {
        $scan = $bundle->securityScan;
        if (($scan['ran'] ?? false) !== true) {
            return $this->fail('security_scan_did_not_run');
        }
        if (($scan['secret_free'] ?? false) !== true) {
            return $this->fail('secret_detected');
        }
        if ((int) ($scan['critical_sast'] ?? 0) > 0) {
            return $this->fail('critical_sast_finding');
        }
        if ((int) ($scan['critical_cve'] ?? 0) > 0) {
            return $this->fail('critical_cve_finding');
        }

        return $this->pass('secret_free_and_no_critical_sast_or_cve');
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function criteriaHashFrozen(AcceptanceBundle $bundle): array
    {
        if ($bundle->criteriaHash === '' || $bundle->frozenHash === '') {
            return $this->fail('criteria_or_frozen_hash_missing');
        }
        if ($bundle->criteriaHash !== $bundle->frozenHash) {
            return $this->fail('criteria_drift:certified_suite_differs_from_frozen');
        }

        // PROOF OF BINDING: when the raw criteria are carried, the hash must be RECOMPUTED from them —
        // otherwise any two identical strings would pass this invariant (the hole Obra #2 found).
        if ($bundle->criteria !== []) {
            $recomputed = CriteriaCanonicalizer::hash($bundle->criteria);

            return hash_equals($recomputed, $bundle->frozenHash)
                ? $this->pass('frozen_hash_provably_binds_the_certified_criteria')
                : $this->fail('frozen_hash_not_bound_to_criteria');
        }

        // ponytail: legacy weak path (hash-equality only). Fully closes once every caller carries raw criteria.
        return $this->pass('certified_suite_is_the_frozen_suite');
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function judgeDiversity(AcceptanceBundle $bundle): array
    {
        $families = [];
        foreach ($bundle->judges as $judge) {
            if (($judge['approved'] ?? false) === true) {
                $families[(string) ($judge['provider_family'] ?? '')] = true;
            }
        }
        unset($families['']);
        $distinct = count($families);

        return $distinct >= self::MIN_JUDGE_FAMILIES
            ? $this->pass("distinct_approving_provider_families {$distinct} >= ".self::MIN_JUDGE_FAMILIES)
            : $this->fail("distinct_approving_provider_families {$distinct} < ".self::MIN_JUDGE_FAMILIES);
    }

    private function isLintCommand(string $cmd): bool
    {
        return (bool) preg_match('/(^|\s)php\s+-l(\s|$)/', $cmd);
    }

    private function isTestRunnerCommand(string $cmd): bool
    {
        $needle = strtolower($cmd);

        return str_contains($needle, 'phpunit')
            || str_contains($needle, 'artisan test')
            || str_contains($needle, 'paratest')
            || str_contains($needle, 'pest');
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function pass(string $detail): array
    {
        return ['status' => 'pass', 'detail' => $detail];
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function fail(string $detail): array
    {
        return ['status' => 'fail', 'detail' => $detail];
    }
}
