<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AtlasOpenBrainSessionCaptureService;
use ReflectionClass;
use Tests\TestCase;

/**
 * D2 — the Stop-hook capture records a STRUCTURED learning (claim + porquê +
 * arquivos + evidence) instead of a flat blob, and fills the APCR
 * post_execution_update when the session carries a persistent context pack.
 * Preserves the cite-or-omit / explicit-only invariants.
 */
class SessionCaptureStructuredLearningTest extends TestCase
{
    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $svc = (new ReflectionClass(AtlasOpenBrainSessionCaptureService::class))->newInstanceWithoutConstructor();

        return (new ReflectionClass(AtlasOpenBrainSessionCaptureService::class))->getMethod($method)->invoke($svc, ...$args);
    }

    public function test_structured_learning_splits_claim_why_files(): void
    {
        $l = $this->invokePrivate(
            'explicitLearning',
            'LEARNING: SIS8 usa journal em disco WHY: sobrevive ao DROP DATABASE FILES: app/Services/Ai/Brain/AtlasMemoryJournal.php',
            600,
        );

        $this->assertNotNull($l);
        $this->assertSame('SIS8 usa journal em disco', $l['claim']);
        $this->assertStringContainsString('sobrevive ao DROP DATABASE', $l['why']);
        $this->assertContains('app/Services/Ai/Brain/AtlasMemoryJournal.php', $l['files']);
        $this->assertNotEmpty($l['evidence_refs']);
        // byte-compat: the flat summary + evidence_refs keys still exist.
        $this->assertArrayHasKey('summary', $l);
    }

    public function test_learning_without_markers_is_backward_compatible(): void
    {
        $l = $this->invokePrivate('explicitLearning', 'LEARNING: algo mudou em app/Foo.php:12', 600);

        $this->assertNotNull($l);
        $this->assertSame('algo mudou em app/Foo.php:12', $l['claim']);
        $this->assertSame('', $l['why']);
        // files fall back to the file-like evidence refs.
        $this->assertContains('app/Foo.php:12', $l['files']);
        $this->assertContains('app/Foo.php:12', $l['evidence_refs']);
    }

    public function test_cite_or_omit_still_enforced(): void
    {
        $this->assertNull($this->invokePrivate('explicitLearning', 'LEARNING: uma frase sem nenhuma citação de arquivo', 600));
    }

    public function test_apcr_not_fed_without_a_pack_id(): void
    {
        $r = $this->invokePrivate('updateApcr', [], 'atlas-server', 'session:x', [
            ['summary' => 's', 'evidence_refs' => ['app/A.php:1'], 'claim' => 'c', 'why' => '', 'files' => []],
        ]);

        $this->assertFalse($r['fed']);
        $this->assertSame('no_apcr_pack', $r['reason']);
    }

    public function test_apcr_post_execution_update_is_filled_with_a_pack_id(): void
    {
        $r = $this->invokePrivate('updateApcr', ['persistent_context_pack_id' => 'pack-1'], 'atlas-server', 'session:x', [
            ['summary' => 's', 'evidence_refs' => ['app/A.php:1'], 'claim' => 'c decision', 'why' => 'porque', 'files' => ['app/A.php']],
        ]);

        // Delegates to the governed APCR runtime (creates a PENDING delta, never
        // auto-promotes); with evidence present the receipt status is 'recorded'.
        $this->assertSame('recorded', $r['status']);
        $this->assertNotNull($r['post_execution_update_hash']);
    }
}
