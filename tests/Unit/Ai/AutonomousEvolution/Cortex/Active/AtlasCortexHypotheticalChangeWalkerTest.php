<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Active;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Active\AtlasCortexHypotheticalChangeWalker;
use Tests\TestCase;

final class AtlasCortexHypotheticalChangeWalkerTest extends TestCase
{
    public function test_walk_returns_exact_static_callers_plus_unknown_region_with_stable_order(): void
    {
        $walker = new AtlasCortexHypotheticalChangeWalker($this->index());

        $first = $walker->walk('app/Domain/Target.php', 'signature_change');
        $second = $walker->walk('app/Domain/Target.php', 'signature_change');

        $this->assertSame($first, $second);
        $this->assertSame(json_encode($first), json_encode($second));
        $this->assertCount(4, $first);
        $this->assertSame(['UNKNOWN_REGION', 'app/Api/Controller.php', 'app/Jobs/Worker.php', 'app/Console/Command.php'], array_column($first, 'caller_file'));
        $this->assertSame([0, 12, 44, 9], array_column($first, 'caller_line'));
        $this->assertSame([1, 1, 1, 2], array_column($first, 'distance_from_target'));
        $this->assertContains('UNKNOWN_REGION', array_column($first, 'caller_symbol'));
    }

    public function test_fact_schema_has_closed_reasons_and_no_scores_or_recommendations(): void
    {
        $facts = (new AtlasCortexHypotheticalChangeWalker($this->index()))->walk('app/Domain/Target.php', [
            'kind' => 'remove',
        ]);

        $allowedReasons = [
            'signature_arity_changed',
            'return_type_changed',
            'side_effect_removed',
            'symbol_removed',
            'symbol_renamed',
            'behavior_changed',
            'unindexed_region',
        ];

        foreach ($facts as $fact) {
            $this->assertSame([
                'schema',
                'target',
                'caller_file',
                'caller_line',
                'caller_symbol',
                'distance_from_target',
                'propagation_reason',
                'confidence_basis',
            ], array_keys($fact));
            $this->assertContains($fact['propagation_reason'], $allowedReasons);
            $this->assertContains($fact['confidence_basis'], ['static_evidence', 'reflective_evidence']);
            $this->assertArrayNotHasKey('score', $fact);
            $this->assertArrayNotHasKey('rank', $fact);
            $this->assertArrayNotHasKey('recommendation', $fact);
            $this->assertArrayNotHasKey('confidence_percentage', $fact);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function index(): array
    {
        return [
            'edges' => [
                'app/Domain/Target.php' => [
                    [
                        'caller_file' => 'app/Api/Controller.php',
                        'caller_line' => 12,
                        'caller_symbol' => 'App\\Api\\Controller::store',
                        'confidence_basis' => 'static_evidence',
                    ],
                    [
                        'caller_file' => 'app/Jobs/Worker.php',
                        'caller_line' => 44,
                        'caller_symbol' => 'App\\Jobs\\Worker::handle',
                        'confidence_basis' => 'reflective_evidence',
                    ],
                ],
                'app/Api/Controller.php' => [
                    [
                        'caller_file' => 'app/Console/Command.php',
                        'caller_line' => 9,
                        'caller_symbol' => 'App\\Console\\Command::handle',
                    ],
                ],
            ],
            'unindexed_edges' => [
                'app/Domain/Target.php' => ['vendor/opaque/Bridge.php'],
            ],
        ];
    }
}
