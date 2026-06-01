<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasHumanKnowledgeSurfaceService;
use Tests\TestCase;

/**
 * Pins the documented Human Knowledge Surface (HKS) authority rules.
 *
 * @see docs/engineering-knowledge-base/system-graph/hks.md
 */
class AtlasHumanKnowledgeSurfaceTest extends TestCase
{
    private function service(): AtlasHumanKnowledgeSurfaceService
    {
        return new AtlasHumanKnowledgeSurfaceService();
    }

    /**
     * Contratos invariant: repo is technical truth; every human-surface source
     * (vault, obsidian, book, note, human) is curated context, never technical
     * truth; an unrecognised source is unknown/untrusted.
     */
    public function test_authority_tiers_separate_repo_canon_from_human_surface(): void
    {
        $svc = $this->service();

        $repo = $svc->classifyAuthority('repo');
        $this->assertSame(AtlasHumanKnowledgeSurfaceService::TIER_TECHNICAL_TRUTH, $repo['tier']);
        $this->assertTrue($repo['technical_truth']);
        $this->assertSame('Repo Canon · Technical Truth', $repo['badge']);

        foreach (['vault', 'obsidian', 'book', 'note', 'human'] as $human) {
            $tier = $svc->classifyAuthority($human);
            $this->assertSame(
                AtlasHumanKnowledgeSurfaceService::TIER_CURATED_CONTEXT,
                $tier['tier'],
                "{$human} must be curated context",
            );
            // A human source is trusted-for-context but is NEVER technical truth.
            $this->assertFalse($tier['technical_truth'], "{$human} must not be technical truth");
            $this->assertTrue($tier['trusted']);
        }

        $unknown = $svc->classifyAuthority('dropbox');
        $this->assertSame(AtlasHumanKnowledgeSurfaceService::TIER_UNKNOWN, $unknown['tier']);
        $this->assertFalse($unknown['trusted']);
        $this->assertSame('Unknown · Untrusted', $unknown['badge']);
    }

    /**
     * Saida invariant ("contexto curado com source"): a sourceless item is
     * rejected, an unknown source is rejected, and a trusted item is admitted
     * carrying its tier.
     */
    public function test_context_pack_admission_requires_a_trusted_source(): void
    {
        $svc = $this->service();

        $noSource = $svc->admitToContextPack([]);
        $this->assertFalse($noSource['admitted']);
        $this->assertSame(AtlasHumanKnowledgeSurfaceService::TIER_UNKNOWN, $noSource['tier']);
        $this->assertTrue(
            (bool) array_filter($noSource['reasons'], static fn (string $r): bool => str_contains($r, 'curated context must carry an explicit source')),
        );

        $unknown = $svc->admitToContextPack(['source' => 'pastebin']);
        $this->assertFalse($unknown['admitted']);
        $this->assertTrue(
            (bool) array_filter($unknown['reasons'], static fn (string $r): bool => str_contains($r, "unknown source 'pastebin'")),
        );

        $vault = $svc->admitToContextPack(['source' => 'vault']);
        $this->assertTrue($vault['admitted']);
        $this->assertSame(AtlasHumanKnowledgeSurfaceService::TIER_CURATED_CONTEXT, $vault['tier']);

        $repo = $svc->admitToContextPack(['source' => 'repo']);
        $this->assertTrue($repo['admitted']);
        $this->assertSame(AtlasHumanKnowledgeSurfaceService::TIER_TECHNICAL_TRUTH, $repo['tier']);
    }

    /**
     * Escopo invariant: a human note can overwrite official docs ONLY with an
     * explicit decision + canon_target; without a decision it is forbidden; an
     * unknown source can never overwrite; repo canon governs itself.
     */
    public function test_canon_overwrite_requires_an_explicit_decision(): void
    {
        $svc = $this->service();

        // Note with no decision -> forbidden.
        $noDecision = $svc->canOverwriteCanon(['source' => 'note']);
        $this->assertFalse($noDecision['allowed']);
        $this->assertTrue($noDecision['requires_decision']);
        $this->assertTrue(
            (bool) array_filter($noDecision['reasons'], static fn (string $r): bool => str_contains($r, 'without an explicit governance decision')),
        );

        // Note WITH a decision + a canon target -> allowed.
        $withDecision = $svc->canOverwriteCanon([
            'source' => 'note',
            'decision' => 'DEC-2026-014',
            'canon_target' => 'docs/engineering-knowledge-base/atlas-ai-master-architecture.md',
        ]);
        $this->assertTrue($withDecision['allowed'], implode(' | ', $withDecision['reasons']));

        // Decision present but no target -> still rejected for the missing target.
        $noTarget = $svc->canOverwriteCanon([
            'source' => 'note',
            'decision' => 'DEC-2026-014',
        ]);
        $this->assertFalse($noTarget['allowed']);
        $this->assertTrue(
            (bool) array_filter($noTarget['reasons'], static fn (string $r): bool => str_contains($r, 'name the canon_target')),
        );

        // Unknown source can never overwrite, decision or not.
        $unknown = $svc->canOverwriteCanon(['source' => 'dropbox', 'decision' => 'DEC-1', 'canon_target' => 'x.md']);
        $this->assertFalse($unknown['allowed']);

        // Repo canon governs itself -> allowed without a decision.
        $repo = $svc->canOverwriteCanon(['source' => 'repo']);
        $this->assertTrue($repo['allowed']);
        $this->assertFalse($repo['requires_decision']);
    }

    /**
     * Riscos: flag drift between a human note and canon, and a reflection being
     * treated as an executable contract.
     */
    public function test_drift_detection_flags_note_vs_canon_and_reflection_as_contract(): void
    {
        $svc = $this->service();

        $drift = $svc->detectDrift([
            'source' => 'note',
            'contradicts_canon' => true,
        ]);
        $this->assertTrue($drift['has_drift']);
        $this->assertTrue(
            (bool) array_filter($drift['signals'], static fn (string $s): bool => str_contains($s, 'drift between note and technical canon')),
        );

        $asContract = $svc->detectDrift([
            'source' => 'reflection',
            'treated_as_contract' => true,
        ]);
        $this->assertTrue($asContract['has_drift']);
        $this->assertTrue(
            (bool) array_filter($asContract['signals'], static fn (string $s): bool => str_contains($s, 'executable contract')),
        );

        // A contradiction reconciled by a decision is NOT drift.
        $reconciled = $svc->detectDrift([
            'source' => 'note',
            'contradicts_canon' => true,
            'decision' => 'DEC-2026-014',
        ]);
        $this->assertFalse($reconciled['has_drift']);
    }

    /**
     * gate() composition: a sourceless note denies; a human note requesting an
     * overwrite without a decision denies; a clean vault note allows.
     */
    public function test_gate_composes_admission_overwrite_and_drift(): void
    {
        $svc = $this->service();

        $sourceless = $svc->gate(['source' => '']);
        $this->assertSame(AtlasHumanKnowledgeSurfaceService::VERDICT_DENY, $sourceless['verdict']);

        $overwrite = $svc->gate(['source' => 'note', 'requests_overwrite' => true]);
        $this->assertSame(AtlasHumanKnowledgeSurfaceService::VERDICT_DENY, $overwrite['verdict']);
        $this->assertTrue(
            (bool) array_filter($overwrite['reasons'], static fn (string $r): bool => str_contains($r, 'without an explicit governance decision')),
        );

        $clean = $svc->gate(['source' => 'vault']);
        $this->assertSame(AtlasHumanKnowledgeSurfaceService::VERDICT_ALLOW, $clean['verdict']);
        $this->assertSame([], $clean['reasons']);
        $this->assertSame('Human Surface · Curated Context', $clean['authority']['badge']);
    }
}
