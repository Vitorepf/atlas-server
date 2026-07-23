<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\AtlasOpenBrainSessionCaptureService;
use App\Services\Ai\Obra\AtlasDeterministicBriefService;
use App\Services\Ai\Obra\AtlasSpecCritiqueService;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * WO-17-T2 — "lembra por quê e avisa antes":
 *   - the deterministic brief round-trips + reports staleness (STALE the moment HEAD
 *     moves past it) and the pack surfaces "BRIEF STALE" — never a silent stale brief;
 *   - the spec critique flags a spec that collides with a refutation BEFORE code;
 *   - the Stop hook captures the delta-de-surpresa marker as a G0 candidate.
 */
final class AtlasDeterministicBriefTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private string $briefDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        $this->briefDir = sys_get_temp_dir().'/atlas-brief-'.bin2hex(random_bytes(6));
        $this->app->instance(
            AtlasDeterministicBriefService::class,
            new AtlasDeterministicBriefService($this->app->make(AtlasMemoryPrivacyService::class), $this->briefDir),
        );
    }

    protected function tearDown(): void
    {
        $this->dropAtlasMemoryEntryTable();
        foreach (glob($this->briefDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->briefDir);
        parent::tearDown();
    }

    private function brief(): AtlasDeterministicBriefService
    {
        return $this->app->make(AtlasDeterministicBriefService::class);
    }

    private function writeBrief(array $overrides): void
    {
        $brief = array_merge([
            'schema' => AtlasDeterministicBriefService::SCHEMA,
            'scope' => 'atlas-server',
            'head' => 'deadbeef',
            'generated_at' => '2026-07-01T00:00:00Z',
            'hot_files' => [],
            'modules' => [['module' => 'app/Services/Ai/Obra', 'changes' => 9]],
            'invariants' => ['Docs canônicos governam implementação'],
            'refutations' => ['NÃO re-propor Number::clamp para gates NaN'],
        ], $overrides);
        @mkdir($this->briefDir, 0775, true);
        file_put_contents($this->brief()->path('atlas-server'), json_encode($brief));
    }

    private function currentHead(): string
    {
        return trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD 2>/dev/null'));
    }

    private function seedRefutation(string $title): AtlasMemoryEntry
    {
        return AtlasMemoryEntry::query()->create([
            'id' => (string) Str::uuid7(),
            'memory_type' => 'refutation_memory',
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $title,
            'body' => $title,
            'status' => 'active',
            'priority' => 80,
            'importance' => 5,
            'confidence' => 0.9,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'source_type' => 'test_fixture',
        ]);
    }

    public function test_brief_staleness_flips_when_head_moves(): void
    {
        $this->writeBrief(['head' => $this->currentHead() ?: 'HEADNOW']);
        $fresh = $this->brief()->staleness($this->brief()->read());
        $this->assertFalse($fresh['stale'], 'um brief gerado no HEAD atual é fresh');

        $this->writeBrief(['head' => '0000000old']);
        $stale = $this->brief()->staleness($this->brief()->read());
        $this->assertTrue($stale['stale'], 'quando o HEAD anda, o brief fica STALE');
        $this->assertSame('head_moved', $stale['reason']);
    }

    public function test_pack_surfaces_brief_stale_never_silent(): void
    {
        $this->writeBrief(['head' => '0000000old', 'generated_at' => '2026-07-01T00:00:00Z']);
        $pack = $this->app->make(AtlasOpenBrainContextPackService::class)->packFor('mexer no brief');
        $md = (string) $pack['markdown'];

        $this->assertStringContainsString('## Brief (determinístico)', $md);
        $this->assertStringContainsString('BRIEF STALE desde 2026-07-01T00:00:00Z', $md);
        $this->assertStringContainsString('invariantes (pétreas)', $md);
    }

    public function test_spec_critique_flags_a_spec_that_collides_with_a_refutation(): void
    {
        $this->seedRefutation('NÃO re-propor Number::clamp para gates NaN (veto herdado da obra 8)');

        $critique = $this->app->make(AtlasSpecCritiqueService::class)
            ->critique('Vou introduzir Number::clamp nos gates para tratar NaN de forma uniforme.');

        $this->assertSame('concerns_found', $critique['verdict']);
        $this->assertGreaterThanOrEqual(1, $critique['concerns_count']);
    }

    public function test_stop_hook_captures_the_surprise_delta_as_g0_candidate(): void
    {
        $result = $this->app->make(AtlasOpenBrainSessionCaptureService::class)->captureSession([
            'transcript_lines' => [
                ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [
                    ['type' => 'text', 'text' => "trabalhei no pack\nATLAS-SURPRISE: recall não repassava a pergunta ao candidate set — o pack não tinha isso"],
                ]]],
            ],
        ]);

        $this->assertContains(
            'recall não repassava a pergunta ao candidate set — o pack não tinha isso',
            $result['surprise_delta'],
            'a surpresa marcada precisa virar candidato G0 no Stop hook',
        );
    }
}
