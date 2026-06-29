<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the abstain-and-ask organ is live at the operator surface and emits deterministic facts: an
 * ungrounded / low-confidence decision ABSTAINS with a clarifying operator question; a grounded, confident,
 * precedented decision PROCEEDS. A missing --input is a usage error.
 */
final class AtlasLoopAbstainAndAskCommandTest extends TestCase
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
        $exit = Artisan::call('atlas:loop:abstain-and-ask', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_ungrounded_decision_abstains_with_question(): void
    {
        $decoded = $this->invoke([
            'grounded' => false,
            'confidence' => 0.2,
            'novel' => true,
            'summary' => 'rewrite the merge gate',
        ]);

        $this->assertSame('atlas.loop.abstain_and_ask.v1', $decoded['schema']);
        $this->assertSame('abstain', $decoded['action']);
        $this->assertNotEmpty($decoded['reasons']);
        $this->assertNotNull($decoded['operator_question']);
        $this->assertStringContainsString('rewrite the merge gate', $decoded['operator_question']);
    }

    public function test_grounded_confident_precedented_decision_proceeds(): void
    {
        $decoded = $this->invoke([
            'grounded' => true,
            'confidence' => 0.95,
            'novel' => false,
            'has_precedent' => true,
            'summary' => 'tighten an existing guard',
        ]);

        $this->assertSame('proceed', $decoded['action']);
        $this->assertSame([], $decoded['reasons']);
        $this->assertNull($decoded['operator_question']);
    }

    /**
     * @param  array<string,mixed>  $decision
     * @return array<string,mixed>
     */
    private function invoke(array $decision): array
    {
        $path = tempnam(sys_get_temp_dir(), 'abstain_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($decision));

        $exit = Artisan::call('atlas:loop:abstain-and-ask', ['--input' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
