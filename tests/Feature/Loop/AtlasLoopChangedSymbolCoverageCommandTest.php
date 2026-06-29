<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the changed-symbol coverage census is live at the operator surface and emits a deterministic fact:
 * a corpus that names + calls the symbol AND asserts marks it exercised; a corpus that calls without any
 * assertion does not. A missing --symbol is a usage error.
 */
final class AtlasLoopChangedSymbolCoverageCommandTest extends TestCase
{
    public function test_requires_corpus_and_symbol(): void
    {
        $exit = Artisan::call('atlas:loop:changed-symbol-coverage', ['--corpus' => 'x', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_named_called_and_asserted_symbol_is_exercised(): void
    {
        $corpus = '$svc->doThing($x); $this->assertTrue($result);';
        $decoded = $this->invoke($corpus, 'doThing');

        $this->assertSame('atlas.loop.changed_symbol_coverage.v1', $decoded['schema']);
        $this->assertSame('doThing', $decoded['symbol']);
        $this->assertTrue($decoded['exercised']);
    }

    public function test_called_without_assertion_is_not_exercised(): void
    {
        $corpus = '$svc->doThing($x); // no assertion at all';
        $decoded = $this->invoke($corpus, 'doThing');

        $this->assertFalse($decoded['exercised']);
    }

    /**
     * @return array<string,mixed>
     */
    private function invoke(string $corpus, string $symbol): array
    {
        $exit = Artisan::call('atlas:loop:changed-symbol-coverage', [
            '--corpus' => $corpus,
            '--symbol' => $symbol,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
