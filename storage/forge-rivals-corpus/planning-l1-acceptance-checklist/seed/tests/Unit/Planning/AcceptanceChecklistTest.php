<?php

declare(strict_types=1);

namespace Tests\Unit\Planning;

use Tests\TestCase;

final class AcceptanceChecklistTest extends TestCase
{
    public function test_acceptance_checklist_matches_expected_planning_contract(): void
    {
        $path = base_path('docs/planning/inbox/feature.acceptance.md');

        $this->assertFileExists($path);
        $this->assertSame(
            <<<'MARKDOWN'
1. Validar que a exportacao inclui apenas tarefas abertas com prazo vencido.
2. Permitir filtrar a exportacao por dono sem tornar o filtro obrigatorio.
3. Informar no arquivo exportado o total de tarefas e o intervalo de datas usado.
4. Mascarar emails completos, mantendo apenas o dominio visivel para auditoria.
5. Bloquear exportacao vazia com mensagem clara para o operador.

MARKDOWN,
            file_get_contents($path),
        );
    }
}
