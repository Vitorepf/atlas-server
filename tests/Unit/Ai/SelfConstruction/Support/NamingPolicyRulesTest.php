<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\NamingPolicyRules;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit lock for {@see NamingPolicyRules} (string-only; no FS / DI / I/O).
 */
final class NamingPolicyRulesTest extends TestCase
{
    #[Test]
    public function is_in_scope_accepts_self_construction_php_paths(): void
    {
        $this->assertTrue(NamingPolicyRules::isInScope(
            'app/Services/Ai/SelfConstruction/ShortName.php',
        ));
        $this->assertTrue(NamingPolicyRules::isInScope(
            './app/Services/Ai/SelfConstruction/Sub/Foo.php',
        ));
    }

    #[Test]
    public function is_in_scope_rejects_outside_root_and_non_php(): void
    {
        $this->assertFalse(NamingPolicyRules::isInScope(
            'app/Services/Ai/Programming/Foo.php',
        ));
        $this->assertFalse(NamingPolicyRules::isInScope(
            'app/Services/Ai/SelfConstruction/Foo.txt',
        ));
        $this->assertFalse(NamingPolicyRules::isInScope(
            'app/Services/Ai/SelfConstruction',
        ));
    }

    #[Test]
    public function extract_family_returns_last_pascal_case_word(): void
    {
        $this->assertSame('Gate', NamingPolicyRules::extractFamily('AtlasSelfConstructionNamingPolicyGate'));
        $this->assertSame('Service', NamingPolicyRules::extractFamily('FooBarService'));
        $this->assertSame('Policy', NamingPolicyRules::extractFamily('Policy'));
    }

    #[Test]
    public function extract_family_falls_back_to_full_name_when_no_pascal_tail(): void
    {
        $this->assertSame('ABC', NamingPolicyRules::extractFamily('ABC'));
        $this->assertSame('lowercase', NamingPolicyRules::extractFamily('lowercase'));
    }

    #[Test]
    public function is_vague_generated_name_matches_bare_stems_only(): void
    {
        $this->assertTrue(NamingPolicyRules::isVagueGeneratedName('Helper'));
        $this->assertTrue(NamingPolicyRules::isVagueGeneratedName('Manager'));
        $this->assertTrue(NamingPolicyRules::isVagueGeneratedName('Util'));
        $this->assertFalse(NamingPolicyRules::isVagueGeneratedName('NamingPolicyHelper'));
        $this->assertFalse(NamingPolicyRules::isVagueGeneratedName('helper')); // case-sensitive
        $this->assertFalse(NamingPolicyRules::isVagueGeneratedName('ShortName'));
    }

    #[Test]
    public function has_forbidden_quarantine_name_requires_quarantine_token_outside_dir(): void
    {
        $this->assertTrue(NamingPolicyRules::hasForbiddenQuarantineName(
            'QuarantineDump',
            'app/Services/Ai/SelfConstruction/QuarantineDump.php',
        ));
        $this->assertTrue(NamingPolicyRules::hasForbiddenQuarantineName(
            'MyquarantineThing',
            'app/Services/Ai/SelfConstruction/MyquarantineThing.php',
        ));
        $this->assertFalse(NamingPolicyRules::hasForbiddenQuarantineName(
            'QuarantineDump',
            'app/Services/Ai/SelfConstruction/_quarantine/QuarantineDump.php',
        ));
        $this->assertFalse(NamingPolicyRules::hasForbiddenQuarantineName(
            'CleanName',
            'app/Services/Ai/SelfConstruction/CleanName.php',
        ));
    }

    #[Test]
    public function violates_layer_boundary_detects_foreign_suffixes(): void
    {
        $this->assertTrue(NamingPolicyRules::violatesLayerBoundary('FooController'));
        $this->assertTrue(NamingPolicyRules::violatesLayerBoundary('CreateUsersMigration'));
        $this->assertTrue(NamingPolicyRules::violatesLayerBoundary('UserModel'));
        $this->assertTrue(NamingPolicyRules::violatesLayerBoundary('AuthMiddleware'));
        $this->assertTrue(NamingPolicyRules::violatesLayerBoundary('HttpKernel'));
        $this->assertTrue(NamingPolicyRules::violatesLayerBoundary('AppServiceProvider'));
        $this->assertFalse(NamingPolicyRules::violatesLayerBoundary('NamingPolicyGate'));
        $this->assertFalse(NamingPolicyRules::violatesLayerBoundary('Controllerish'));
    }

    #[Test]
    public function concept_stem_strips_version_and_copy_markers(): void
    {
        $this->assertSame('FooReducer', NamingPolicyRules::conceptStem('FooReducer'));
        $this->assertSame('FooReducer', NamingPolicyRules::conceptStem('FooReducer2'));
        $this->assertSame('FooReducer', NamingPolicyRules::conceptStem('FooReducerV2'));
        $this->assertSame('FooReducer', NamingPolicyRules::conceptStem('FooReducerCopy'));
        $this->assertSame('FooReducer', NamingPolicyRules::conceptStem('FooReducerNew'));
        $this->assertSame('Foo', NamingPolicyRules::conceptStem('Foo10'));
    }

    #[Test]
    public function target_root_constant_is_canonical_self_construction_path(): void
    {
        $this->assertSame('app/Services/Ai/SelfConstruction', NamingPolicyRules::TARGET_ROOT);
    }
}
