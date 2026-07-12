<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use ReflectionClass;
use Tests\TestCase;

/**
 * MAXC-05 — Sufficiency section rendered in the AOBG context pack markdown.
 *
 * Prova o contrato do rendering:
 * - `## Suficiência` só aparece quando `sufficiency.not_enough_context=true`;
 *   pacote coberto NÃO ganha ruído extra (progressive disclosure);
 * - a seção NOMEIA as facetas faltando e emite os `expand:*` handles literalmente
 *   — o agente externo vê "não achei X" com AÇÃO nomeada, não silêncio.
 * - o hook transporta o markdown inalterado (contrato invariante); mudança de
 *   hash do CTX é esperada quando `not_enough_context=true` — declarado no plano.
 */
final class Maxc05SufficiencyMarkdownTest extends TestCase
{
    private function render(array $pack): string
    {
        $service = app(AtlasOpenBrainContextPackService::class);
        $method = (new ReflectionClass($service))->getMethod('renderMarkdown');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $pack);
    }

    public function test_sufficiency_section_is_omitted_when_not_present(): void
    {
        $md = $this->render([
            'task' => 't',
            'workspace' => 'atlas-server',
        ]);

        $this->assertStringNotContainsString('## Suficiência', $md);
    }

    public function test_sufficiency_section_is_omitted_when_covered(): void
    {
        $md = $this->render([
            'task' => 't',
            'workspace' => 'atlas-server',
            'sufficiency' => [
                'present' => true,
                'not_enough_context' => false,
                'missing_essential' => [],
                'handles' => [],
            ],
        ]);

        $this->assertStringNotContainsString('## Suficiência', $md);
    }

    public function test_sufficiency_section_names_gaps_and_handles_when_uncovered(): void
    {
        $md = $this->render([
            'task' => 'refactor UserContextService',
            'workspace' => 'atlas-server',
            'sufficiency' => [
                'present' => true,
                'not_enough_context' => true,
                'missing_essential' => [
                    ['type' => 'symbol', 'value' => 'App\\Services\\UserContextService', 'handle' => 'expand:symbol:App\\Services\\UserContextService'],
                    ['type' => 'path', 'value' => 'app/Services/UserContextService.php', 'handle' => 'expand:path:app/Services/UserContextService.php'],
                ],
                'handles' => [
                    'expand:symbol:App\\Services\\UserContextService',
                    'expand:path:app/Services/UserContextService.php',
                ],
            ],
        ]);

        $this->assertStringContainsString('## Suficiência', $md);
        $this->assertStringContainsString('not_enough_context=true', $md);
        $this->assertStringContainsString('symbol=App\\Services\\UserContextService', $md);
        $this->assertStringContainsString('expand:symbol:App\\Services\\UserContextService', $md);
        $this->assertStringContainsString('expand:path:app/Services/UserContextService.php', $md);
        $this->assertStringContainsString('expand_handles:', $md);
        $this->assertStringContainsString('workflow:', $md);
    }
}
