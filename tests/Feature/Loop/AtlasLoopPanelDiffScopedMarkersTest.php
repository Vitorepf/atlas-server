<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AdversarialProofPanelService;
use Tests\TestCase;

/**
 * AUTÓPSIA 12/06 — causa-raiz #2 do "0 propostas": o verifier regression_detection do
 * painel adversarial checava markers de incompletude (TODO/FIXME) no ARQUIVO INTEIRO.
 * Alvos reais do Atlas carregam TODOs legítimos pré-existentes ⇒ toda proposta de
 * discovery era refutada para sempre, independente da qualidade do diff.
 *
 * Contrato congelado: com `changed_added_lines` no cycle, o scan é DIFF-SCOPED — só o
 * que a mudança ADICIONOU pode refutá-la. Sem a chave, o file-scoped original continua
 * (callers legados preservados; um diff que ADICIONA TODO continua refutado).
 */
final class AtlasLoopPanelDiffScopedMarkersTest extends TestCase
{
    private function refute(array $cycle): array
    {
        return app(AdversarialProofPanelService::class)->refute(array_merge([
            'cycle_id' => 'c-test',
            'objective' => 'melhorar X',
            'changed_files' => array_keys($cycle['changed_file_contents'] ?? []),
            'allowed_files' => array_keys($cycle['changed_file_contents'] ?? []),
            'selected_finding' => ['affected_files' => array_keys($cycle['changed_file_contents'] ?? [])],
            'validation' => ['ran' => true, 'passed' => true, 'commands' => ['php t.php']],
            'outcome_measured' => false,
        ], $cycle));
    }

    private function regressionVerdict(array $panel): array
    {
        foreach ((array) ($panel['verifier_verdicts'] ?? []) as $v) {
            if (($v['verifier'] ?? '') === 'regression_detection') {
                return $v;
            }
        }

        return ['refuted' => null, 'detail' => 'missing'];
    }

    public function test_preexisting_todo_does_not_refute_when_diff_added_lines_are_clean(): void
    {
        $panel = $this->refute([
            'changed_file_contents' => [
                'src/Service.php' => "<?php\n// TODO legado que o diff NÃO tocou\nfunction f() { return 2; }\n",
            ],
            'changed_added_lines' => [
                'src/Service.php' => "\nfunction f() { return 2; }",
            ],
        ]);

        $v = $this->regressionVerdict($panel);
        $this->assertFalse((bool) $v['refuted'], 'TODO pré-existente não refuta um diff limpo: '.$v['detail']);
    }

    public function test_todo_added_by_the_diff_still_refutes(): void
    {
        $panel = $this->refute([
            'changed_file_contents' => [
                'src/Service.php' => "<?php\nfunction f() { /* TODO depois */ return 2; }\n",
            ],
            'changed_added_lines' => [
                'src/Service.php' => "\nfunction f() { /* TODO depois */ return 2; }",
            ],
        ]);

        $v = $this->regressionVerdict($panel);
        $this->assertTrue((bool) $v['refuted'], 'TODO ADICIONADO pelo diff continua refutado (anti-incompletude intacto)');
    }

    public function test_legacy_callers_without_added_lines_keep_file_scoped_behavior(): void
    {
        $panel = $this->refute([
            'changed_file_contents' => [
                'src/Service.php' => "<?php\n// TODO qualquer\nfunction f() {}\n",
            ],
            // sem changed_added_lines
        ]);

        $v = $this->regressionVerdict($panel);
        $this->assertTrue((bool) $v['refuted'], 'sem a chave, o comportamento file-scoped original é preservado');
    }
}
