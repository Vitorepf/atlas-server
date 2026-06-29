<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\CausalGraph\AtlasLoopPreHocBlastRadiusPredictor;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the pre-hoc blast-radius predictor is live at the operator surface: consumers using a removed or
 * signature-changed member are predicted to break; consumers using only an added member are safe.
 */
final class AtlasLoopPreHocBlastRadiusCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-blast-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function predict(array $doc): array
    {
        file_put_contents($this->input, (string) json_encode($doc));
        $exit = Artisan::call('atlas:loop:pre-hoc-blast-radius', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_predicts_breaking_consumers(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->predict([
            'fqcn' => 'App\\Target',
            'proposed_api_change' => ['removed' => ['oldMethod'], 'changed' => ['changedSig'], 'added' => ['newMethod']],
            'consumers' => [
                ['consumer_fqcn' => 'App\\C1', 'uses' => ['oldMethod']],    // removed_member
                ['consumer_fqcn' => 'App\\C2', 'uses' => ['changedSig']],   // signature_changed
                ['consumer_fqcn' => 'App\\C3', 'uses' => ['newMethod']],    // added_only ⇒ safe
            ],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopPreHocBlastRadiusPredictor::SCHEMA_VERSION, $d['schema']);
        $this->assertFalse($d['safe'], (string) json_encode($d));
        $this->assertSame(2, $d['break_count']);
        $byConsumer = array_column($d['would_break'], 'break_kind', 'consumer_fqcn');
        $this->assertSame('removed_member', $byConsumer['App\\C1']);
        $this->assertSame('signature_changed', $byConsumer['App\\C2']);
        $this->assertArrayNotHasKey('App\\C3', $byConsumer);
    }

    public function test_added_only_change_is_safe(): void
    {
        ['d' => $d] = $this->predict([
            'fqcn' => 'App\\Target',
            'proposed_api_change' => ['added' => ['newMethod']],
            'consumers' => [['consumer_fqcn' => 'App\\C3', 'uses' => ['newMethod']]],
        ]);

        $this->assertTrue($d['safe']);
        $this->assertSame(0, $d['break_count']);
        $this->assertSame('no_breaking_consumers_predicted', $d['reason']);
    }

    public function test_missing_fqcn_is_usage_error(): void
    {
        file_put_contents($this->input, (string) json_encode(['proposed_api_change' => [], 'consumers' => []]));
        $exit = Artisan::call('atlas:loop:pre-hoc-blast-radius', ['--input' => $this->input, '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:pre-hoc-blast-radius', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
