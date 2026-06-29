<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the pre-hoc blast-radius predictor is live at the operator surface: a consumer that uses a member named
 * by --removed-member is predicted to break (break_kind removed_member, safe=false), while a run with no such
 * consumer is safe. Consumer evidence is injected so the test depends on no real scan.
 */
final class AtlasLoopBlastRadiusCommandTest extends TestCase
{
    private function bindConsumers(array $consumers): void
    {
        $this->app->bind(
            'atlas.loop.blast_radius.consumer_source',
            fn (): Closure => fn (string $fqcn): array => $consumers,
        );
    }

    public function test_blast_radius_flags_consumer_using_removed_member(): void
    {
        $this->bindConsumers([
            ['consumer_fqcn' => 'App\\Uses', 'uses' => ['someMethod']],
            ['consumer_fqcn' => 'App\\DoesntUse', 'uses' => ['otherMethod']],
        ]);

        $exit = Artisan::call('atlas:loop:blast-radius', [
            '--fqcn' => 'App\\SomeClass',
            '--removed-member' => ['someMethod'],
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertFalse($decoded['safe'], 'a consumer of a removed member is not safe');

        $byConsumer = array_column($decoded['would_break'], 'break_kind', 'consumer_fqcn');
        $this->assertArrayHasKey('App\\Uses', $byConsumer);
        $this->assertSame('removed_member', $byConsumer['App\\Uses']);
        $this->assertArrayNotHasKey('App\\DoesntUse', $byConsumer, 'a consumer not using the removed member is unaffected');
    }

    public function test_blast_radius_is_safe_when_no_consumer_uses_removed_member(): void
    {
        $this->bindConsumers([
            ['consumer_fqcn' => 'App\\DoesntUse', 'uses' => ['otherMethod']],
        ]);

        $exit = Artisan::call('atlas:loop:blast-radius', [
            '--fqcn' => 'App\\SomeClass',
            '--removed-member' => ['someMethod'],
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($decoded['safe']);
        $this->assertSame([], $decoded['would_break']);
    }

    public function test_missing_fqcn_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:blast-radius', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
