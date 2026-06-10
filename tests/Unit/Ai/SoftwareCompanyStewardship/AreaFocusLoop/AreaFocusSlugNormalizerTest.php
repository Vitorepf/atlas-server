<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSlugNormalizer;
use Tests\TestCase;

final class AreaFocusSlugNormalizerTest extends TestCase
{
    public function test_area_id_token_matches_lane_slug_contract(): void
    {
        $this->assertSame(
            'agentic_engineering-os',
            AreaFocusSlugNormalizer::areaIdToken(' Agentic Engineering-OS ', 'default_area'),
        );
        $this->assertSame('default_area', AreaFocusSlugNormalizer::areaIdToken('---', 'default_area'));
        $this->assertSame('alpha_beta-gamma', AreaFocusSlugNormalizer::areaIdToken('__alpha beta-gamma__', 'default_area'));
    }

    public function test_area_ref_token_matches_queue_and_safety_contract(): void
    {
        $this->assertSame(
            'agentic:engineering-os',
            AreaFocusSlugNormalizer::areaRefToken(' Agentic:Engineering-OS ', 'default_area'),
        );
        $this->assertSame('-', AreaFocusSlugNormalizer::areaRefToken('-', 'default_area'));
        $this->assertSame('default_area', AreaFocusSlugNormalizer::areaRefToken('___', 'default_area'));
    }

    public function test_area_dash_token_matches_release_and_preflight_contract(): void
    {
        $this->assertSame('agentic-engineering-os', AreaFocusSlugNormalizer::areaDashToken(' Agentic Engineering OS '));
        $this->assertSame('area', AreaFocusSlugNormalizer::areaDashToken('---'));
    }

    public function test_alnum_separated_token_matches_branch_and_sandbox_contract(): void
    {
        $this->assertSame('agentic-engineering-os', AreaFocusSlugNormalizer::alnumSeparatedToken(' Agentic Engineering OS '));
        $this->assertSame('sandbox_123', AreaFocusSlugNormalizer::alnumSeparatedToken(' Sandbox 123 ', '_'));
        $this->assertSame('fallback', AreaFocusSlugNormalizer::alnumSeparatedToken('---', '-', 'fallback'));
    }

    public function test_lower_snake_token_matches_legacy_slug_contract(): void
    {
        $this->assertSame('alpha_beta_42', AreaFocusSlugNormalizer::lowerSnakeToken(' Alpha Beta 42 '));
        $this->assertSame('scope_creep_guard', AreaFocusSlugNormalizer::lowerSnakeToken('scope:creep/guard'));
        $this->assertSame('', AreaFocusSlugNormalizer::lowerSnakeToken('---'));
    }

    public function test_lower_snake_token_or_fallback_matches_ledger_slug_contract(): void
    {
        $this->assertSame('alpha_beta', AreaFocusSlugNormalizer::lowerSnakeTokenOrFallback('Alpha-Beta', 'fallback'));
        $this->assertSame('fallback', AreaFocusSlugNormalizer::lowerSnakeTokenOrFallback('---', 'fallback'));
    }

    public function test_lower_snake_token_preserving_boundary_keeps_legacy_file_key_boundaries(): void
    {
        $this->assertSame('alpha_beta', AreaFocusSlugNormalizer::lowerSnakeTokenPreservingBoundary(' Alpha-Beta ', 'fallback'));
        $this->assertSame('_alpha_', AreaFocusSlugNormalizer::lowerSnakeTokenPreservingBoundary(' !Alpha! ', 'fallback'));
        $this->assertSame('_', AreaFocusSlugNormalizer::lowerSnakeTokenPreservingBoundary('---', 'fallback'));
        $this->assertSame('fallback', AreaFocusSlugNormalizer::lowerSnakeTokenPreservingBoundary('', 'fallback'));
    }

    public function test_lower_path_component_token_preserves_dot_dash_and_underscore(): void
    {
        $this->assertSame('run.01-alpha_beta', AreaFocusSlugNormalizer::lowerPathComponentToken('Run.01-Alpha_Beta', 'fallback'));
        $this->assertSame('run_01', AreaFocusSlugNormalizer::lowerPathComponentToken('Run/01', 'fallback'));
        $this->assertSame('_', AreaFocusSlugNormalizer::lowerPathComponentToken(' ', 'fallback'));
        $this->assertSame('fallback', AreaFocusSlugNormalizer::lowerPathComponentToken('', 'fallback'));
    }

    public function test_lower_file_token_preserves_legacy_filename_slug_contract(): void
    {
        $this->assertSame('agentic_engineering_os', AreaFocusSlugNormalizer::lowerFileToken('Agentic Engineering OS', 'fallback'));
        $this->assertSame('---', AreaFocusSlugNormalizer::lowerFileToken('---', 'fallback'));
        $this->assertSame('fallback', AreaFocusSlugNormalizer::lowerFileToken('___', 'fallback'));
    }

    public function test_lower_underscore_token_preserves_underscore_contract_with_explicit_trim_modes(): void
    {
        $this->assertSame('alpha__beta', AreaFocusSlugNormalizer::lowerUnderscoreToken(' Alpha__Beta ', 'fallback'));
        $this->assertSame('_alpha_', AreaFocusSlugNormalizer::lowerUnderscoreToken(' !Alpha! ', 'fallback', true, false));
        $this->assertSame('_alpha_', AreaFocusSlugNormalizer::lowerUnderscoreToken(' Alpha ', 'fallback', false, false));
        $this->assertSame('fallback', AreaFocusSlugNormalizer::lowerUnderscoreToken('!!!', 'fallback'));
        $this->assertSame('_', AreaFocusSlugNormalizer::lowerUnderscoreToken('!!!', 'fallback', true, false));
    }

    public function test_space_dash_snake_token_preserves_non_space_dash_characters(): void
    {
        $this->assertSame('alpha_beta/gamma:delta', AreaFocusSlugNormalizer::spaceDashSnakeToken(' Alpha-Beta/Gamma:Delta '));
        $this->assertSame('already_snake', AreaFocusSlugNormalizer::spaceDashSnakeToken('__Already_Snake__'));
    }

    public function test_unscoped_token_matches_ledger_contract(): void
    {
        $this->assertSame('component_value-01', AreaFocusSlugNormalizer::unscopedToken(' Component Value-01 '));
        $this->assertSame('unscoped', AreaFocusSlugNormalizer::unscopedToken('___'));
    }
}
