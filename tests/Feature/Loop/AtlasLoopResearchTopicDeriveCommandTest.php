<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the research-topic deriver is live at the operator surface and emits deterministic facts: with
 * authoring ON a refactor shape maps to its clean public concept; with authoring OFF (provider-safe default)
 * nothing is derived. A missing --input is a usage error.
 */
final class AtlasLoopResearchTopicDeriveCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_requires_input(): void
    {
        $exit = Artisan::call('atlas:loop:research-topic-derive', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_derives_clean_topic_for_refactor_shape_when_enabled(): void
    {
        config(['atlas.loop.research_authoring_enabled' => true]);

        $decoded = $this->invoke(['shape' => 'single_file_refactor', 'cyclomatic' => 30]);

        $this->assertSame('atlas.loop.research_topic_derive.v1', $decoded['schema']);
        $this->assertTrue($decoded['has_topic']);
        $this->assertStringContainsString('cyclomatic-complexity reduction', $decoded['derived_topic']);
    }

    public function test_derives_nothing_when_authoring_off(): void
    {
        config(['atlas.loop.research_authoring_enabled' => false]);

        $decoded = $this->invoke(['shape' => 'single_file_refactor']);

        $this->assertFalse($decoded['has_topic']);
        $this->assertNull($decoded['derived_topic']);
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array<string,mixed>
     */
    private function invoke(array $signals): array
    {
        $path = tempnam(sys_get_temp_dir(), 'research_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($signals));

        $exit = Artisan::call('atlas:loop:research-topic-derive', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
