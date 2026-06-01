<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendImpeccableDetectorExtensionService;
use Tests\TestCase;

/**
 * Pins the doc's concrete decisions: FLUXO engine selection + exit-2-on-findings,
 * REGRAS PARA IA (#4 browser > regex, #5 pixel contrast, #1 evidence-not-proof,
 * #2/#3 every finding carries severity/impact/false-positive-policy) and the
 * EVIDENCIAS 29 = 16 slop + 13 quality catalogue. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-detector-extension.md
 */
class AtlasProgrammingFrontendImpeccableDetectorExtensionTest extends TestCase
{
    private function service(): AtlasProgrammingFrontendImpeccableDetectorExtensionService
    {
        return new AtlasProgrammingFrontendImpeccableDetectorExtensionService;
    }

    public function test_engine_selection_routes_each_target_kind_to_the_documented_engine(): void
    {
        $service = $this->service();

        // FLUXO: target file/dir/url/stdin -> choose engine.
        $url = $service->chooseEngine('https://localhost:5173/app');
        $this->assertSame('url', $url['target_kind']);
        $this->assertSame(AtlasProgrammingFrontendImpeccableDetectorExtensionService::ENGINE_BROWSER, $url['engine']);

        $html = $service->chooseEngine('public/index.html');
        $this->assertSame('html_file', $html['target_kind']);
        $this->assertSame(AtlasProgrammingFrontendImpeccableDetectorExtensionService::ENGINE_STATIC_HTML, $html['engine']);

        $screenshot = $service->chooseEngine('artifacts/home.png');
        $this->assertSame('screenshot', $screenshot['target_kind']);
        $this->assertSame(AtlasProgrammingFrontendImpeccableDetectorExtensionService::ENGINE_VISUAL, $screenshot['engine']);

        $stdin = $service->chooseEngine('-');
        $this->assertSame('stdin', $stdin['target_kind']);
        $this->assertSame(AtlasProgrammingFrontendImpeccableDetectorExtensionService::ENGINE_REGEX, $stdin['engine']);

        $dir = $service->chooseEngine('src/');
        $this->assertSame('directory', $dir['target_kind']);
        $this->assertSame(AtlasProgrammingFrontendImpeccableDetectorExtensionService::ENGINE_REGEX, $dir['engine']);

        $file = $service->chooseEngine('src/Button.tsx');
        $this->assertSame('source_file', $file['target_kind']);
        $this->assertSame(AtlasProgrammingFrontendImpeccableDetectorExtensionService::ENGINE_REGEX, $file['engine']);
    }

    public function test_exit_code_is_2_when_findings_exist_and_0_when_clean(): void
    {
        $service = $this->service();

        $clean = $service->assembleReport('src/', 'regex', []);
        $this->assertFalse($clean['has_findings']);
        $this->assertSame(AtlasProgrammingFrontendImpeccableDetectorExtensionService::EXIT_CLEAN, $clean['exit_code']);
        $this->assertSame('none', $clean['severity']);

        $withFindings = $service->assembleReport('src/', 'regex', [[
            'rule_id' => 'gradient_text',
            'severity' => 'medium',
            'impact' => 'generic gradient weakens brand originality.',
            'false_positive_policy' => 'allow only with brand rationale.',
            'evidence_refs' => ['anti_slop_report'],
        ]]);
        $this->assertTrue($withFindings['has_findings']);
        $this->assertSame(AtlasProgrammingFrontendImpeccableDetectorExtensionService::EXIT_FINDINGS, $withFindings['exit_code']);
        $this->assertSame('medium', $withFindings['severity']);
        $this->assertSame(['anti_slop_report'], $withFindings['evidence_refs']);
    }

    public function test_finding_missing_severity_impact_or_false_positive_policy_is_rejected_not_silently_passed(): void
    {
        $service = $this->service();

        // REGRAS #2 & #3: severity + impact + false-positive policy are required.
        $report = $service->assembleReport('src/', 'regex', [
            ['rule_id' => 'no_severity', 'impact' => 'x', 'false_positive_policy' => 'y'],            // missing severity
            ['rule_id' => 'no_impact', 'severity' => 'high', 'false_positive_policy' => 'y'],          // missing impact
            ['rule_id' => 'no_fp', 'severity' => 'low', 'impact' => 'x'],                              // missing false_positive_policy
        ]);

        // None are accepted as real findings; all three are recorded as malformed.
        $this->assertSame(0, $report['finding_count']);
        $this->assertSame(3, $report['malformed_count']);
        $this->assertFalse($report['claim_policy']['malformed_finding_cannot_become_silent_pass']);
        $this->assertContains('severity', $report['malformed_findings'][0]['missing']);
        $this->assertContains('impact', $report['malformed_findings'][1]['missing']);
        $this->assertContains('false_positive_policy', $report['malformed_findings'][2]['missing']);
    }

    public function test_browser_is_stronger_than_regex_for_layout_and_visual_is_required_for_contrast(): void
    {
        $service = $this->service();

        // REGRA #4: a layout claim from regex is insufficient; escalate to browser.
        $regexLayout = $service->evaluateClaimEngine('layout', 'regex');
        $this->assertFalse($regexLayout['sufficient']);
        $this->assertSame(AtlasProgrammingFrontendImpeccableDetectorExtensionService::ENGINE_BROWSER, $regexLayout['escalate_to']);

        $browserLayout = $service->evaluateClaimEngine('layout', 'browser');
        $this->assertTrue($browserLayout['sufficient']);
        $this->assertNull($browserLayout['escalate_to']);

        // REGRA #5: contrast requires the visual pixel engine; even browser is not enough.
        $browserContrast = $service->evaluateClaimEngine('contrast', 'browser');
        $this->assertFalse($browserContrast['sufficient']);
        $this->assertSame(AtlasProgrammingFrontendImpeccableDetectorExtensionService::ENGINE_VISUAL, $browserContrast['escalate_to']);

        $visualContrast = $service->evaluateClaimEngine('contrast', 'visual');
        $this->assertTrue($visualContrast['sufficient']);

        // A purely textual slop claim is fine from regex.
        $regexSlop = $service->evaluateClaimEngine('slop', 'regex');
        $this->assertTrue($regexSlop['sufficient']);
    }

    public function test_rule_catalogue_is_exactly_29_split_16_slop_13_quality(): void
    {
        $service = $this->service();
        $payload = $service->describe();

        // EVIDENCIAS: "29 regras auditadas: 16 slop, 13 quality."
        $this->assertSame(29, $payload['rule_catalogue']['total']);
        $this->assertSame(16, $payload['rule_catalogue']['slop']);
        $this->assertSame(13, $payload['rule_catalogue']['quality']);
        $this->assertTrue($payload['rule_catalogue']['sum_matches_total']);
    }

    public function test_detector_is_evidence_not_final_proof_and_never_authorizes_runtime(): void
    {
        $service = $this->service();

        // REGRA #1: detector is evidence, not final-design proof.
        $report = $service->assembleReport('https://localhost/', 'browser', []);
        $this->assertTrue($report['claim_policy']['detector_is_evidence_not_final_design_proof']);
        $this->assertTrue($report['claim_policy']['clean_detector_alone_is_not_completion_proof']);
        $this->assertFalse($report['runtime_authorized']);

        $this->assertFalse($service->chooseEngine('https://localhost/')['runtime_authorized']);
        $this->assertFalse($service->describe()['runtime_authorized']);
        $this->assertFalse($service->evaluateClaimEngine('layout', 'regex')['runtime_authorized']);
    }
}
