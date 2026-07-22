<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use Tests\TestCase;

/**
 * TETO-09 — aceite: áreas 2/11/14 com veredito explícito; caso negativo = área ausente.
 */
final class Teto09AreaVerdictAcceptanceTest extends TestCase
{
    private const REQUIRED = [2, 11, 14];

    public function test_plan_declares_hardening_verdicts_for_areas_2_11_14(): void
    {
        $planPath = dirname(__DIR__, 5).'/docs/engineering-knowledge-base/atlas-acos-max-frontier-plan-v1.md';
        $this->assertFileExists($planPath);
        $plan = (string) file_get_contents($planPath);
        $this->assertStringContainsString('### TETO-09 — Vereditos assinados', $plan);

        $ids = $this->areaIdsFromPlanTable($plan);
        sort($ids);
        $this->assertSame(self::REQUIRED, $ids, 'TETO-09 exige veredito explícito nas áreas 2, 11 e 14');

        foreach (['Captura & Imunidade', 'Evidência & Certificação Longitudinal', 'Porta do Cérebro & Provider-safety'] as $name) {
            $this->assertStringContainsString($name, $plan);
        }
        $this->assertStringContainsString('teto = endurecimento', $plan);
        $this->assertStringContainsString('MULTX-02', $plan);
    }

    public function test_negative_case_missing_area_fails_acceptance(): void
    {
        $incomplete = [2, 11];
        sort($incomplete);
        $this->assertNotSame(self::REQUIRED, $incomplete);
        $this->assertFalse($this->accepts($incomplete));
        $this->assertTrue($this->accepts(self::REQUIRED));
    }

    /**
     * @param  list<int>  $areaIds
     */
    private function accepts(array $areaIds): bool
    {
        $ids = array_values(array_unique(array_map('intval', $areaIds)));
        sort($ids);

        return $ids === self::REQUIRED;
    }

    /**
     * @return list<int>
     */
    private function areaIdsFromPlanTable(string $plan): array
    {
        if (! preg_match('/### TETO-09 — Vereditos assinados.*?\n\n---/s', $plan, $block)) {
            $this->fail('Bloco TETO-09 vereditos ausente no plano');
        }

        preg_match_all('/^\| \*\*(\d+)\*\* /m', $block[0], $matches);

        return array_map('intval', $matches[1] ?? []);
    }
}
