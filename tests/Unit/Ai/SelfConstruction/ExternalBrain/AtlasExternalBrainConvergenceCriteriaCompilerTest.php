<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainConvergenceCriteriaCompiler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainConvergenceCriteriaCompilerTest extends TestCase
{
    private AtlasExternalBrainConvergenceCriteriaCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compiler = new AtlasExternalBrainConvergenceCriteriaCompiler();
    }

    // AC 2: maturity without evidence receipts → unproven
    public function test_maturity_without_evidence_marked_unproven(): void
    {
        $result = $this->compiler->compile([
            'autonomy' => ['maturity_claim' => 'level_3'],
            'queue_quality' => ['maturity_claim' => 'level_3'],
            'outcome_learning' => ['maturity_claim' => 'level_3'],
            'simplification' => ['maturity_claim' => 'level_3'],
            'model_amplifier' => ['maturity_claim' => 'level_3'],
        ]);

        $this->assertFalse($result['all_proven']);
        $this->assertNotEmpty($result['unproven']);
    }

    // AC 3: all 5 groups required
    public function test_all_five_groups_required(): void
    {
        $result = $this->compiler->compile([
            'autonomy' => ['maturity_claim' => 'level_3', 'evidence_receipt' => 'autonomy_test.json'],
            'queue_quality' => ['maturity_claim' => 'level_3', 'evidence_receipt' => 'queue_test.json'],
            'outcome_learning' => ['maturity_claim' => 'level_3', 'evidence_receipt' => 'learning_test.json'],
            'simplification' => ['maturity_claim' => 'level_3', 'evidence_receipt' => 'simplification_test.json'],
            'model_amplifier' => ['maturity_claim' => 'level_3', 'evidence_receipt' => 'model_test.json'],
        ]);

        foreach (['autonomy', 'queue_quality', 'outcome_learning', 'simplification', 'model_amplifier'] as $group) {
            $this->assertArrayHasKey($group, $result['groups']);
            $this->assertTrue($result['groups'][$group]['required']);
            $this->assertTrue($result['groups'][$group]['proven']);
        }
        $this->assertTrue($result['all_proven']);
    }

    // AC 4: compiled criteria include exact proof fields
    public function test_compiled_criteria_include_proof_fields(): void
    {
        $result = $this->compiler->compile([
            'autonomy' => ['maturity_claim' => 'level_3', 'evidence_receipt' => 'autonomy_receipt.json'],
        ]);

        $this->assertSame('autonomy.evidence_receipt', $result['groups']['autonomy']['proof_field']);
        $this->assertSame('autonomy_receipt.json', $result['groups']['autonomy']['receipt']);
    }

    public function test_empty_dimensions_all_unproven(): void
    {
        $result = $this->compiler->compile([]);

        $this->assertFalse($result['all_proven']);
        $this->assertCount(5, $result['unproven']);
    }

    public function test_partial_evidence_leaves_remaining_unproven(): void
    {
        $result = $this->compiler->compile([
            'autonomy' => ['maturity_claim' => 'level_3', 'evidence_receipt' => 'a.json'],
        ]);

        $this->assertFalse($result['all_proven']);
        $this->assertNotContains('autonomy', $result['unproven']);
        $this->assertContains('queue_quality', $result['unproven']);
    }

    public function test_blank_receipt_treated_as_absent_and_unproven(): void
    {
        $result = $this->compiler->compile([
            'autonomy' => ['maturity_claim' => 'level_3', 'evidence_receipt' => '   '],
        ]);

        $this->assertFalse($result['all_proven']);
        $this->assertFalse($result['groups']['autonomy']['proven']);
        $this->assertNull($result['groups']['autonomy']['receipt'], 'blank receipt must be null');
        $this->assertContains('autonomy', $result['unproven']);
    }

    public function test_empty_claim_treated_as_not_declared_and_unproven(): void
    {
        $result = $this->compiler->compile([
            'autonomy' => ['maturity_claim' => '', 'evidence_receipt' => 'receipt.json'],
        ]);

        $this->assertFalse($result['all_proven']);
        $this->assertFalse($result['groups']['autonomy']['proven']);
        $this->assertSame('not_declared', $result['groups']['autonomy']['claim']);
        $this->assertContains('autonomy', $result['unproven']);
    }
}
