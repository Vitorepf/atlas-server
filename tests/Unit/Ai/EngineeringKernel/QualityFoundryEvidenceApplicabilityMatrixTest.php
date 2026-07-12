<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\QualityFoundryEvidenceApplicabilityMatrix;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\EngineeringKernel\VerificationCourtAcceptanceGate;
use PHPUnit\Framework\TestCase;

final class QualityFoundryEvidenceApplicabilityMatrixTest extends TestCase
{
    public function test_r4_requires_independent_dimensions_and_missing_receipt_blocks_acceptance(): void
    {
        $matrix = new QualityFoundryEvidenceApplicabilityMatrix;
        $report = $matrix->evaluate([
            'risk_class' => 'R4',
            'delivery_facts' => [
                'mutative' => true, 'runtime_boundary' => true, 'performance_sensitive' => true,
                'user_facing' => true, 'migration' => true, 'outcome_claimed' => true,
            ],
            'evidence' => [],
        ]);

        self::assertFalse($report['accepted']);
        foreach (QualityFoundryEvidenceApplicabilityMatrix::DIMENSIONS as $dimension) {
            self::assertContains('evidence_missing:'.$dimension, $report['blockers']);
        }
        self::assertContains('evidence_missing:chaos', $report['blockers']);
        self::assertContains('evidence_missing:metamorphic', $report['blockers']);
        self::assertContains('evidence_missing:rollback', $report['blockers']);
    }

    public function test_na_requires_rule_justification_and_canonical_evidence_hash(): void
    {
        $matrix = new QualityFoundryEvidenceApplicabilityMatrix;
        $facts = ['risk_class' => 'R0', 'evidence' => [
            'unit' => ['status' => 'pass', 'receipt_hash' => 'unit-hash'],
        ]];
        $invalid = $matrix->evaluate(array_replace($facts, ['delivery_facts' => ['performance_sensitive' => true], 'evidence' => [
            'unit' => ['status' => 'pass', 'receipt_hash' => 'unit-hash'],
            'performance' => ['status' => 'not_applicable', 'na_proof' => true],
        ]]));
        self::assertContains('evidence_invalid:performance', $invalid['blockers']);

        $valid = $matrix->evaluate(array_replace($facts, ['delivery_facts' => ['performance_sensitive' => true], 'evidence' => [
            'unit' => ['status' => 'pass', 'receipt_hash' => 'unit-hash'],
            'performance' => [
                'status' => 'not_applicable', 'na_proof' => true, 'rule' => 'no_runtime_path',
                'justification' => 'no runtime path changed', 'evidence_hash' => 'na-hash',
            ],
        ]]));
        self::assertTrue($valid['accepted']);
    }

    public function test_mode_does_not_lower_the_required_matrix_and_gate_refuses_missing_evidence(): void
    {
        $matrix = new QualityFoundryEvidenceApplicabilityMatrix;
        $dev = $matrix->evaluate(['risk_class' => 'R3', 'mode' => 'dev', 'delivery_facts' => ['mutative' => true]]);
        $forge = $matrix->evaluate(['risk_class' => 'R3', 'mode' => 'forge', 'delivery_facts' => ['mutative' => true]]);
        $autonomos = $matrix->evaluate(['risk_class' => 'R3', 'mode' => 'autonomos', 'delivery_facts' => ['mutative' => true]]);
        self::assertSame($dev['required'], $forge['required']);
        self::assertSame($forge['required'], $autonomos['required']);

        $verdict = (new VerificationCourtAcceptanceGate)->certify(
            AcceptanceBundleFactory::honest([
                'non_functional' => ['verification_court' => $this->courtFacts([
                    'risk_class' => 'R4',
                    'delivery_facts' => ['mutative' => true],
                    'evidence' => ['unit' => ['status' => 'pass', 'receipt_hash' => 'unit-hash']],
                ])],
            ]),
            TrustLevel::Dev,
        );
        self::assertSame('refuse', $verdict->status);
        self::assertContains('evidence_applicability', $verdict->blockers);
    }

    public function test_r0_to_r5_policy_is_deterministic_and_one_dimension_failure_remains_red(): void
    {
        $matrix = new QualityFoundryEvidenceApplicabilityMatrix;
        $deliveryFacts = [
            'mutative' => true, 'runtime_boundary' => true, 'security_sensitive' => true,
            'performance_sensitive' => true, 'user_facing' => true, 'migration' => true,
            'outcome_claimed' => true,
        ];

        foreach (['R0', 'R1', 'R2', 'R3', 'R4', 'R5'] as $risk) {
            $required = $matrix->evaluate(['risk_class' => $risk, 'delivery_facts' => $deliveryFacts])['required'];
            $evidence = [];
            foreach ($required as $dimension) {
                $evidence[$dimension] = ['status' => 'pass', 'receipt_hash' => 'receipt-'.$dimension];
                if (in_array($dimension, ['mutation', 'property', 'metamorphic'], true) && (int) ltrim($risk, 'R') >= 4) {
                    $evidence[$dimension]['oracle'] = ['kind' => 'implementation_independent', 'implementation_independent' => true];
                }
            }
            $ready = $matrix->evaluate([
                'risk_class' => $risk, 'mode' => 'dev', 'delivery_facts' => $deliveryFacts, 'evidence' => $evidence,
            ]);
            self::assertTrue($ready['accepted'], $risk.': '.implode(',', $ready['blockers']));

            $failedEvidence = $evidence;
            $failedEvidence[$required[0]]['receipt_hash'] = '';
            $failed = $matrix->evaluate([
                'risk_class' => $risk, 'delivery_facts' => $deliveryFacts, 'evidence' => $failedEvidence,
            ]);
            self::assertFalse($failed['accepted'], $risk.' accepted invalid '.$required[0]);
        }
    }

    public function test_r4_oracle_cannot_be_implementation_dependent(): void
    {
        $matrix = new QualityFoundryEvidenceApplicabilityMatrix;
        $result = $matrix->evaluate([
            'risk_class' => 'R4',
            'evidence' => [
                'unit' => ['status' => 'pass', 'receipt_hash' => 'unit'],
                'mutation' => ['status' => 'pass', 'receipt_hash' => 'mutation', 'oracle' => ['kind' => 'unit', 'implementation_independent' => false]],
                'property' => ['status' => 'pass', 'receipt_hash' => 'property', 'oracle' => ['kind' => 'property', 'implementation_independent' => true]],
                'metamorphic' => ['status' => 'pass', 'receipt_hash' => 'metamorphic', 'oracle' => ['kind' => 'metamorphic', 'implementation_independent' => true]],
            ],
        ]);

        self::assertContains('oracle_invalid:mutation', $result['blockers']);
    }

    /** @param array<string,mixed> $applicability @return array<string,mixed> */
    private function courtFacts(array $applicability): array
    {
        return [
            'required_roles' => EngineeringRoleRoster::OFFICIAL_ROLES,
            'dispositions' => array_map(
                static fn (string $role): array => ['role' => $role, 'status' => 'pass', 'author_id' => 'author-a', 'verifier_id' => 'judge-a'],
                EngineeringRoleRoster::OFFICIAL_ROLES,
            ),
            'expected_hashes' => ['world_hash' => str_repeat('a', 64), 'spec_hash' => str_repeat('b', 64), 'order_hash' => str_repeat('c', 64)],
            'actual_hashes' => ['world_hash' => str_repeat('a', 64), 'spec_hash' => str_repeat('b', 64), 'order_hash' => str_repeat('c', 64)],
            'allegation' => ['task_packet_id' => 'packet-1', 'lease_id' => 'lease-1', 'allowed_files_hash' => 'files-1', 'command_hash' => 'command-1'],
            'evidence' => ['receipt_chain' => ['task_packet_id' => 'packet-1', 'lease_id' => 'lease-1', 'allowed_files_hash' => 'files-1', 'command_hash' => 'command-1']],
            'replay' => [
                'evidence_contract_result' => ['accepted' => true],
                'replay_plan_result' => ['plan_status' => 'ready', 'commands' => [['id' => 'cmd-1', 'name' => 'focused']], 'blockers' => []],
                'replay_outcomes' => [['command_id' => 'cmd-1', 'passed' => true, 'output_present' => true]],
                'changed_files' => ['app/Foo.php'], 'allowed_files' => ['app/Foo.php'],
            ],
            'evidence_applicability' => $applicability,
        ];
    }
}
