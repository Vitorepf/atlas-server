<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\CausalGraph;

use App\Services\Ai\AutonomousEvolution\CausalGraph\AtlasLoopPreHocBlastRadiusPredictor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AtlasLoopPreHocBlastRadiusPredictorTest extends TestCase
{
    public function test_predict_returns_safe_true_with_empty_would_break_when_no_consumer_breaks(): void
    {
        $result = (new AtlasLoopPreHocBlastRadiusPredictor)->predict('App\\TargetService', [
            'added_methods' => ['newHook'],
        ], [
            ['consumer_fqcn' => 'App\\ConsumerA', 'uses' => ['newHook']],
            ['consumer_fqcn' => 'App\\ConsumerB', 'uses' => ['stillThere']],
        ]);

        $this->assertSame(AtlasLoopPreHocBlastRadiusPredictor::SCHEMA_VERSION, $result['schema']);
        $this->assertTrue($result['safe']);
        $this->assertSame([], $result['would_break']);
        $this->assertSame('no_breaking_consumers_predicted', $result['reason']);
    }

    public function test_predict_returns_safe_false_and_lists_every_breaking_consumer(): void
    {
        $result = (new AtlasLoopPreHocBlastRadiusPredictor)->predict('App\\TargetService', [
            'removed_methods' => ['oldHook'],
            'changed_methods' => ['changedSig'],
            'added_methods' => ['newHook'],
        ], [
            ['consumer_fqcn' => 'App\\UsesAdded', 'uses' => ['newHook']],
            ['consumer_fqcn' => 'App\\UsesChanged', 'uses' => ['changedSig']],
            ['consumer_fqcn' => 'App\\UsesRemoved', 'uses' => ['oldHook']],
            ['consumer_fqcn' => 'App\\Unaffected', 'uses' => ['stillThere']],
        ]);

        $this->assertFalse($result['safe']);
        $this->assertSame('breaking_consumers_predicted', $result['reason']);
        $this->assertSame([
            ['consumer_fqcn' => 'App\\UsesChanged', 'break_kind' => 'signature_changed'],
            ['consumer_fqcn' => 'App\\UsesRemoved', 'break_kind' => 'removed_member'],
        ], $result['would_break']);
    }

    public function test_source_contains_no_file_write_or_materialization_primitive(): void
    {
        $ref = new ReflectionClass(AtlasLoopPreHocBlastRadiusPredictor::class);
        $source = file_get_contents((string) $ref->getFileName());
        $this->assertIsString($source);

        foreach (['file_put_contents', 'fopen', 'fwrite', 'shell_exec', 'proc_open', 'passthru', 'exec('] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }
    }
}
