<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtEvidenceContract;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;

/**
 * Sovereign composition seam for the Verification Court. The existing floor
 * remains the owner of kernel invariants; this class adds the court-specific
 * conjunctive evidence and never gathers evidence or performs effects.
 */
final class VerificationCourtAcceptanceGate implements AcceptanceGate
{
    public const SCHEMA = 'atlas.engineering_kernel.verification_court_acceptance_gate.v1';

    public function __construct(
        private readonly SovereignHonestyFloor $floor = new SovereignHonestyFloor,
        private readonly AtlasVerificationCourtEvidenceContract $evidenceContract = new AtlasVerificationCourtEvidenceContract,
        private readonly AtlasVerificationCourtFalseGreenDetector $falseGreenDetector = new AtlasVerificationCourtFalseGreenDetector,
    ) {}

    public function certify(AcceptanceBundle $bundle, TrustLevel $trust): CertVerdict
    {
        $floorVerdict = $this->floor->certify($bundle, $trust);
        $facts = $bundle->nonFunctional['verification_court'] ?? null;
        $invariants = $floorVerdict->invariants;
        $court = is_array($facts) ? $this->courtInvariants($facts) : [
            'verification_court_bundle' => ['status' => 'fail', 'detail' => 'verification_court_facts_missing'],
        ];
        $invariants = array_merge($invariants, $court);
        $blockers = $floorVerdict->blockers;
        foreach ($court as $id => $result) {
            if (($result['status'] ?? 'fail') !== 'pass') {
                $blockers[] = $id;
            }
        }
        $blockers = array_values(array_unique($blockers));

        $verdict = $blockers === []
            ? CertVerdict::promote($invariants, $trust->witnessSet())
            : CertVerdict::refuse($blockers, $invariants, $trust->witnessSet());

        return $floorVerdict->receiptRef === null ? $verdict : $verdict->withReceiptRef($floorVerdict->receiptRef);
    }

    /** @return array<string,array{status:string,detail:string}> */
    private function courtInvariants(array $facts): array
    {
        $results = [];
        $results['verification_roles'] = $this->roles($facts);
        $results['verification_hashes'] = $this->hashes($facts);

        $contract = $this->evidenceContract->verify(
            is_array($facts['allegation'] ?? null) ? $facts['allegation'] : [],
            is_array($facts['evidence'] ?? null) ? $facts['evidence'] : [],
        );
        $results['evidence_contract'] = [
            'status' => $contract['accepted'] === true ? 'pass' : 'fail',
            'detail' => $contract['accepted'] === true ? 'court_evidence_contract_accepted' : implode(',', $contract['blockers']),
        ];

        $replay = $this->falseGreenDetector->detect(is_array($facts['replay'] ?? null) ? $facts['replay'] : []);
        $results['false_green_replay'] = [
            'status' => $replay['verdict'] === AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED ? 'pass' : 'fail',
            'detail' => $replay['verdict'] === AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED ? 'replay_passed' : implode(',', $replay['reasons']),
        ];

        return $results;
    }

    /** @return array{status:string,detail:string} */
    private function roles(array $facts): array
    {
        $required = array_values(array_filter(array_map('strval', (array) ($facts['required_roles'] ?? []))));
        $dispositions = is_array($facts['dispositions'] ?? null) ? $facts['dispositions'] : [];
        $byRole = [];
        foreach ($dispositions as $disposition) {
            if (is_array($disposition)) {
                $byRole[(string) ($disposition['role'] ?? '')] = $disposition;
            }
        }
        $blockers = [];
        foreach ($required as $role) {
            $d = $byRole[$role] ?? null;
            if (! is_array($d)) {
                $blockers[] = 'missing_role:'.$role;
                continue;
            }
            if (($d['status'] ?? '') === 'not_applicable' && ($d['na_proof'] ?? false) !== true) {
                $blockers[] = 'forged_na:'.$role;
            } elseif (($d['status'] ?? '') !== 'pass') {
                $blockers[] = 'role_blocked:'.$role;
            }
            if (($d['author_id'] ?? null) !== null && ($d['author_id'] ?? '') === ($d['verifier_id'] ?? null)) {
                $blockers[] = 'self_verification:'.$role;
            }
        }

        return [
            'status' => $required !== [] && $blockers === [] ? 'pass' : 'fail',
            'detail' => $required === [] ? 'required_roles_missing' : ($blockers === [] ? 'roles_passed' : implode(',', $blockers)),
        ];
    }

    /** @return array{status:string,detail:string} */
    private function hashes(array $facts): array
    {
        $expected = is_array($facts['expected_hashes'] ?? null) ? $facts['expected_hashes'] : [];
        $actual = is_array($facts['actual_hashes'] ?? null) ? $facts['actual_hashes'] : [];
        $blockers = [];
        foreach (['world_hash', 'spec_hash', 'order_hash'] as $key) {
            if (! is_string($expected[$key] ?? null) || ! is_string($actual[$key] ?? null) || $expected[$key] === '' || ! hash_equals($expected[$key], $actual[$key])) {
                $blockers[] = 'hash_mismatch:'.$key;
            }
        }

        return ['status' => $blockers === [] ? 'pass' : 'fail', 'detail' => $blockers === [] ? 'world_spec_order_hashes_match' : implode(',', $blockers)];
    }
}
