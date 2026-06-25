<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Tests\TestCase;

final class LoopSelfModificationSafetyCanonicalDocTest extends TestCase
{
    private function doc(): string
    {
        return (string) file_get_contents(base_path('docs/loop-self-modification-safety-canonical.md'));
    }

    public function test_doc_exists_and_contains_required_primitives(): void
    {
        $doc = $this->doc();
        $this->assertNotSame('', $doc);

        foreach (['ATLAS_LOOP_MASTER_ENABLED', 'fail-closed', 'FORBIDDEN', 'flock', 'sandbox', 'moat'] as $needle) {
            $this->assertStringContainsString($needle, $doc, "doc must contain '{$needle}'");
        }
    }

    public function test_doc_anchors_real_symbols(): void
    {
        $doc = $this->doc();

        foreach (['AtlasLoopWorkspaceMaterializerSupport2', 'AtlasLoopAutoMergeService', 'FrozenJudge'] as $anchor) {
            $this->assertStringContainsString($anchor, $doc, "doc must anchor symbol '{$anchor}'");
        }
    }

    public function test_doc_explicitly_states_propose_only_is_not_a_merge_gate(): void
    {
        $doc = strtolower($this->doc());

        $this->assertStringContainsString('propose-only', $doc);
        $this->assertMatchesRegularExpression('/propose-only is not a merge gate|propose-only.*not.*a merge gate|propose-only is not/i', $doc);
    }
}
