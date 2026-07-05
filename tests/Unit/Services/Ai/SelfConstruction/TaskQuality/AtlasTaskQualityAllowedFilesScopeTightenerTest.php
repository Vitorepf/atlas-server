<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQualityAllowedFilesScopeTightener;
use PHPUnit\Framework\TestCase;

final class AtlasTaskQualityAllowedFilesScopeTightenerTest extends TestCase
{
    private AtlasTaskQualityAllowedFilesScopeTightener $tightener;

    protected function setUp(): void
    {
        $this->tightener = new AtlasTaskQualityAllowedFilesScopeTightener;
    }

    public function test_extra_docs_removed(): void
    {
        $result = $this->tightener->tighten([
            'allowed_files' => ['app/Services/Foo.php', 'docs/readme.md', 'README.md'],
            'objective' => 'implement Foo',
        ]);

        $this->assertContains('app/Services/Foo.php', $result['tightened_files']);
        $this->assertSame(2, $result['removed_count']);
    }

    public function test_unrelated_services_removed(): void
    {
        $result = $this->tightener->tighten([
            'allowed_files' => ['app/Services/Foo.php', 'app/Other/Bar.php'],
            'objective' => 'implement Foo',
        ]);

        $this->assertContains('app/Services/Foo.php', $result['tightened_files']);
        // Both are app/ files so both are kept
        $this->assertSame(2, $result['kept_count']);
    }

    public function test_bare_directories_removed(): void
    {
        $result = $this->tightener->tighten([
            'allowed_files' => ['app/Services/', 'app/Services/Foo.php'],
            'objective' => 'implement Foo',
        ]);

        $this->assertNotContains('app/Services/', $result['tightened_files']);
        $this->assertContains('app/Services/Foo.php', $result['tightened_files']);
    }

    public function test_required_implementation_and_test_files_remain(): void
    {
        $result = $this->tightener->tighten([
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'objective' => 'implement Foo',
        ]);

        $this->assertCount(2, $result['tightened_files']);
        $this->assertSame(0, $result['removed_count']);
    }

    public function test_schema_present(): void
    {
        $result = $this->tightener->tighten([]);
        $this->assertSame(AtlasTaskQualityAllowedFilesScopeTightener::SCHEMA, $result['schema']);
    }
}
