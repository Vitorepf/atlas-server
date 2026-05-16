<?php

declare(strict_types=1);

namespace Tests\Unit\Planning;

use PHPUnit\Framework\TestCase;

/**
 * Golden checker for docs/planning/inbox/feature.risks.md.
 *
 * Format expected for each risk:
 *
 *   ## risk-1 — short title
 *   - likelihood: medium
 *   - impact: high
 *   - mitigation: rate-limit signup endpoint to 5 req/min per ip
 *   - owner: growth
 */
final class RiskRegisterTest extends TestCase
{
    private const RISKS_PATH = __DIR__.'/../../../docs/planning/inbox/feature.risks.md';

    private const ALLOWED_LIKELIHOOD = ['low', 'medium', 'high'];

    private const ALLOWED_IMPACT = ['low', 'medium', 'high', 'critical'];

    public function test_file_exists(): void
    {
        $this->assertFileExists(self::RISKS_PATH);
    }

    public function test_count_in_range(): void
    {
        $risks = $this->parse((string) file_get_contents(self::RISKS_PATH));
        $this->assertGreaterThanOrEqual(4, count($risks), 'expected at least 4 risks');
        $this->assertLessThanOrEqual(8, count($risks), 'expected at most 8 risks');
    }

    public function test_each_risk_has_required_fields_in_allowed_ranges(): void
    {
        $risks = $this->parse((string) file_get_contents(self::RISKS_PATH));
        foreach ($risks as $risk) {
            $this->assertNotSame('', $risk['id']);
            $this->assertContains($risk['likelihood'], self::ALLOWED_LIKELIHOOD, "risk {$risk['id']} bad likelihood");
            $this->assertContains($risk['impact'], self::ALLOWED_IMPACT, "risk {$risk['id']} bad impact");
            $this->assertNotSame('', $risk['mitigation'], "risk {$risk['id']} mitigation missing");
            $this->assertNotSame('monitor', strtolower($risk['mitigation']), "risk {$risk['id']} mitigation is just 'monitor'");
            $this->assertNotSame('', $risk['owner']);
        }
    }

    public function test_at_least_one_critical_or_high_likelihood_risk(): void
    {
        $risks = $this->parse((string) file_get_contents(self::RISKS_PATH));
        $hits = array_filter(
            $risks,
            static fn (array $r): bool => $r['impact'] === 'critical' || $r['likelihood'] === 'high',
        );
        $this->assertGreaterThanOrEqual(1, count($hits), 'no high-severity risk in register');
    }

    public function test_high_severity_risks_have_concrete_mitigation(): void
    {
        $risks = $this->parse((string) file_get_contents(self::RISKS_PATH));
        foreach ($risks as $risk) {
            if (! in_array($risk['impact'], ['high', 'critical'], true) && $risk['likelihood'] !== 'high') {
                continue;
            }
            $this->assertGreaterThan(10, strlen($risk['mitigation']), "risk {$risk['id']} mitigation too thin");
        }
    }

    /** @return list<array{id:string,likelihood:string,impact:string,mitigation:string,owner:string}> */
    private function parse(string $md): array
    {
        $risks = [];
        $current = null;
        foreach (preg_split('/\R/', $md) ?: [] as $line) {
            if (preg_match('/^##\s+(risk-\S+)/', $line, $m)) {
                if ($current !== null) {
                    $risks[] = $current;
                }
                $current = ['id' => $m[1], 'likelihood' => '', 'impact' => '', 'mitigation' => '', 'owner' => ''];

                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^- (likelihood|impact|mitigation|owner):\s*(.+)$/i', $line, $m)) {
                $current[strtolower($m[1])] = trim($m[2]);
            }
        }
        if ($current !== null) {
            $risks[] = $current;
        }

        return $risks;
    }
}
