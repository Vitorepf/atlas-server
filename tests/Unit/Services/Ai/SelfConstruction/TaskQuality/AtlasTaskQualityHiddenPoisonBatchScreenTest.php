<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQualityHiddenPoisonBatchScreen;
use Tests\TestCase;

final class AtlasTaskQualityHiddenPoisonBatchScreenTest extends TestCase
{
    private function screen(): AtlasTaskQualityHiddenPoisonBatchScreen
    {
        return new AtlasTaskQualityHiddenPoisonBatchScreen;
    }

    // ── AC: repeated shape is flagged across the batch ──

    public function test_repeated_shape_flagged(): void
    {
        $packets = [];
        for ($i = 0; $i < 3; $i++) {
            $packets[] = [
                'allowed_files' => ['app/Same.php', 'tests/SameTest.php'],
                'acceptance_criteria' => ['php artisan test --filter=Unique'.$i],
                'objective' => 'Unique objective '.$i,
            ];
        }

        $result = $this->screen()->screen($packets);

        $this->assertTrue($result['flagged']);
        $flagTypes = array_column($result['flags'], 'type');
        $this->assertContains('repeated_shape', $flagTypes);
    }

    // ── AC: same acceptance filter family is flagged ──

    public function test_same_acceptance_filter_family_flagged(): void
    {
        $packets = [];
        for ($i = 0; $i < 3; $i++) {
            $packets[] = [
                'allowed_files' => ['app/Unique'.$i.'.php'],
                'acceptance_criteria' => ['php artisan test --filter=SameFilter'],
                'objective' => 'Unique objective '.$i,
            ];
        }

        $result = $this->screen()->screen($packets);

        $this->assertTrue($result['flagged']);
        $flagTypes = array_column($result['flags'], 'type');
        $this->assertContains('same_acceptance_filter_family', $flagTypes);
    }

    // ── AC: cosmetic class name churn is flagged ──

    public function test_cosmetic_class_name_churn_flagged(): void
    {
        $packets = [];
        $classNames = ['Foo', 'Bar', 'Baz'];
        foreach ($classNames as $name) {
            $packets[] = [
                'allowed_files' => ['app/'.$name.'.php'],
                'acceptance_criteria' => ['php artisan test --filter='.$name.'Test'],
                'objective' => 'Implement the '.$name.' service with method',
            ];
        }

        $result = $this->screen()->screen($packets);

        $this->assertTrue($result['flagged']);
        $flagTypes = array_column($result['flags'], 'type');
        $this->assertContains('cosmetic_class_name_churn', $flagTypes);
    }

    // ── no poison → no flags ──

    public function test_diverse_batch_not_flagged(): void
    {
        $packets = [
            [
                'allowed_files' => ['app/A.php'],
                'acceptance_criteria' => ['php artisan test --filter=ATest'],
                'objective' => 'Implement A service',
            ],
            [
                'allowed_files' => ['app/B.php'],
                'acceptance_criteria' => ['php artisan test --filter=BTest'],
                'objective' => 'Fix B controller bug',
            ],
        ];

        $result = $this->screen()->screen($packets);

        $this->assertFalse($result['flagged']);
        $this->assertSame([], $result['flags']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->screen()->screen([]);

        $this->assertSame(AtlasTaskQualityHiddenPoisonBatchScreen::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('flagged', $result);
        $this->assertArrayHasKey('flags', $result);
        $this->assertArrayHasKey('total_packets', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $packets = [
            ['allowed_files' => ['app/A.php'], 'acceptance_criteria' => ['php artisan test --filter=ATest'], 'objective' => 'A'],
            ['allowed_files' => ['app/A.php'], 'acceptance_criteria' => ['php artisan test --filter=BTest'], 'objective' => 'B'],
            ['allowed_files' => ['app/A.php'], 'acceptance_criteria' => ['php artisan test --filter=CTest'], 'objective' => 'C'],
        ];

        $a = $this->screen()->screen($packets);
        $b = $this->screen()->screen($packets);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
