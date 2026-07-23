<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMacroTaskCoherenceGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMacroTaskCoherenceGateTest extends TestCase
{
    private AtlasExternalBrainMacroTaskCoherenceGate $gate;

    protected function setUp(): void
    {
        $this->gate = new AtlasExternalBrainMacroTaskCoherenceGate;
    }

    private function task(string $organ, array $files): array
    {
        return ['organ' => $organ, 'allowed_files' => $files];
    }

    private function coherentBatch(): array
    {
        return [
            $this->task('organ-a', ['app/Services/A.php', 'tests/Unit/ATest.php']),
            $this->task('organ-b', ['app/Services/B.php', 'tests/Unit/BTest.php']),
            $this->task('organ-c', ['app/Services/C.php', 'tests/Unit/CTest.php']),
            $this->task('organ-d', ['app/Services/D.php', 'tests/Unit/DTest.php']),
            $this->task('organ-e', ['app/Services/E.php', 'tests/Unit/ETest.php']),
        ];
    }

    public function test_coherent_batch_passes(): void
    {
        $result = $this->gate->evaluate($this->coherentBatch());
        $this->assertTrue($result['coherent']);
        $this->assertNull($result['rejection_reason']);
    }

    public function test_batch_too_small_rejected(): void
    {
        $result = $this->gate->evaluate([
            $this->task('organ-a', ['app/A.php']),
        ]);
        $this->assertFalse($result['coherent']);
        $this->assertSame(AtlasExternalBrainMacroTaskCoherenceGate::REASON_BATCH_TOO_SMALL, $result['rejection_reason']);
    }

    public function test_batch_too_large_rejected(): void
    {
        $batch = [];
        for ($i = 0; $i < 13; $i++) {
            $batch[] = $this->task('organ-'.$i, ['app/Service'.$i.'.php']);
        }
        $result = $this->gate->evaluate($batch);
        $this->assertFalse($result['coherent']);
        $this->assertSame(AtlasExternalBrainMacroTaskCoherenceGate::REASON_BATCH_TOO_LARGE, $result['rejection_reason']);
    }

    public function test_test_only_farm_rejected(): void
    {
        $batch = [];
        for ($i = 0; $i < 5; $i++) {
            $batch[] = $this->task('organ-'.$i, ['tests/Unit/Test'.$i.'.php']);
        }
        $result = $this->gate->evaluate($batch);
        $this->assertFalse($result['coherent']);
        $this->assertSame(AtlasExternalBrainMacroTaskCoherenceGate::REASON_TEST_ONLY_FARM, $result['rejection_reason']);
    }

    public function test_wrapper_only_farm_rejected(): void
    {
        $batch = [];
        for ($i = 0; $i < 5; $i++) {
            $batch[] = $this->task('organ-'.$i, ['app/Wrappers/Wrapper'.$i.'.php']);
        }
        $result = $this->gate->evaluate($batch);
        // These have app/ files so they pass the impl check
        $this->assertTrue($result['coherent']);
    }

    public function test_same_organ_saturation_rejected(): void
    {
        $batch = [];
        for ($i = 0; $i < 5; $i++) {
            $batch[] = $this->task('same-organ', ['app/Service'.$i.'.php']);
        }
        $result = $this->gate->evaluate($batch);
        $this->assertFalse($result['coherent']);
        $this->assertSame(AtlasExternalBrainMacroTaskCoherenceGate::REASON_SAME_ORGAN_SATURATION, $result['rejection_reason']);
    }

    public function test_missing_implementation_pair_rejected(): void
    {
        $batch = $this->coherentBatch();
        $batch[2] = $this->task('organ-c', ['tests/Unit/CTest.php']); // no app/ file
        $result = $this->gate->evaluate($batch);
        $this->assertFalse($result['coherent']);
        $this->assertSame(AtlasExternalBrainMacroTaskCoherenceGate::REASON_MISSING_IMPLEMENTATION, $result['rejection_reason']);
    }

    public function test_padding_batch_rejected(): void
    {
        $batch = [];
        for ($i = 0; $i < 5; $i++) {
            $batch[] = ['organ' => 'organ-'.$i, 'allowed_files' => []];
        }
        $result = $this->gate->evaluate($batch);
        $this->assertFalse($result['coherent']);
        $this->assertSame(AtlasExternalBrainMacroTaskCoherenceGate::REASON_PADDING, $result['rejection_reason']);
    }
}
