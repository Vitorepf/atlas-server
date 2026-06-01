<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasContextPackagesAndProjectionsService;
use Tests\TestCase;

/**
 * Pins the documented contracts: the ten-section package shape, the React +
 * Laravel UI save package selection, and the five Projection Law rules.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md
 */
class AtlasContextPackagesAndProjectionsTest extends TestCase
{
    private function service(): AtlasContextPackagesAndProjectionsService
    {
        return new AtlasContextPackagesAndProjectionsService;
    }

    /**
     * @return array<string,mixed>
     */
    private function completePackage(): array
    {
        $pkg = ['name' => 'laravel-api-v1'];
        foreach (AtlasContextPackagesAndProjectionsService::REQUIRED_PACKAGE_FIELDS as $field) {
            $pkg[$field] = "value for {$field}";
        }

        return $pkg;
    }

    public function test_complete_package_is_valid_and_usable_for_briefing(): void
    {
        $r = $this->service()->validatePackage($this->completePackage());

        $this->assertTrue($r['valid']);
        $this->assertTrue($r['usable_for_briefing']);
        $this->assertSame([], $r['missing_fields']);
        // The doc lists exactly ten required sections.
        $this->assertCount(10, $r['defined_fields']);
    }

    public function test_package_missing_any_required_section_is_invalid(): void
    {
        $pkg = $this->completePackage();
        // Drop two documented sections.
        unset($pkg['bad_examples'], $pkg['evaluation_criteria']);

        $r = $this->service()->validatePackage($pkg);

        $this->assertFalse($r['valid']);
        // An incomplete package must NOT brief an agent.
        $this->assertFalse($r['usable_for_briefing']);
        $this->assertContains('bad_examples', $r['missing_fields']);
        $this->assertContains('evaluation_criteria', $r['missing_fields']);
    }

    public function test_react_laravel_ui_save_selects_the_documented_six_packages(): void
    {
        $r = $this->service()->selectPackages([
            'stack' => ['react', 'typescript', 'laravel'],
            'changed_file_types' => ['tsx', 'php', 'save'],
            'risk_level' => 'medium',
            'has_receipt' => true,
        ]);

        // The doc pins this exact set for a React + Laravel UI save action.
        $this->assertEqualsCanonicalizing(
            AtlasContextPackagesAndProjectionsService::REACT_LARAVEL_UI_SAVE_PACKAGES,
            $r['packages'],
        );
        $this->assertSame(6, $r['count']);
    }

    public function test_projection_missing_source_or_timestamp_is_rejected(): void
    {
        $r = $this->service()->evaluateProjection([
            // no declared_source / declared_timestamp
            'drift_checked' => true,
            'drift_detected' => false,
            'risk_level' => 'low',
        ]);

        $this->assertSame(AtlasContextPackagesAndProjectionsService::PROJECTION_REJECT, $r['verdict']);
        $this->assertFalse($r['admissible']);
        $this->assertContains('must_declare_source_and_timestamp', $r['violated_rules']);
        // Rule 1 holds regardless of verdict.
        $this->assertTrue($r['canonical_outranks_projection']);
    }

    public function test_risky_execution_without_canonical_verification_is_rejected(): void
    {
        $base = [
            'declared_source' => 'docs/.../context-packages-and-projections.md',
            'declared_timestamp' => '2026-06-01T00:00:00Z',
            'drift_checked' => true,
            'drift_detected' => false,
            'risk_level' => 'high',
        ];

        $unverified = $this->service()->evaluateProjection($base + ['verified_against_canonical' => false]);
        $this->assertFalse($unverified['admissible']);
        $this->assertTrue($unverified['risky_execution']);
        $this->assertContains('verify_canonical_before_risky_execution', $unverified['violated_rules']);

        // Same projection, now verified against canon => admissible.
        $verified = $this->service()->evaluateProjection($base + ['verified_against_canonical' => true]);
        $this->assertTrue($verified['admissible']);
        $this->assertSame(AtlasContextPackagesAndProjectionsService::PROJECTION_ADMIT, $verified['verdict']);
    }

    public function test_silent_permission_or_tool_escalation_is_rejected(): void
    {
        $r = $this->service()->evaluateProjection([
            'declared_source' => 'docs/.../context-packages-and-projections.md',
            'declared_timestamp' => '2026-06-01T00:00:00Z',
            'drift_checked' => true,
            'drift_detected' => false,
            'verified_against_canonical' => true,
            'risk_level' => 'low',
            'added_tools' => ['apply_patch'],
        ]);

        $this->assertFalse($r['admissible']);
        $this->assertContains('no_silent_permission_escalation', $r['violated_rules']);
    }
}
