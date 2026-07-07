<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\LongHorizon\LongHorizonContinuityPackEmitterService;
use App\Services\Ai\Obra\AtlasObraStateService;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * WO-17-T1 — "retomei e ele sabia". Locks the resumption spine over a temp obra dir:
 *   - obra state round-trips (write session footprint → read it back);
 *   - when an obra is ACTIVE, the context pack carries a "## Retomada da obra ativa"
 *     section FIRST (the resuming session sees where it was in turn 1 — the ≤3-turns
 *     gate: the facts are in the pack, no exploration needed);
 *   - with NO active obra the pack is byte-compatible (no resumption section);
 *   - the religated LongHorizon emitter's read fail-opens (never breaks the pack).
 */
final class AtlasObraResumptionPackTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private string $obraDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        $this->obraDir = sys_get_temp_dir().'/atlas-obras-'.bin2hex(random_bytes(6));
        // Bind a temp-dir state service so the pack + capture read/write here, not real storage.
        $this->app->instance(AtlasObraStateService::class, new AtlasObraStateService($this->obraDir));
    }

    protected function tearDown(): void
    {
        $this->dropAtlasMemoryEntryTable();
        foreach (glob($this->obraDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->obraDir);
        parent::tearDown();
    }

    private function state(): AtlasObraStateService
    {
        return $this->app->make(AtlasObraStateService::class);
    }

    public function test_obra_state_round_trips(): void
    {
        $state = $this->state();
        $state->setCurrent('obra-17');
        $this->assertSame('obra-17', $state->currentId());

        $state->recordSession('obra-17', [
            'session_id' => 'sess-1',
            'files' => ['app/Services/Ai/Obra/AtlasObraStateService.php'],
            'result' => ['delivered' => true],
            'request' => 'construir estado por obra',
        ]);

        $read = $state->read('obra-17');
        $this->assertNotNull($read);
        $this->assertCount(1, $read['sessions']);
        $this->assertSame('sess-1', $read['sessions'][0]['session_id']);
        $this->assertContains('app/Services/Ai/Obra/AtlasObraStateService.php', $read['sessions'][0]['files']);
    }

    public function test_resumption_section_is_present_and_first_when_obra_is_active(): void
    {
        $state = $this->state();
        $state->setCurrent('obra-17');
        $state->recordSession('obra-17', [
            'session_id' => 'sess-1',
            'files' => ['app/Services/Ai/Obra/AtlasObraStateService.php', 'tests/Feature/Ai/Obra/AtlasObraResumptionPackTest.php'],
            'result' => ['delivered' => true],
            'request' => 'estado por obra + pack de retomada',
        ], ['phase' => 'T1', 'pendencies' => ['matcher de refutação na admissão']]);

        $pack = $this->app->make(AtlasOpenBrainContextPackService::class)->packFor('continuar a obra 17');
        $md = (string) $pack['markdown'];

        $this->assertStringContainsString('## Retomada da obra ativa', $md, 'a sessão retomada precisa ver a seção de retomada');
        $this->assertStringContainsString('obra=obra-17', $md);
        $this->assertStringContainsString('fase=T1', $md);
        $this->assertStringContainsString('última sessão: tocou 2 arquivo(s)', $md);
        $this->assertStringContainsString('falta: matcher de refutação na admissão', $md);
        // Retomada vem ANTES do code graph (é a primeira coisa que importa ao retomar).
        $this->assertLessThan(
            strpos($md, '## Code graph') ?: PHP_INT_MAX,
            strpos($md, '## Retomada da obra ativa'),
            'a retomada precisa vir antes do code graph',
        );
    }

    public function test_no_active_obra_pack_has_no_resumption_section(): void
    {
        // No setCurrent() → no active obra → pack must not carry the resumption section.
        $pack = $this->app->make(AtlasOpenBrainContextPackService::class)->packFor('tarefa qualquer');
        $this->assertStringNotContainsString('## Retomada da obra ativa', (string) $pack['markdown']);
        $this->assertFalse(($pack['retomada']['present'] ?? false));
    }

    public function test_religated_emitter_read_fails_open_to_null(): void
    {
        // No continuation pack (and possibly no table in sqlite) → null, never a throw.
        $continuity = $this->app->make(LongHorizonContinuityPackEmitterService::class)
            ->latestContinuityFor('obra', 'obra-17');
        $this->assertNull($continuity);
    }
}
