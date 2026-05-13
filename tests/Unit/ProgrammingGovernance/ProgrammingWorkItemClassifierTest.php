<?php

namespace Tests\Unit\ProgrammingGovernance;

use App\Services\Ai\Programming\Governance\ProgrammingWorkItemClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProgrammingWorkItemClassifierTest extends TestCase
{
    private ProgrammingWorkItemClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ProgrammingWorkItemClassifier();
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function intentTypeMatrixProvider(): array
    {
        return [
            'pt_bugfix' => ['Corrija o bug do gate de receipt', 'bugfix'],
            'en_bugfix' => ['Fix crash in the verify command', 'bugfix'],
            'pt_feature' => ['Adicionar nova capability de export', 'feature'],
            'en_feature' => ['Add new feature for evidence ledger', 'feature'],
            'pt_refactor' => ['Refatorar o classifier para suportar i18n', 'refactor'],
            'en_refactor' => ['Refactor the orchestrator into smaller services', 'refactor'],
            'pt_docs' => ['Atualizar documentação canônica do runbook', 'docs'],
            'en_docs' => ['Update README with new command list', 'docs'],
            'pt_migration' => ['Criar migração para tabela de evidence', 'migration'],
            'en_migration' => ['Add a migration that creates the schema', 'migration'],
            'pt_test' => ['Adicionar testes unitários para o classifier', 'test'],
            'pt_architecture' => ['Redesenhar a arquitetura do harness runner', 'architecture'],
            'pt_cartography' => ['Publicar cartografia visual dos módulos', 'cartography'],
            'self_construction' => ['Self-construct a new evidence pipeline', 'self_construction'],
            'other' => ['Estado ambíguo sem palavra-chave', 'other'],
        ];
    }

    #[DataProvider('intentTypeMatrixProvider')]
    public function test_classifies_intent_type_from_text(string $intent, string $expectedType): void
    {
        $result = $this->classifier->classify($intent);

        $this->assertSame($expectedType, $result['intent_type']);
    }

    public function test_explicit_type_override_wins_over_keywords(): void
    {
        $result = $this->classifier->classify('refator do orchestrator', ['type' => 'bugfix']);

        $this->assertSame('bugfix', $result['intent_type']);
    }

    public function test_explicit_unknown_type_falls_back_to_other(): void
    {
        $result = $this->classifier->classify('refator do orchestrator', ['type' => 'nonsense']);

        $this->assertSame('other', $result['intent_type']);
    }

    public function test_structural_signals_promote_scope_mode_to_structural(): void
    {
        $result = $this->classifier->classify('alterar schema da tabela atlas_xpto');

        $this->assertSame('structural', $result['scope_mode']);
        $this->assertContains('schema', $result['signals']['structural_matches']);
    }

    public function test_typo_fix_is_compact(): void
    {
        $result = $this->classifier->classify('Conserte typo no comentário do AtlasFoo');

        $this->assertSame('bugfix', $result['intent_type']);
        $this->assertSame('compact', $result['scope_mode']);
    }

    public function test_feature_is_structural_by_default(): void
    {
        $result = $this->classifier->classify('Adicionar nova feature de evidence ledger');

        $this->assertSame('feature', $result['intent_type']);
        $this->assertSame('structural', $result['scope_mode']);
    }

    public function test_mode_override_wins(): void
    {
        $result = $this->classifier->classify('alterar schema crítico', ['mode' => 'compact']);

        $this->assertSame('compact', $result['scope_mode']);
    }

    public function test_security_keyword_promotes_risk_to_high(): void
    {
        $result = $this->classifier->classify('corrigir vulnerabilidade de security na auth');

        $this->assertSame('high', $result['risk_level']);
    }

    public function test_structural_default_risk_is_medium(): void
    {
        $result = $this->classifier->classify('Adicionar nova feature de export pdf');

        $this->assertSame('structural', $result['scope_mode']);
        $this->assertSame('medium', $result['risk_level']);
    }

    public function test_docs_structural_is_low_risk(): void
    {
        $result = $this->classifier->classify('Atualizar documentação canônica do runbook');

        $this->assertSame('docs', $result['intent_type']);
        $this->assertSame('low', $result['risk_level']);
    }

    public function test_risk_override_wins(): void
    {
        $result = $this->classifier->classify('feature simples', ['risk' => 'critical']);

        $this->assertSame('critical', $result['risk_level']);
    }
}
