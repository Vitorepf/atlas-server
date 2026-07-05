<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQualityAcceptanceCoverageHardener;
use PHPUnit\Framework\TestCase;

final class AtlasTaskQualityAcceptanceCoverageHardenerTest extends TestCase
{
    private AtlasTaskQualityAcceptanceCoverageHardener $hardener;

    protected function setUp(): void
    {
        $this->hardener = new AtlasTaskQualityAcceptanceCoverageHardener;
    }

    // ── AC: generic phpunit is flagged ──

    public function test_generic_phpunit_is_flagged(): void
    {
        $result = $this->hardener->harden([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskQualityAcceptanceCoverageHardener.php',
                'tests/Unit/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskQualityAcceptanceCoverageHardenerTest.php',
            ],
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test'],
        ]);

        $this->assertSame(AtlasTaskQualityAcceptanceCoverageHardener::VERDICT_FAIL, $result['verdict']);
        $this->assertSame(1, $result['unbound_count']);
        $this->assertSame('generic_phpunit_without_target', $result['unbound_criteria_findings'][0]['reason']);
    }

    // ── AC: unrelated filters are flagged ──

    public function test_unrelated_filter_is_flagged(): void
    {
        $result = $this->hardener->harden([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskQualityAcceptanceCoverageHardener.php',
                'tests/Unit/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskQualityAcceptanceCoverageHardenerTest.php',
            ],
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test --filter=SomeOtherServiceTest'],
        ]);

        $this->assertSame(AtlasTaskQualityAcceptanceCoverageHardener::VERDICT_FAIL, $result['verdict']);
        $this->assertSame('unrelated_filter_or_path:SomeOtherServiceTest', $result['unbound_criteria_findings'][0]['reason']);
    }

    // ── AC: targetless acceptance is flagged ──

    public function test_targetless_acceptance_is_flagged(): void
    {
        $result = $this->hardener->harden([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskQualityAcceptanceCoverageHardener.php',
            ],
            'acceptance_criteria' => ['The implementation should be correct.'],
        ]);

        $this->assertSame(AtlasTaskQualityAcceptanceCoverageHardener::VERDICT_FAIL, $result['verdict']);
        $this->assertSame('targetless_acceptance_criterion', $result['unbound_criteria_findings'][0]['reason']);
    }

    // ── AC: target-bound filters pass ──

    public function test_target_bound_filter_passes(): void
    {
        $result = $this->hardener->harden([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskQualityAcceptanceCoverageHardener.php',
                'tests/Unit/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskQualityAcceptanceCoverageHardenerTest.php',
            ],
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test --filter=AtlasTaskQualityAcceptanceCoverageHardenerTest'],
        ]);

        $this->assertSame(AtlasTaskQualityAcceptanceCoverageHardener::VERDICT_PASS, $result['verdict']);
        $this->assertSame(0, $result['unbound_count']);
        $this->assertSame(['/opt/homebrew/bin/php artisan test --filter=AtlasTaskQualityAcceptanceCoverageHardenerTest'], $result['bound_criteria']);
    }

    public function test_target_bound_path_passes(): void
    {
        $result = $this->hardener->harden([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskQualityAcceptanceCoverageHardener.php',
                'tests/Unit/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskQualityAcceptanceCoverageHardenerTest.php',
            ],
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test tests/Unit/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskQualityAcceptanceCoverageHardenerTest.php'],
        ]);

        $this->assertSame(AtlasTaskQualityAcceptanceCoverageHardener::VERDICT_PASS, $result['verdict']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->hardener->harden([
            'allowed_files' => [],
            'acceptance_criteria' => [],
        ]);

        $this->assertSame(AtlasTaskQualityAcceptanceCoverageHardener::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('bound_criteria', $result);
        $this->assertArrayHasKey('unbound_criteria_findings', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskQualityAcceptanceCoverageHardener.php',
            ],
            'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test --filter=AtlasTaskQualityAcceptanceCoverageHardenerTest'],
        ];

        $a = $this->hardener->harden($input);
        $b = $this->hardener->harden($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
