<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Pipeline\DevWorkcellDecomposer;
use PHPUnit\Framework\TestCase;

final class DevWorkcellDecomposerTest extends TestCase
{
    private function decomposer(): DevWorkcellDecomposer
    {
        return new DevWorkcellDecomposer;
    }

    public function test_single_concern_spec_yields_one_workcell_mirroring_spec_scope(): void
    {
        $spec = [
            'goal' => 'add caching to FooService',
            'allowed_files' => ['app/Services/Foo/FooService.php', 'app/Services/Foo/FooCache.php'],
            'acceptance_criteria' => [
                ['id' => 'ac1', 'description' => 'cache hits avoid recompute', 'verification' => 'php artisan test --filter=FooServiceTest'],
            ],
            'verification_plan' => ['commands' => ['php artisan test --filter=FooServiceTest']],
        ];

        $result = $this->decomposer()->decompose($spec, []);

        $this->assertCount(1, $result['workcells']);
        $wc = $result['workcells'][0];
        $this->assertSame(['app/Services/Foo/FooService.php', 'app/Services/Foo/FooCache.php'], $wc['allowed_files']);
        $this->assertCount(1, $wc['acceptance_criteria']);
        $this->assertSame('php artisan test --filter=FooServiceTest', $wc['verification_command']);
    }

    public function test_two_concern_spec_splits_into_disjoint_workcells_with_own_commands(): void
    {
        $spec = [
            'goal' => 'add caching and update docs',
            'allowed_files' => [
                'app/Services/Foo/FooService.php',
                'docs/engineering-knowledge-base/foo-service.md',
            ],
            'acceptance_criteria' => [
                ['id' => 'ac1', 'description' => 'FooService caches', 'verification' => 'php artisan test --filter=FooServiceTest touches app/Services/Foo/FooService.php'],
                ['id' => 'ac2', 'description' => 'docs updated', 'verification' => 'manual review of docs/engineering-knowledge-base/foo-service.md'],
            ],
            'verification_plan' => ['commands' => [
                'php artisan test --filter=FooServiceTest app/Services/Foo/FooService.php',
                'markdown-lint docs/engineering-knowledge-base/foo-service.md',
            ]],
        ];

        $result = $this->decomposer()->decompose($spec, []);

        $this->assertCount(2, $result['workcells']);

        $allFiles = [];
        foreach ($result['workcells'] as $wc) {
            foreach ($wc['allowed_files'] as $f) {
                $this->assertNotContains($f, $allFiles, 'allowed_files must never repeat across workcells');
                $allFiles[] = $f;
            }
        }
        $this->assertCount(2, $allFiles);

        $byFile = [];
        foreach ($result['workcells'] as $wc) {
            foreach ($wc['allowed_files'] as $f) {
                $byFile[$f] = $wc;
            }
        }
        $this->assertStringContainsString('FooServiceTest', $byFile['app/Services/Foo/FooService.php']['verification_command']);
        $this->assertStringContainsString('markdown-lint', $byFile['docs/engineering-knowledge-base/foo-service.md']['verification_command']);
    }

    public function test_internal_error_falls_back_to_single_workcell_shape(): void
    {
        // 'goal' is an object with no __toString — the internal (string) cast genuinely throws an
        // Error, proving decompose() catches it and falls back instead of propagating.
        $spec = [
            'goal' => new class {},
            'allowed_files' => ['app/Services/Weird.php'],
            'acceptance_criteria' => [],
        ];

        $result = $this->decomposer()->decompose($spec, []);

        $this->assertCount(1, $result['workcells']);
        $this->assertSame('wc-1', $result['workcells'][0]['workcell_id']);
        $this->assertSame(['app/Services/Weird.php'], $result['workcells'][0]['allowed_files']);
        $this->assertSame('', $result['workcells'][0]['objective_slice']);
    }

    public function test_identical_inputs_always_produce_identical_output(): void
    {
        $spec = [
            'goal' => 'multi-cluster change',
            'allowed_files' => [
                'app/Services/A/A.php',
                'app/Services/B/B.php',
                'tests/Unit/A/ATest.php',
            ],
            'acceptance_criteria' => [
                ['id' => 'ac1', 'description' => 'A works', 'verification' => 'php artisan test app/Services/A/A.php'],
            ],
            'verification_plan' => ['commands' => ['php artisan test app/Services/A/A.php']],
        ];

        $first = $this->decomposer()->decompose($spec, []);
        $second = $this->decomposer()->decompose($spec, []);

        $this->assertSame($first, $second);
    }

    public function test_test_only_workcell_depends_on_non_test_workcells(): void
    {
        $spec = [
            'goal' => 'implement plus tests',
            'allowed_files' => [
                'app/Services/A/A.php',
                'tests/Unit/A/ATest.php',
            ],
            'acceptance_criteria' => [],
            'verification_plan' => ['commands' => []],
        ];

        $result = $this->decomposer()->decompose($spec, []);
        $this->assertCount(2, $result['workcells']);

        $testWorkcell = null;
        $implWorkcell = null;
        foreach ($result['workcells'] as $wc) {
            if (in_array('tests/Unit/A/ATest.php', $wc['allowed_files'], true)) {
                $testWorkcell = $wc;
            } else {
                $implWorkcell = $wc;
            }
        }

        $this->assertNotNull($testWorkcell);
        $this->assertNotNull($implWorkcell);
        $this->assertContains($implWorkcell['workcell_id'], $testWorkcell['depends_on']);
        $this->assertSame([], $implWorkcell['depends_on']);
    }

    public function test_schema_present(): void
    {
        $result = $this->decomposer()->decompose(['allowed_files' => ['app/Foo.php']], []);

        $this->assertSame(DevWorkcellDecomposer::SCHEMA, $result['schema']);
    }
}
