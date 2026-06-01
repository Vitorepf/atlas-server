<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingGovernanceSystemContractsService;
use Tests\TestCase;

/**
 * Pins the contracts doc's load-bearing, decidable rules: the per-contract
 * required-field schemas plus the four hard rejection rules — retroactive spec
 * fails (Contract 2), textual-only evidence is rejected (Contract 5), an
 * out-of-contract diff is blocked / a forbidden diff is escalated (Contract 3),
 * and learning never auto-applies governance (Contract 6). Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
 */
class AtlasProgrammingGovernanceSystemContractsTest extends TestCase
{
    private function service(): AtlasProgrammingGovernanceSystemContractsService
    {
        return new AtlasProgrammingGovernanceSystemContractsService;
    }

    /**
     * Doc "Contrato 3": a task contract requires exactly the 12 named fields.
     * Dropping the last one (cartography_required) makes the contract invalid
     * with that field reported missing; supplying all 12 makes it valid.
     */
    public function test_task_contract_requires_all_twelve_documented_fields(): void
    {
        $svc = $this->service();

        $full = [
            'allowed_files' => ['a.php'], 'forbidden_files' => ['b.php'],
            'expected_files' => ['a.php'], 'owner' => 'programming',
            'dependencies' => ['dep'], 'risk_level' => 'high',
            'validation_commands' => ['php artisan test'], 'acceptance_criteria' => ['green'],
            'rollback' => 'revert', 'evidence_required' => ['receipt'],
            'docs_required' => ['doc'], 'cartography_required' => true,
        ];

        $this->assertCount(12, $svc->requiredFields('task_contract'));

        $ok = $svc->validateContract('task_contract', $full);
        $this->assertTrue($ok['valid']);
        $this->assertSame([], $ok['missing']);

        $missingOne = $full;
        unset($missingOne['cartography_required']);
        $bad = $svc->validateContract('task_contract', $missingOne);
        $this->assertFalse($bad['valid']);
        $this->assertSame(['cartography_required'], $bad['missing']);
        $this->assertContains('missing_required_field:cartography_required', $bad['reasons']);
    }

    /**
     * Doc "Contrato 2" + "Regras para IA": "Spec retroativa e falha de
     * processo." A spec that is COMPLETE but retroactive still fails the gate.
     */
    public function test_retroactive_spec_fails_even_when_field_complete(): void
    {
        $completeSpec = [
            'objective' => 'o', 'canonical_context' => 'c', 'expected_behavior' => 'b',
            'likely_files' => ['a.php'], 'inputs' => ['i'], 'outputs' => ['o'],
            'risks' => ['r'], 'tests' => ['t'], 'evidence_required' => ['e'],
            'rollback' => 'rb', 'completion_criteria' => ['cc'],
        ];

        // Forward spec with all fields -> valid.
        $forward = $this->service()->gateSpecBeforeCode($completeSpec);
        $this->assertTrue($forward['valid']);
        $this->assertFalse($forward['retroactive']);

        // Same complete spec, but declared after the code exists -> process failure.
        $retro = $this->service()->gateSpecBeforeCode($completeSpec + ['code_already_exists' => true]);
        $this->assertFalse($retro['valid']);
        $this->assertTrue($retro['retroactive']);
        $this->assertSame([], $retro['missing']);
        $this->assertContains('retroactive_spec_is_process_failure', $retro['reasons']);
    }

    /**
     * Doc "Contrato 5" + "Regras para IA": "Nao reduzir evidence para resumo
     * textual." A payload whose mechanical-proof fields (executed_commands,
     * test_result, changed_files) are all empty is textual-only and rejected,
     * even though every required key is present and a prose summary exists.
     */
    public function test_textual_only_evidence_is_rejected(): void
    {
        $svc = $this->service();

        // A prose summary with empty mechanical-proof fields is textual-only and
        // is flagged as such (the proof fields also read as missing — empty is
        // not "declared"). The textual-only rejection is the documented rule.
        $textualOnly = [
            'spec_id' => 's', 'task_id' => 't', 'agent_runner' => 'r',
            'changed_files' => [], 'executed_commands' => [], 'test_result' => '',
            'updated_docs' => ['d'], 'updated_cartography' => ['c'], 'errors' => ['none'],
            'residual_risk' => 'low', 'completion_decision' => 'done',
            'summary' => 'everything passed, trust me',
        ];
        $rejected = $svc->gateEvidence($textualOnly);
        $this->assertFalse($rejected['valid']);
        $this->assertTrue($rejected['textual_only']);
        $this->assertContains('evidence_reduced_to_textual_summary', $rejected['reasons']);

        // Add real mechanical proof + complete every required field -> accepted.
        $withProof = $textualOnly;
        $withProof['executed_commands'] = ['php artisan test'];
        $withProof['test_result'] = 'pass';
        $withProof['changed_files'] = ['a.php'];
        $withProof['errors'] = ['none'];
        $ok = $svc->gateEvidence($withProof);
        $this->assertTrue($ok['valid']);
        $this->assertFalse($ok['textual_only']);
    }

    /**
     * Doc "Contrato 3": "Diff fora do contrato e bloqueado, justificado ou
     * escalado." In-contract diffs pass; an out-of-contract diff is BLOCKED
     * (JUSTIFIED only with explicit justification); a diff into forbidden_files
     * is ESCALATED. Never silently allowed.
     */
    public function test_diff_disposition_blocked_justified_escalated(): void
    {
        $svc = $this->service();
        $contract = ['allowed_files' => ['src/a.php', 'src/b.php'], 'forbidden_files' => ['config/kernel.php']];

        $inside = $svc->classifyDiff($contract, ['src/a.php']);
        $this->assertTrue($inside['in_contract']);
        $this->assertSame('in_contract', $inside['disposition']);

        $outside = $svc->classifyDiff($contract, ['src/a.php', 'src/c.php']);
        $this->assertFalse($outside['in_contract']);
        $this->assertSame(AtlasProgrammingGovernanceSystemContractsService::DIFF_BLOCKED, $outside['disposition']);
        $this->assertSame(['src/c.php'], $outside['outside_files']);

        $justified = $svc->classifyDiff($contract, ['src/c.php'], justified: true);
        $this->assertSame(AtlasProgrammingGovernanceSystemContractsService::DIFF_JUSTIFIED, $justified['disposition']);

        $forbidden = $svc->classifyDiff($contract, ['src/a.php', 'config/kernel.php']);
        $this->assertSame(AtlasProgrammingGovernanceSystemContractsService::DIFF_ESCALATED, $forbidden['disposition']);
        $this->assertSame(['config/kernel.php'], $forbidden['forbidden_hits']);
    }

    /**
     * Doc "Contrato 6" + "Regras para IA": "Learning nao autoaplica governanca"
     * / "Nao promover learning sem review." A pending proposal does not apply
     * governance; only an explicitly accepted (reviewed) one does; a rejected
     * one does not.
     */
    public function test_learning_applies_governance_only_after_accepted_review(): void
    {
        $svc = $this->service();
        $base = ['trigger' => 'repair', 'observation' => 'weak gate', 'proposal' => 'tighten'];

        $pending = $svc->evaluateLearning($base + ['review_state' => 'pending_review']);
        $this->assertFalse($pending['applies_governance']);
        $this->assertContains('learning_not_reviewed', $pending['reasons']);

        $accepted = $svc->evaluateLearning($base + ['review_state' => 'accepted']);
        $this->assertTrue($accepted['applies_governance']);
        $this->assertSame([], $accepted['reasons']);

        $rejected = $svc->evaluateLearning($base + ['review_state' => 'rejected']);
        $this->assertFalse($rejected['applies_governance']);
        $this->assertContains('learning_not_accepted:rejected', $rejected['reasons']);
    }
}
