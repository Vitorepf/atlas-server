<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\Governance\AtlasAaeosAcosLaneScope;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosAcosLaneScopeTest extends TestCase
{
    public function test_infer_returns_slug_when_all_production_files_in_lane(): void
    {
        $slug = AtlasAaeosAcosLaneScope::inferFromAllowedFiles([
            'app/Services/Ai/Aaeos/AtlasDepartmentQualityBarService.php',
            'app/Services/Ai/Cognition/AtlasAcosWindowGatesService.php',
            'tests/Unit/Ai/Aaeos/SomethingTest.php',
        ]);

        $this->assertSame(AtlasAaeosAcosLaneScope::SLUG, $slug);
    }

    public function test_infer_returns_null_when_file_escapes_lane(): void
    {
        $slug = AtlasAaeosAcosLaneScope::inferFromAllowedFiles([
            'app/Services/Ai/Aaeos/Foo.php',
            'app/Services/Ai/SelfConstruction/AtlasTaskServingService.php',
        ]);

        $this->assertNull($slug);
    }

    public function test_infer_returns_null_for_tests_only(): void
    {
        $this->assertNull(AtlasAaeosAcosLaneScope::inferFromAllowedFiles([
            'tests/Unit/Ai/Aaeos/FooTest.php',
        ]));
    }

    public function test_definition_lists_code_roots(): void
    {
        $def = AtlasAaeosAcosLaneScope::definition();

        $this->assertSame(AtlasAaeosAcosLaneScope::SLUG, $def['slug']);
        $this->assertContains('app/Services/Ai/Cognition/AcosProgram', $def['code_roots']);
        $this->assertContains('app/Services/Ai/AgenticEngineeringOs', $def['code_roots']);
    }
}
