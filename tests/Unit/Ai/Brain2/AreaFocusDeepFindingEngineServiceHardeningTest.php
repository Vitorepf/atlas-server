<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AreaFocusDeepFindingEngineServiceHardeningTest extends TestCase
{
    /**
     * Verify the source uses json_encode for injective finding id.
     */
    public function test_source_uses_json_encode_for_finding_id(): void
    {
        // GOD-DEBULK split: the makeFinding pipeline (injective finding id) now lives in DeepFindingFactory.
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DeepFinding/DeepFindingFactory.php');

        $this->assertStringContainsString('json_encode', $source, 'must use json_encode for injective id');
        $this->assertStringContainsString('JSON_THROW_ON_ERROR', $source, 'must throw on encode failure');
    }

    /**
     * Verify the old pipe-implode for finding id is gone.
     */
    public function test_old_pipe_implode_for_finding_id_removed(): void
    {
        // GOD-DEBULK split: the makeFinding pipeline (injective finding id) now lives in DeepFindingFactory.
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DeepFinding/DeepFindingFactory.php');

        // The specific implode('|', [$areaId, $focus, $kind, $owner, $sourceRef]) must be gone.
        $this->assertStringNotContainsString(
            "implode('|', [\$areaId, \$focus, \$kind, \$owner, \$sourceRef])",
            $source,
            'old pipe-implode for finding id must be replaced'
        );
    }

    /**
     * Demonstrate the collision: pipe delimiter is not injective.
     */
    public function test_pipe_is_not_injective(): void
    {
        $key1 = implode('|', ['area-1', 'focus|detail', 'kind', 'owner', 'ref']);
        $key2 = implode('|', ['area-1', 'focus', 'detail|kind', 'owner', 'ref']);
        $this->assertEquals($key1, $key2, 'pipe delimiter is not injective');

        $json1 = json_encode(['area-1', 'focus|detail', 'kind', 'owner', 'ref']);
        $json2 = json_encode(['area-1', 'focus', 'detail|kind', 'owner', 'ref']);
        $this->assertNotEquals($json1, $json2, 'json_encode is injective');
    }
}
