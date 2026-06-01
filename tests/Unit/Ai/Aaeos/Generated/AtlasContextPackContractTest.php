<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasContextPackContractService;
use Tests\TestCase;

/**
 * Pins the documented Context Pack Contract rules: 9-field ref shape, the
 * max-8 size policy with architecture/maintenance/capabilities ranking + tag
 * boost, hash de-duplication, the blueprint-nucleus autonomy gate and safe-fail.
 *
 * @see docs/engineering-knowledge-base/context-pack.md
 */
class AtlasContextPackContractTest extends TestCase
{
    private function service(): AtlasContextPackContractService
    {
        return new AtlasContextPackContractService();
    }

    /**
     * @return array<string,mixed>
     */
    private function ref(string $id, string $category, int $priority, string $hash): array
    {
        return [
            'id' => $id,
            'slug' => $id,
            'title' => ucfirst($id),
            'category' => $category,
            'priority' => $priority,
            'canonical_path' => "docs/{$id}.md",
            'content_hash' => $hash,
            'summary' => "Summary for {$id}.",
            'reason' => 'test ref',
        ];
    }

    /**
     * Ref shape: a ref missing any of the 9 required fields is invalid and is
     * dropped from the pack (never rendered malformed).
     */
    public function test_ref_missing_required_field_is_rejected(): void
    {
        $svc = $this->service();

        $check = $svc->validateRef([
            'id' => 'x', 'slug' => 'x', 'title' => 'X', 'category' => 'architecture',
            'priority' => 1, 'canonical_path' => 'docs/x.md',
            // content_hash + summary + reason missing
        ]);

        $this->assertFalse($check['valid']);
        $this->assertSame(['content_hash', 'summary', 'reason'], $check['missing_fields']);

        $pack = $svc->build([
            'knowledge_refs' => [
                $this->ref('good', 'architecture', 50, 'h-good'),
                ['id' => 'bad', 'slug' => 'bad', 'title' => 'Bad', 'category' => 'reference', 'priority' => 9],
            ],
        ]);

        $this->assertSame(1, $pack['ref_count']);
        $this->assertSame('good', $pack['knowledge_refs'][0]['id']);
        $this->assertSame('bad', $pack['rejected_refs'][0]['ref']);
    }

    /**
     * Size policy: "Por padrao, o Harness usa ate 8 referencias." 10 valid refs
     * (distinct hashes) => capped at 8 and flagged truncated.
     */
    public function test_caps_at_eight_references_by_default(): void
    {
        $refs = [];
        for ($i = 1; $i <= 10; $i++) {
            $refs[] = $this->ref("r{$i}", 'reference', 100 - $i, "h-{$i}");
        }

        $pack = $this->service()->build(['knowledge_refs' => $refs]);

        $this->assertSame(AtlasContextPackContractService::DEFAULT_MAX_REFS, $pack['max_refs']);
        $this->assertSame(8, $pack['ref_count']);
        $this->assertTrue($pack['truncated']);
    }

    /**
     * Ranking: architecture/maintenance/capabilities categories rank above a
     * plain reference even when the reference has a much higher numeric priority.
     */
    public function test_priority_categories_rank_above_plain_reference(): void
    {
        $pack = $this->service()->build([
            'knowledge_refs' => [
                $this->ref('plain', 'reference', 999, 'h-plain'),
                $this->ref('arch', 'architecture', 1, 'h-arch'),
                $this->ref('maint', 'maintenance', 1, 'h-maint'),
            ],
        ]);

        $order = array_column($pack['knowledge_refs'], 'id');
        $this->assertSame(['arch', 'maint', 'plain'], $order);
    }

    /**
     * Tag boost: "boost para categorias relacionadas a tags da task". A ref whose
     * category matches a task tag jumps ahead of a higher-priority-category ref.
     */
    public function test_task_tag_boosts_matching_category(): void
    {
        $pack = $this->service()->build([
            'task_tags' => ['capabilities'],
            'knowledge_refs' => [
                $this->ref('arch', 'architecture', 10, 'h-arch'),
                $this->ref('caps', 'capabilities', 10, 'h-caps'),
            ],
        ]);

        // Without the tag, architecture (rank 0) would beat capabilities (rank 2);
        // the matching tag boosts capabilities to the front.
        $this->assertSame('caps', $pack['knowledge_refs'][0]['id']);
    }

    /**
     * No-duplicate rule: two refs sharing a content_hash collapse to a single
     * auditable section.
     */
    public function test_duplicate_content_hash_collapses_to_single_ref(): void
    {
        $pack = $this->service()->build([
            'knowledge_refs' => [
                $this->ref('a', 'architecture', 50, 'same-hash'),
                $this->ref('b', 'maintenance', 40, 'same-hash'),
                $this->ref('c', 'capabilities', 30, 'other-hash'),
            ],
        ]);

        $this->assertSame(2, $pack['ref_count']);
        $this->assertSame(1, $pack['duplicate_hashes_collapsed']);
        $hashes = array_column($pack['knowledge_refs'], 'content_hash');
        $this->assertSame(['same-hash', 'other-hash'], $hashes);
    }

    /**
     * Blueprint-refs autonomy gate: a medium/high risk task without the full
     * blueprint nucleus must NOT get full autonomy ("nao deve receber autonomia
     * total para concluir task de risco medio/alto") — investigate only. With the
     * complete nucleus, full autonomy is restored. Low risk is never gated.
     */
    public function test_blueprint_nucleus_gates_autonomy_for_medium_high_risk(): void
    {
        $svc = $this->service();

        $denied = $svc->build([
            'risk_level' => 'high',
            'blueprint_refs' => ['task_contract', 'engineering_blueprint'], // incomplete
            'knowledge_refs' => [$this->ref('arch', 'architecture', 1, 'h')],
        ]);
        $this->assertFalse($denied['autonomy']['full_autonomy_allowed']);
        $this->assertSame(
            AtlasContextPackContractService::AUTONOMY_INVESTIGATE_ONLY,
            $denied['autonomy']['verdict'],
        );
        $this->assertContains('review_gates', $denied['autonomy']['missing_blueprint_refs']);

        $allowed = $svc->build([
            'risk_level' => 'high',
            'blueprint_refs' => AtlasContextPackContractService::CORE_BLUEPRINT_REFS,
            'knowledge_refs' => [$this->ref('arch', 'architecture', 1, 'h')],
        ]);
        $this->assertTrue($allowed['autonomy']['full_autonomy_allowed']);
        $this->assertSame(
            AtlasContextPackContractService::AUTONOMY_FULL,
            $allowed['autonomy']['verdict'],
        );
        $this->assertSame([], $allowed['autonomy']['missing_blueprint_refs']);

        // Low risk is never gated even with an empty nucleus.
        $lowRisk = $svc->build([
            'risk_level' => 'low',
            'blueprint_refs' => [],
            'knowledge_refs' => [],
        ]);
        $this->assertTrue($lowRisk['autonomy']['full_autonomy_allowed']);
    }

    /**
     * Safe-fail: "Se a migration ainda nao rodou ou a tabela nao existe, o
     * context pack continua funcionando sem knowledge refs." No table => empty,
     * degraded, valid pack — no exception.
     */
    public function test_missing_table_degrades_safely(): void
    {
        $pack = $this->service()->build([
            'table_present' => false,
            'knowledge_refs' => [$this->ref('arch', 'architecture', 1, 'h')], // ignored
        ]);

        $this->assertTrue($pack['degraded']);
        $this->assertSame(0, $pack['ref_count']);
        $this->assertSame([], $pack['knowledge_refs']);
        $this->assertFalse($pack['truncated']);
    }
}
