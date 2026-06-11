<?php

namespace App\Services\Ai\Product;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiStringListNormalizer;

class AtlasProductFalsificationProofRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.product_proof_challenge.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function challenge(array $input): array
    {
        $truth = $this->array($input['product_truth'] ?? $input['truth_contract'] ?? []);
        $delivery = $this->array($input['delivery_contract'] ?? $input['delivery'] ?? []);
        $evidence = $this->array($input['evidence'] ?? []);
        $route = $this->string($delivery['route'] ?? $delivery['route_target'] ?? data_get($truth, 'execution_decomposition.route')) ?? 'unknown';

        $blockers = array_merge(
            $this->requirementBlockers($truth),
            $this->acceptanceBlockers($truth),
            $this->contractBlockers($truth),
            $this->securityBlockers($truth, $evidence),
            $this->testBlockers($truth, $evidence),
            $this->evidenceBlockers($truth, $delivery, $evidence),
            $this->humanAssumptionBlockers($truth),
        );

        $counterexamples = $this->counterexamples($truth);
        $criticalBlockers = array_values(array_filter(
            $blockers,
            static fn (array $blocker): bool => ($blocker['severity'] ?? 'critical') === 'critical',
        ));

        $status = match (true) {
            $criticalBlockers !== [] => 'blocked',
            $blockers !== [] => 'needs_repair',
            default => 'ready',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'target' => [
                'delivery_id' => $this->string($delivery['delivery_id'] ?? $input['delivery_id'] ?? null),
                'route' => $route,
            ],
            'falsification_score' => $this->score($blockers, $counterexamples),
            'critical_blockers' => $criticalBlockers,
            'counterexamples' => $counterexamples,
            'required_repairs' => $this->requiredRepairs($blockers),
            'proof' => [
                'requirements' => $this->proofStatus($this->requirementBlockers($truth)),
                'acceptance' => $this->proofStatus($this->acceptanceBlockers($truth)),
                'contracts' => $this->proofStatus($this->contractBlockers($truth)),
                'business_rules' => $this->proofStatus($this->requirementBlockers($truth)),
                'security' => $this->proofStatus($this->securityBlockers($truth, $evidence)),
                'tests' => $this->proofStatus($this->testBlockers($truth, $evidence)),
                'evidence' => $this->proofStatus($this->evidenceBlockers($truth, $delivery, $evidence)),
            ],
            'claim_policy' => [
                'provider_invoked' => false,
                'writes' => false,
                'external_superiority_claim' => false,
            ],
        ];
        $payload['proof_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $truth
     * @return list<array<string,string>>
     */
    private function requirementBlockers(array $truth): array
    {
        $rules = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'business_domain.rules', []));
        if ($rules === []) {
            return [$this->blocker('missing_business_rules', 'critical', 'Product truth has no business rules.')];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $truth
     * @return list<array<string,string>>
     */
    private function acceptanceBlockers(array $truth): array
    {
        $mustWork = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'acceptance_universe.must_work', []));
        $mustNotBreak = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'acceptance_universe.must_not_break', []));
        if ($mustWork === [] || $mustNotBreak === []) {
            return [$this->blocker('missing_acceptance_universe', 'critical', 'Acceptance must include must_work and must_not_break.')];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $truth
     * @return list<array<string,string>>
     */
    private function contractBlockers(array $truth): array
    {
        $blocked = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'execution_lenses.blocked_if_missing', []));
        if (! in_array('contract', $blocked, true)) {
            return [];
        }

        $apis = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'contract_map.apis', []));
        $events = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'contract_map.events', []));
        $shapes = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'contract_map.data_shapes', []));
        if ($apis === [] && $events === [] && $shapes === []) {
            return [$this->blocker('missing_contract_map', 'critical', 'Required contract lens has no API/event/data shape.')];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $truth
     * @param  array<string,mixed>  $evidence
     * @return list<array<string,string>>
     */
    private function securityBlockers(array $truth, array $evidence): array
    {
        $required = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'execution_lenses.required', []));
        if (! in_array('security_driven', $required, true)) {
            return [];
        }

        $securityEvidence = AiStringListNormalizer::trimmedScalarValues($evidence['security'] ?? []);
        if ($securityEvidence === []) {
            return [$this->blocker('missing_security_evidence', 'critical', 'Security-driven delivery requires security evidence.')];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $truth
     * @param  array<string,mixed>  $evidence
     * @return list<array<string,string>>
     */
    private function testBlockers(array $truth, array $evidence): array
    {
        $tests = AiStringListNormalizer::trimmedScalarValues($evidence['tests'] ?? []);
        if ($tests === []) {
            return [$this->blocker('missing_test_evidence', 'critical', 'Delivery has no test evidence or skip_reason.')];
        }

        $requiredTests = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'proof_plan.tests', []));
        if (in_array('contract_tests', $requiredTests, true) && ! $this->containsNeedle($tests, 'contract')) {
            return [$this->blocker('missing_contract_test_evidence', 'critical', 'Contract lens requires contract test evidence.')];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $truth
     * @param  array<string,mixed>  $delivery
     * @param  array<string,mixed>  $evidence
     * @return list<array<string,string>>
     */
    private function evidenceBlockers(array $truth, array $delivery, array $evidence): array
    {
        $blockers = [];
        if ($delivery === []) {
            $blockers[] = $this->blocker('missing_delivery_contract', 'critical', 'APFPR requires a delivery contract.');
        }
        if ($delivery !== [] && ($delivery['status'] ?? null) !== 'ready_for_delivery') {
            $blockers[] = $this->blocker('delivery_contract_not_ready', 'critical', 'APFPR cannot certify a delivery contract that is not ready.');
        }
        if ($truth === []) {
            $blockers[] = $this->blocker('missing_product_truth', 'critical', 'APFPR requires a Product Truth Contract.');
        }
        if (AiStringListNormalizer::trimmedScalarValues($evidence['acceptance_mapping'] ?? []) === []) {
            $blockers[] = $this->blocker('missing_acceptance_mapping', 'critical', 'Evidence must map tests/diff to acceptance.');
        }
        if (AiStringListNormalizer::trimmedScalarValues($evidence['outcome'] ?? []) === []) {
            $blockers[] = $this->blocker('missing_outcome_evidence', 'warn', 'Outcome memory evidence is missing.');
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $truth
     * @return list<array<string,string>>
     */
    private function humanAssumptionBlockers(array $truth): array
    {
        $questions = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'human_questions.minimum_required', []));
        if (($truth['status'] ?? null) === 'needs_context' && $questions !== []) {
            return [$this->blocker('unresolved_human_questions', 'critical', 'Product truth still needs human answers.')];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $truth
     * @return list<array<string,string>>
     */
    private function counterexamples(array $truth): array
    {
        $examples = [];
        $objects = AiStringListNormalizer::trimmedScalarValues(data_get($truth, 'business_domain.objects', []));
        if (in_array('payment', $objects, true)) {
            $examples[] = ['id' => 'payment_webhook_replay', 'question' => 'Does a replayed webhook mutate the same order twice?'];
            $examples[] = ['id' => 'payment_declined_checkout', 'question' => 'Does checkout fail safely when payment is declined?'];
        }
        if (in_array('credential', $objects, true) || in_array('session', $objects, true)) {
            $examples[] = ['id' => 'expired_session_login', 'question' => 'Does the fix handle expired sessions and invalid credentials?'];
        }
        if ($examples === []) {
            $examples[] = ['id' => 'acceptance_false_positive', 'question' => 'Can the declared tests pass while the main user goal still fails?'];
        }

        return $examples;
    }

    /**
     * @param  list<array<string,string>>  $blockers
     * @return list<string>
     */
    private function requiredRepairs(array $blockers): array
    {
        return array_values(array_unique(array_map(
            static fn (array $blocker): string => match ($blocker['id'] ?? '') {
                'missing_test_evidence' => 'add_or_run_focused_tests',
                'missing_contract_test_evidence' => 'add_contract_test_evidence',
                'missing_security_evidence' => 'add_security_abuse_case_evidence',
                'missing_acceptance_mapping' => 'map_evidence_to_acceptance',
                'unresolved_human_questions' => 'ask_minimum_human_questions',
                default => 'repair_missing_proof',
            },
            $blockers,
        )));
    }

    /**
     * @param  list<array<string,string>>  $blockers
     * @param  list<array<string,string>>  $counterexamples
     */
    private function score(array $blockers, array $counterexamples): float
    {
        $penalty = count($blockers) * 0.18;
        $challengeCredit = min(count($counterexamples) * 0.04, 0.16);

        return round(max(0.0, min(1.0, 1.0 - $penalty + $challengeCredit)), 2);
    }

    /**
     * @param  list<array<string,string>>  $blockers
     */
    private function proofStatus(array $blockers): string
    {
        return $blockers === [] ? 'passed' : 'failed';
    }

    /**
     * @return array<string,string>
     */
    private function blocker(string $id, string $severity, string $reason): array
    {
        return compact('id', 'severity', 'reason');
    }

    /**
     * @return array<string,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  list<string>  $items
     */
    private function containsNeedle(array $items, string $needle): bool
    {
        foreach ($items as $item) {
            if (str_contains(mb_strtolower($item), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
