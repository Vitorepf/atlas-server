<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the causal-edge classifier is live at the operator surface and emits deterministic facts: per
 * consumer, the break kind reflects whether it uses a removed / changed / unaffected member of the changed
 * API. A missing --input is a usage error.
 */
final class AtlasLoopCausalEdgeClassifyCommandTest extends TestCase
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
        $exit = Artisan::call('atlas:loop:causal-edge-classify', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_classifies_break_kind_per_consumer(): void
    {
        $decoded = $this->invoke([
            'fqcn' => 'App\\Hub',
            'consumer_fqcns' => [
                ['consumer_fqcn' => 'App\\C1', 'uses' => ['doRemoved']],
                ['consumer_fqcn' => 'App\\C2', 'uses' => ['doChanged']],
                ['consumer_fqcn' => 'App\\C3', 'uses' => ['safeMethod']],
            ],
            'api_change' => [
                'removed' => ['doRemoved'],
                'changed' => ['doChanged'],
                'added' => ['newThing'],
            ],
        ]);

        $this->assertSame('atlas.loop.causal_edge_classifier.v1', $decoded['schema']);

        $byConsumer = array_column($decoded['edges'], 'break_kind', 'consumer_fqcn');
        $this->assertSame('removed_member', $byConsumer['App\\C1']);
        $this->assertSame('signature_changed', $byConsumer['App\\C2']);
        $this->assertSame('none', $byConsumer['App\\C3']);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function invoke(array $payload): array
    {
        $path = tempnam(sys_get_temp_dir(), 'causal_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($payload));

        $exit = Artisan::call('atlas:loop:causal-edge-classify', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
