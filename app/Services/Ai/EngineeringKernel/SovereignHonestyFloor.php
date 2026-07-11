<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\NonFunctional\MigrationSafetyProbe;

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
    public const FLOOR_VERSION = 'atlas.engineering_kernel.sovereign_floor.v3';

    /** The non-overridable sovereign pisos. config() may raise these, never lower them. */
    public const SOVEREIGN_MUTATION_FLOOR = 0.6;

    public const SOVEREIGN_CONTEXT_FLOOR = 80;

    public const MIN_JUDGE_FAMILIES = 2;

    /** Signature of the legacy fake-green kernel's fixed smoke artifact — a hard tell of a lint-as-suite lie. */
    public const FIXED_SMOKE_SIGNATURE = 'atlas_real_execution_smoke';

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
            return new self;
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
            // Obra #3 — non-functional gates. Each WAIVES when the delivery does not touch its
            // surface, so an honest functional-only change is never blocked by them; each fails
            // closed when the surface IS touched but its safety is unproven.
            'performance_budget' => $this->performanceBudget($bundle),
            'migration_safety' => $this->migrationSafety($bundle),
            'architecture_no_regression' => $this->architectureNoRegression($bundle),
            'property_clean_for_tagged' => $this->propertyCleanForTagged($bundle),
            // OBRA #4 S1 — regression-lock como LEI: falha reparada vira caso trancado para sempre.
            // Waive quando não houve repair; fail-closed quando houve e o lock não existe.
            'regression_locked_for_repaired' => $this->regressionLockedForRepaired($bundle),
            // OBRA #4 S2 — replay-proof: reparo só conta como consertado quando o caso EXATO da
            // falha original re-rodou e passou. "Suíte verde de novo" não basta.
            'replay_proof_for_repaired' => $this->replayProofForRepaired($bundle),
        ];

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
        // Single source of the anti-fake-green rule — shared with the OutcomeMemory
        // write-path proof gate so a fake-green cannot slip through a drifted copy.
        return (new FalseClaimInvariant)->evaluate($bundle->execution);
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
        if ($this->notApplicable($bundle->mutationReport['applicability'] ?? null, 'maintenance_simplification', 'no_code_change')) {
            return $this->pass('mutation_explicitly_not_applicable');
        }
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
        if (($scan['ran'] ?? false) === false && $this->notApplicable($scan['applicability'] ?? null, 'appsec_privacy', 'no_mutation_security_applicability_scan')) {
            return $this->pass('security_explicitly_not_applicable');
        }
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
        if ($bundle->judges === [] && $this->deterministicCourtsValid(data_get($bundle->nonFunctional, 'judge_diversity.deterministic_courts'))) {
            return $this->pass('independent_deterministic_courts_satisfied_r0');
        }
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

    /**
     * Obra #3 — performance budget. Opt-in: a delivery that DECLARES a budget must show a measured
     * metric within it. Declared-but-unmeasured is fail-closed; not declared is waived.
     *
     * @return array{status:string,detail:string}
     */
    private function performanceBudget(AcceptanceBundle $bundle): array
    {
        $nf = (array) ($bundle->nonFunctional['performance_budget'] ?? []);
        if ($this->notApplicable($nf['applicability'] ?? null, 'performance_resilience', 'no_runtime_path_change')) {
            return $this->pass('performance_explicitly_not_applicable');
        }
        if (($nf['applies'] ?? false) !== true) {
            return $this->pass('performance_budget_not_applicable');
        }
        if (! array_key_exists('budget', $nf) || ! array_key_exists('measured', $nf)) {
            return $this->fail('performance_budget_declared_but_unmeasured');
        }
        $budget = (float) $nf['budget'];
        $measured = (float) $nf['measured'];

        return $measured <= $budget
            ? $this->pass("performance_within_budget {$measured} <= {$budget}")
            : $this->fail("performance_regression_over_budget {$measured} > {$budget}");
    }

    /**
     * Obra #3 — migration safety. Objective trigger: any changed file under database/migrations/.
     * Touching a migration WITHOUT a safety probe is fail-closed (the direct guard against a data
     * wipe); an unsafe probe result is refused. No migration touched => waived.
     *
     * @return array{status:string,detail:string}
     */
    private function migrationSafety(AcceptanceBundle $bundle): array
    {
        if ($this->notApplicable(data_get($bundle->nonFunctional, 'migration_safety.applicability'), 'data', 'no_data_or_schema_change')) {
            return $this->pass('migration_explicitly_not_applicable');
        }
        $touchesMigration = false;
        foreach ($bundle->changedFiles as $file) {
            if (MigrationSafetyProbe::isMigrationPath((string) $file)) {
                $touchesMigration = true;
                break;
            }
        }
        if (! $touchesMigration) {
            return $this->pass('no_migration_touched');
        }

        $nf = (array) ($bundle->nonFunctional['migration_safety'] ?? []);
        if (($nf['probed'] ?? false) !== true) {
            return $this->fail('migration_touched_without_safety_probe');
        }

        return ($nf['safe'] ?? false) === true
            ? $this->pass('migrations_safe')
            : $this->fail('unsafe_migration:'.implode(';', array_map('strval', (array) ($nf['reasons'] ?? ['unspecified']))));
    }

    /**
     * Obra #3 — architecture no-regression. A diff that adds an import edge crossing a forbidden
     * layer boundary is refused. No reported violation => pass.
     * ponytail: advisory-strong — waives when no edge scan ran; fully fail-closes once the adapter
     * extracts import edges on every delivery (upgrade path: wire ArchitectureRegressionProbe live).
     *
     * @return array{status:string,detail:string}
     */
    private function architectureNoRegression(AcceptanceBundle $bundle): array
    {
        $nf = (array) ($bundle->nonFunctional['architecture_no_regression'] ?? []);
        if ($this->notApplicable($nf['applicability'] ?? null, 'architecture', 'no_architecture_change')) {
            return $this->pass('architecture_explicitly_not_applicable');
        }
        $violations = array_values(array_map('strval', (array) ($nf['violations'] ?? [])));

        return $violations === []
            ? $this->pass('no_architecture_regression')
            : $this->fail('architecture_regression:'.implode(';', $violations));
    }

    /**
     * Obra #3 — property-clean for tagged (sovereignty). A delivery tagged sensitive/secret/cyber
     * must prove its property invariant was checked and holds. Tagged-but-unchecked is fail-closed;
     * a violation is refused. Untagged => waived.
     *
     * @return array{status:string,detail:string}
     */
    private function propertyCleanForTagged(AcceptanceBundle $bundle): array
    {
        $nf = (array) ($bundle->nonFunctional['property_clean_for_tagged'] ?? []);
        if ($this->notApplicable($nf['applicability'] ?? null, 'appsec_privacy', 'no_mutation_security_applicability_scan')) {
            return $this->pass('property_explicitly_not_applicable');
        }
        if (($nf['tagged'] ?? false) !== true) {
            return $this->pass('not_tagged_sensitive');
        }
        if (($nf['checked'] ?? false) !== true) {
            return $this->fail('tagged_sensitive_but_property_unchecked');
        }
        $violations = array_values(array_map('strval', (array) ($nf['violations'] ?? [])));

        return $violations === []
            ? $this->pass('tagged_property_clean')
            : $this->fail('tagged_property_violation:'.implode(';', $violations));
    }

    /**
     * OBRA #4 S1 — regression-lock invariant: a delivery that needed repair (attempts > 0) must
     * carry the regression_lock_ref proving the failure it repaired was locked as a permanent case
     * (or quarantined as known-flaky — the KNOWLEDGE is what must never be lost). No repair =>
     * waived; repaired-without-lock => refused. "A cada ciclo, o harness fica mais difícil de quebrar."
     *
     * @return array{status:string,detail:string}
     */
    private function regressionLockedForRepaired(AcceptanceBundle $bundle): array
    {
        $attempts = (int) ($bundle->repair['attempts'] ?? 0);
        if ($attempts < 1) {
            return $this->pass('no_repair_attempts_lock_waived');
        }

        $ref = trim((string) ($bundle->repair['regression_lock_ref'] ?? ''));

        return $ref !== ''
            ? $this->pass('repaired_failure_locked:'.substr($ref, 0, 16))
            : $this->fail('repaired_without_regression_lock');
    }

    /**
     * OBRA #4 S2 — replay-proof invariant: a repaired delivery must prove the ORIGINAL failing case
     * was replayed and passed — a fix that turns the suite green without re-proving the exact case
     * that failed is not a proven fix. No repair => waived; repaired-without-replay => refused.
     *
     * @return array{status:string,detail:string}
     */
    private function replayProofForRepaired(AcceptanceBundle $bundle): array
    {
        $attempts = (int) ($bundle->repair['attempts'] ?? 0);
        if ($attempts < 1) {
            return $this->pass('no_repair_attempts_replay_waived');
        }

        $proof = (array) ($bundle->repair['replay_proof'] ?? []);
        if (($proof['replayed'] ?? false) !== true) {
            return $this->fail('repaired_without_replaying_original_failure');
        }

        return ($proof['passed'] ?? false) === true
            ? $this->pass('original_failure_replayed_green:'.(string) ($proof['original_failure_ref'] ?? ''))
            : $this->fail('original_failure_replay_still_red');
    }

    private function notApplicable(mixed $receipt, string $expectedRole, string $expectedRule): bool
    {
        if (! is_array($receipt) || ($receipt['status'] ?? null) !== 'not_applicable'
            || ($receipt['role'] ?? null) !== $expectedRole || ($receipt['applicability_rule'] ?? null) !== $expectedRule
            || ! is_string($receipt['signature'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $receipt['signature']) !== 1
            || ! is_string($receipt['justification'] ?? null) || $receipt['justification'] === ''
            || ! is_array($receipt['probe_facts'] ?? null)) {
            return false;
        }
        $required = ['authority_read_only', 'mutation_forbidden', 'release_none', 'provider_none', 'explicit_role_policy', 'scope_read_only_docs'];
        foreach ($required as $fact) {
            if (($receipt['probe_facts'][$fact] ?? false) !== true) {
                return false;
            }
        }

        return $this->courtSignatureValid($receipt, ReadOnlyQualityCourt::VERIFIER_DOMAIN);
    }

    private function deterministicCourtsValid(mixed $receipts): bool
    {
        if (! is_array($receipts) || count($receipts) !== 2) {
            return false;
        }
        $contexts = [];
        foreach ($receipts as $receipt) {
            if (! is_array($receipt) || ($receipt['status'] ?? null) !== 'pass'
                || ! is_string($receipt['signature'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $receipt['signature']) !== 1
                || ! is_string($receipt['signer_context'] ?? null) || $receipt['signer_context'] === '') {
                return false;
            }
            $contexts[] = $receipt['signer_context'];
            if (! $this->courtSignatureValid($receipt, (string) $receipt['signer_context'])) {
                return false;
            }
        }
        sort($contexts);
        $expected = [ReadOnlyFinalCertifier::DOMAIN, ReadOnlyQualityCourt::VERIFIER_DOMAIN];
        sort($expected);

        if ($contexts !== $expected) {
            return false;
        }
        $byRole = [];
        foreach ($receipts as $receipt) {
            $byRole[(string) ($receipt['role'] ?? '')] = $receipt['signer_context'] ?? null;
        }

        return $byRole === ['evidence_audit' => ReadOnlyQualityCourt::VERIFIER_DOMAIN, 'final_certification' => ReadOnlyFinalCertifier::DOMAIN];
    }

    /** @param array<string,mixed> $receipt */
    private function courtSignatureValid(array $receipt, string $domain): bool
    {
        if (($receipt['signer_context'] ?? null) !== $domain
            || ! in_array($domain, [ReadOnlyQualityCourt::VERIFIER_DOMAIN, ReadOnlyFinalCertifier::DOMAIN], true)) {
            return false;
        }
        $signature = (string) ($receipt['signature'] ?? '');
        unset($receipt['signature']);
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $material = is_string($decoded) ? $decoded : '';

        return hash_equals($signature, hash_hmac('sha256', CanonicalKernelPayload::hash($receipt), hash_hmac('sha256', $domain, $material, true)));
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
