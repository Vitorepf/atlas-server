<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Corpus;

use App\Services\Ai\AutonomousEvolution\SelfModel\Corpus\AtlasLoopOutcomeCorpusBuilder;
use Tests\TestCase;

final class AtlasLoopOutcomeCorpusBuilderTest extends TestCase
{
    public function test_build_is_deterministic_for_same_deliveries(): void
    {
        $deliveries = [
            $this->delivery('shape-b', 'extract service', 2, true),
            $this->delivery('shape-a', 'add characterization', -1, false),
        ];

        $builder = new AtlasLoopOutcomeCorpusBuilder;
        $first = $builder->build($deliveries);
        $second = $builder->build($deliveries);

        $this->assertSame(
            json_encode($first, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode($second, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $this->assertSame(64, strlen($first['corpus_hash']));
    }

    public function test_certified_positive_delta_labels_positive_and_uncertified_labels_negative(): void
    {
        $result = (new AtlasLoopOutcomeCorpusBuilder)->build([
            $this->delivery('shape-positive', 'ship behavior', 2, true),
            $this->delivery('shape-negative', 'failed patch', 3, false),
            $this->delivery('shape-flat', 'no behavior delta', 0, true),
        ]);

        $labels = array_column($result['examples'], 'label', 'action');

        $this->assertSame('positive', $labels['ship behavior']);
        $this->assertSame('negative', $labels['failed patch']);
        $this->assertSame('negative', $labels['no behavior delta']);
        $this->assertSame(1, $result['positive_count']);
        $this->assertSame(2, $result['negative_count']);
    }

    public function test_missing_shape_token_or_action_summary_is_skipped(): void
    {
        $result = (new AtlasLoopOutcomeCorpusBuilder)->build([
            $this->delivery('shape-keep', 'valid action', 1, true),
            ['action_summary' => 'missing shape', 'net_behavior_delta' => 1, 'certified' => true],
            ['shape_token' => 'shape-missing-action', 'net_behavior_delta' => 1, 'certified' => true],
        ]);

        $this->assertCount(1, $result['examples']);
        $this->assertSame(1, $result['positive_count']);
        $this->assertSame(0, $result['negative_count']);
    }

    public function test_prompt_context_redacts_provider_sensitive_tokens(): void
    {
        $result = (new AtlasLoopOutcomeCorpusBuilder)->build([
            $this->delivery('shape-sk-LIVE123', 'uses password=abc123 safely', 1, true),
        ]);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('sk-LIVE123', $encoded);
        $this->assertStringNotContainsString('password=abc123', $encoded);
        $this->assertStringContainsString('[REDACTED]', $encoded);
    }

    public function test_source_has_no_provider_network_or_db_calls(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/AutonomousEvolution/SelfModel/Corpus/AtlasLoopOutcomeCorpusBuilder.php'));

        $this->assertStringNotContainsString('Process', $source);
        $this->assertStringNotContainsString('Http', $source);
        $this->assertStringNotContainsString('DB::', $source);
    }

    /**
     * @return array<string,mixed>
     */
    private function delivery(string $shape, string $action, int $delta, bool $certified): array
    {
        return [
            'objective_class' => 'feature',
            'shape_token' => $shape,
            'action_summary' => $action,
            'net_behavior_delta' => $delta,
            'certified' => $certified,
            'provider' => 'codex',
        ];
    }
}
