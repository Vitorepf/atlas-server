<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroIntentToPacketShapeProposer;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\CortexGroundingSnapshot;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\IntentFactBundle;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ProposalRefusal;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\ProposedPacketShape;
use PHPUnit\Framework\TestCase;

/**
 * Proves the QUAT-W330 proposer: deterministic byte-equal output across 100 invocations on identical inputs;
 * refusal with NO_SCOPE_GROUNDING when no symbol overlap exists (and zero file is written); refusal with
 * MULTI_SCOPE_AMBIGUOUS on multi-scope intent (queue is untouched — proven by spying that the proposer's
 * writer is never invoked); and the anchor-symbol invariant — every emitted ProposedPacketShape.objective
 * carries a symbol literal that actually exists in the provided Cortex snapshot.
 */
final class MaestroIntentToPacketShapeProposerTest extends TestCase
{
    private function cortexSnapshot(): CortexGroundingSnapshot
    {
        return new CortexGroundingSnapshot(
            symbols: ['App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopCortexThing', 'App\\Services\\Ai\\Maestro\\MaestroThing'],
            files: ['app/Services/Ai/AutonomousEvolution/AtlasLoopCortexThing.php', 'app/Services/Ai/Maestro/MaestroThing.php'],
            cortexId: 'cortex-run-1',
        );
    }

    private function loopOnlyIntent(): IntentFactBundle
    {
        return new IntentFactBundle(
            phrases: ['evolve the cortex thing', 'audit the cortex thing harder'],
            timestamps: [1_700_000_000, 1_700_000_010],
            scopeTags: ['cortex'],
            verbs: ['evolve', 'audit'],
        );
    }

    public function test_deterministic_byte_identical_output_across_100_invocations(): void
    {
        $proposer = new AtlasMaestroIntentToPacketShapeProposer(static function (): void { /* swallow writes */ });
        $intent = $this->loopOnlyIntent();
        $cortex = $this->cortexSnapshot();

        $first = $proposer->propose($intent, $cortex, 'wave-330');
        $this->assertInstanceOf(ProposedPacketShape::class, $first);
        $firstJson = $first->canonicalJson();
        $firstHash = hash('sha256', $firstJson);

        for ($i = 0; $i < 99; $i++) {
            $next = $proposer->propose($intent, $cortex, 'wave-330');
            $this->assertInstanceOf(ProposedPacketShape::class, $next);
            $this->assertSame($firstHash, hash('sha256', $next->canonicalJson()), "iter {$i}: identical inputs must yield byte-identical JSON");
        }
    }

    public function test_no_scope_grounding_when_zero_symbol_overlap_and_zero_file_written(): void
    {
        $writes = 0;
        $proposer = new AtlasMaestroIntentToPacketShapeProposer(static function () use (&$writes): void { $writes++; });

        $intent = new IntentFactBundle(
            phrases: ['do the totally unrelated thing'],
            timestamps: [1_700_000_000],
            scopeTags: ['something_not_in_cortex'],
            verbs: ['do'],
        );

        $result = $proposer->propose($intent, $this->cortexSnapshot(), 'wave-330');

        $this->assertInstanceOf(ProposalRefusal::class, $result);
        $this->assertSame(ProposalRefusal::CODE_NO_SCOPE_GROUNDING, $result->code);
        $this->assertSame(0, $writes, 'no file written on refusal');
    }

    public function test_multi_scope_ambiguous_refusal_with_zero_writes_and_queue_untouched(): void
    {
        $writes = 0;
        $proposer = new AtlasMaestroIntentToPacketShapeProposer(static function () use (&$writes): void { $writes++; });

        $intent = new IntentFactBundle(
            phrases: ['evolve loop and cortex together'],
            timestamps: [1_700_000_000],
            scopeTags: ['loop', 'cortex'], // mixes two primitive scopes
            verbs: ['evolve'],
        );

        $result = $proposer->propose($intent, $this->cortexSnapshot(), 'wave-330');

        $this->assertInstanceOf(ProposalRefusal::class, $result);
        $this->assertSame(ProposalRefusal::CODE_MULTI_SCOPE_AMBIGUOUS, $result->code);
        $this->assertStringContainsString('loop', $result->detail);
        $this->assertStringContainsString('cortex', $result->detail);
        $this->assertSame(0, $writes, 'queue/file untouched on multi-scope refusal');
    }

    public function test_empty_intent_refusal(): void
    {
        $proposer = new AtlasMaestroIntentToPacketShapeProposer(static function (): void { /* swallow */ });
        $result = $proposer->propose(new IntentFactBundle([], [], []), $this->cortexSnapshot(), 'wave-330');

        $this->assertInstanceOf(ProposalRefusal::class, $result);
        $this->assertSame(ProposalRefusal::CODE_EMPTY_INTENT, $result->code);
    }

    public function test_objective_contains_a_symbol_literal_from_cortex_snapshot(): void
    {
        $proposer = new AtlasMaestroIntentToPacketShapeProposer(static function (): void { /* swallow */ });
        $cortex = $this->cortexSnapshot();
        $result = $proposer->propose($this->loopOnlyIntent(), $cortex, 'wave-330');

        $this->assertInstanceOf(ProposedPacketShape::class, $result);
        $found = false;
        foreach ($cortex->symbols as $sym) {
            if (str_contains($result->objective, $sym)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'objective must contain at least one symbol literal present in the Cortex snapshot — no hallucinated anchor');
        $this->assertContains($result->anchorSymbol, $cortex->symbols, 'anchor_symbol must come from the snapshot');
    }

    public function test_proposer_writes_to_storage_path_via_injected_writer(): void
    {
        $writtenPath = null;
        $writtenJson = null;
        $proposer = new AtlasMaestroIntentToPacketShapeProposer(function (string $path, string $json) use (&$writtenPath, &$writtenJson): void {
            $writtenPath = $path;
            $writtenJson = $json;
        });

        $result = $proposer->propose($this->loopOnlyIntent(), $this->cortexSnapshot(), 'wave-330');

        $this->assertInstanceOf(ProposedPacketShape::class, $result);
        $this->assertIsString($writtenPath);
        $this->assertStringContainsString('atlas/maestro/dialogue/proposed/', (string) $writtenPath, 'proposer writes to the storage proposed dir');
        $this->assertStringEndsWith($result->taskPacketId.'.json', (string) $writtenPath);
        $this->assertSame($result->canonicalJson(), $writtenJson);
    }

    public function test_out_of_range_symbol_index_resolves_to_empty_file_not_files_zero(): void
    {
        // CortexGroundingSnapshot has no length-parity guarantee: symbols can outnumber files.
        // A symbol matched at an out-of-range index must resolve to '' (no hallucinated anchor).
        $cortex = new CortexGroundingSnapshot(
            symbols: ['App\\Services\\Ai\\Maestro\\MaestroThing'],
            files: [],  // no files at all — every index is out of range
            cortexId: 'cortex-run-2',
        );

        $proposer = new AtlasMaestroIntentToPacketShapeProposer(static function (): void { /* swallow */ });
        $intent = new IntentFactBundle(
            phrases: ['evolve the maestro thing'],
            timestamps: [1_700_000_000],
            scopeTags: ['maestro'],
            verbs: ['evolve'],
        );

        $result = $proposer->propose($intent, $cortex, 'wave-330');

        $this->assertInstanceOf(ProposedPacketShape::class, $result);
        $this->assertSame('', $result->anchorFile, 'out-of-range symbol index must resolve to empty file, not a wrong files[0]');
        $this->assertSame([], $result->allowedFiles);
    }
}
