<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Repair;

use App\Services\Ai\EngineeringKernel\Repair\FailureBrainCorpus;
use App\Services\Ai\EngineeringKernel\Repair\FailureTaxonomy;
use App\Services\Ai\EngineeringKernel\Repair\RepairDiagnosisAdvisor;
use App\Services\Ai\EngineeringKernel\Repair\RepairDiagnosisStage;
use PHPUnit\Framework\TestCase;

/**
 * OBRA #4 S3 — RepairBrain. Prova os ACs congelados da spec:
 *   AC-3.1 assinatura de flake => estratégia re-run SEM provider (decisão determinística, zero modelo)
 *   AC-3.2 test_wrong NUNCA resulta em conserto automático do teste — escala; e um advisor
 *          malicioso não consegue induzir test_wrong (contest só destrava classes seguras)
 *   AC-3.3 corpus > 0 após diagnósticos; entrada carrega classe + estratégia + outcome
 */
final class RepairBrainSliceTest extends TestCase
{
    public function test_ac31_flake_signature_reruns_without_provider(): void
    {
        $d = (new RepairDiagnosisStage)->diagnose([
            'failure_output' => "PHPUnit timed out after 300 seconds\nconnection refused",
        ]);

        $this->assertSame(FailureTaxonomy::ENV_FLAKE, $d['class']);
        $this->assertSame(FailureTaxonomy::STRATEGY_RERUN_NO_PROVIDER, $d['strategy']);
        $this->assertSame('deterministic_rule', $d['decided_by'], 'zero modelo: regra determinística decidiu');
    }

    public function test_ac32_test_wrong_always_escalates_never_autofixes(): void
    {
        // Único caminho para test_wrong: sinal EXPLÍCITO.
        $d = (new RepairDiagnosisStage)->diagnose([
            'failure_output' => 'Failures: 1',
            'explicit_signals' => ['test_contradicts_frozen_spec'],
        ]);
        $this->assertSame(FailureTaxonomy::TEST_WRONG, $d['class']);
        $this->assertSame(FailureTaxonomy::STRATEGY_ESCALATE_NEVER_AUTOFIX, $d['strategy']);

        // Advisor MALICIOSO tenta contestar UNKNOWN com test_wrong => IGNORADO (um palpite de
        // modelo jamais autoriza tocar na régua). A classe fica unknown => regenerar com hint.
        $malicious = new class implements RepairDiagnosisAdvisor
        {
            public function contest(array $context): ?string
            {
                return FailureTaxonomy::TEST_WRONG;
            }
        };
        $d2 = (new RepairDiagnosisStage($malicious))->diagnose([
            'failure_output' => 'saida sem nenhum padrao reconhecivel',
        ]);
        $this->assertSame(FailureTaxonomy::UNKNOWN, $d2['class'], 'advisor não pode induzir test_wrong');
        $this->assertSame(FailureTaxonomy::STRATEGY_REGENERATE_WITH_HINT, $d2['strategy']);
    }

    public function test_advisor_contest_only_unlocks_safe_classes_on_unknown(): void
    {
        $advisor = new class implements RepairDiagnosisAdvisor
        {
            public function contest(array $context): ?string
            {
                return FailureTaxonomy::ENV_FLAKE;
            }
        };
        $d = (new RepairDiagnosisStage($advisor))->diagnose([
            'failure_output' => 'saida sem nenhum padrao reconhecivel',
        ]);
        $this->assertSame(FailureTaxonomy::ENV_FLAKE, $d['class']);
        $this->assertSame('advisor_contest', $d['decided_by']);

        // E o advisor NUNCA sobrescreve uma regra determinística já decidida.
        $d2 = (new RepairDiagnosisStage($advisor))->diagnose([
            'failure_output' => 'Failed asserting that 3 matches expected 2.',
        ]);
        $this->assertSame(FailureTaxonomy::IMPL_BUG, $d2['class'], 'regra determinística vence o advisor');
        $this->assertStringContainsString('asserção', $d2['hint'], 'hint dirigido à asserção, não ao log inteiro');
    }

    public function test_classification_covers_dependency_scope_and_default(): void
    {
        $stage = new RepairDiagnosisStage;

        $dep = $stage->diagnose(['failure_output' => "Class 'App\\Foo\\Bar' not found in vendor/autoload.php"]);
        $this->assertSame(FailureTaxonomy::DEPENDENCY_BROKEN, $dep['class']);
        $this->assertSame(FailureTaxonomy::STRATEGY_ABORT_BLOCKER, $dep['strategy']);

        $scope = $stage->diagnose(['failure_output' => 'fopen(): No such file or directory']);
        $this->assertSame(FailureTaxonomy::SCOPE_MISS, $scope['class']);
        $this->assertSame(FailureTaxonomy::STRATEGY_DECOMPOSE, $scope['strategy']);

        $spec = $stage->diagnose(['failure_output' => 'x', 'explicit_signals' => ['spec_ambiguous_or_wrong']]);
        $this->assertSame(FailureTaxonomy::SPEC_WRONG, $spec['class']);
        $this->assertSame(FailureTaxonomy::STRATEGY_CONTEST_SPEC, $spec['strategy']);
    }

    public function test_ac33_corpus_records_class_strategy_and_outcome(): void
    {
        $path = sys_get_temp_dir().'/atlas-fbrain-'.bin2hex(random_bytes(4)).'.jsonl';
        try {
            $corpus = new FailureBrainCorpus($path);
            $this->assertCount(0, $corpus->all(), 'corpus nasce vazio');

            $corpus->record([
                'failure_signature' => 'sig-1',
                'origin' => 'repo_verified_delivery',
                'class' => FailureTaxonomy::IMPL_BUG,
                'strategy' => FailureTaxonomy::STRATEGY_REGENERATE_WITH_HINT,
                'decided_by' => 'deterministic_rule',
                'outcome' => 'repaired',
            ]);

            $all = $corpus->all();
            $this->assertCount(1, $all, 'corpus > 0 após um diagnóstico');
            $this->assertSame(FailureTaxonomy::IMPL_BUG, $all[0]['class']);
            $this->assertSame(FailureTaxonomy::STRATEGY_REGENERATE_WITH_HINT, $all[0]['strategy']);
            $this->assertSame('repaired', $all[0]['outcome']);
        } finally {
            @unlink($path);
        }
    }
}
