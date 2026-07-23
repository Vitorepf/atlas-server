<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAcceptanceRunnableSynthesizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAcceptanceRunnableSynthesizerTest extends TestCase
{
    private AtlasExternalBrainAcceptanceRunnableSynthesizer $synthesizer;

    protected function setUp(): void
    {
        $this->synthesizer = new AtlasExternalBrainAcceptanceRunnableSynthesizer;
    }

    public function test_service_test_pair_produces_filter_criterion(): void
    {
        $result = $this->synthesizer->synthesize([
            'allowed_files' => [
                'app/Services/Foo.php',
                'tests/Unit/Services/FooTest.php',
            ],
        ]);

        $this->assertTrue($result['runnable']);
        $this->assertNotEmpty($result['runnable_acceptance_criteria']);
        $this->assertStringContainsString('--filter=FooTest', $result['runnable_acceptance_criteria'][0]);
    }

    public function test_missing_test_file_returns_blocking_deficiency(): void
    {
        $result = $this->synthesizer->synthesize([
            'allowed_files' => ['app/Services/Foo.php'],
        ]);

        $this->assertFalse($result['runnable']);
        $this->assertContains(AtlasExternalBrainAcceptanceRunnableSynthesizer::DEFICIENCY_NO_TEST_FILE, $result['deficiencies']);
    }

    public function test_missing_implementation_file_returns_blocking_deficiency(): void
    {
        $result = $this->synthesizer->synthesize([
            'allowed_files' => ['tests/Unit/FooTest.php'],
        ]);

        $this->assertFalse($result['runnable']);
        $this->assertContains(AtlasExternalBrainAcceptanceRunnableSynthesizer::DEFICIENCY_NO_IMPLEMENTATION_FILE, $result['deficiencies']);
    }

    public function test_empty_allowed_files_returns_deficiencies(): void
    {
        $result = $this->synthesizer->synthesize(['allowed_files' => []]);

        $this->assertFalse($result['runnable']);
        $this->assertNotEmpty($result['deficiencies']);
    }

    public function test_target_bound_true_when_implementation_present(): void
    {
        $result = $this->synthesizer->synthesize([
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
        ]);

        $this->assertTrue($result['target_bound']);
    }

    public function test_schema_present(): void
    {
        $result = $this->synthesizer->synthesize([]);
        $this->assertSame(AtlasExternalBrainAcceptanceRunnableSynthesizer::SCHEMA, $result['schema']);
    }
}
