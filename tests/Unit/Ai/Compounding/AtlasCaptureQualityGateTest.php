<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Services\Ai\Compounding\AtlasCaptureQualityGate;
use PHPUnit\Framework\TestCase;

/**
 * The content-quality gate must REJECT the real noise families (proven 100% on live
 * data) WITHOUT rejecting a genuinely substantive learning — a gate that rejects
 * everything is useless. Plus: identical content collapses to one hash.
 */
class AtlasCaptureQualityGateTest extends TestCase
{
    private function gate(): AtlasCaptureQualityGate
    {
        return new AtlasCaptureQualityGate();
    }

    public function test_admits_a_substantive_learning(): void
    {
        $v = $this->gate()->assess([
            'kind' => 'failure_pattern',
            'claim' => 'A transient Postgres connection blip during the loop campaign should trigger a retry+reconnect before parking the cycle, not an immediate abort.',
            'content' => [
                'trigger' => 'transient postgres connection error mid-campaign',
                'action' => 'retry with backoff then DB::reconnect, abort only after sustained outage',
                'evidence' => 'observed 3 false aborts on transient blips',
            ],
        ]);

        $this->assertTrue($v['admit'], 'a real learning must be admitted; got '.$v['reason']);
        $this->assertSame(AtlasCaptureQualityGate::REASON_OK, $v['reason']);
        $this->assertGreaterThanOrEqual(20, $v['quality_score']);
    }

    public function test_admits_a_claim_only_learning_with_empty_content(): void
    {
        // The dominant false-positive the adversarial hunt found: a real learning whose
        // value lives entirely in the claim (empty structured content) must be ADMITTED.
        $v = $this->gate()->assess([
            'kind' => 'retrieval_hint',
            'claim' => 'Prefer the canonical repo doc over the Obsidian projection, which lags by a sync cycle.',
            'content' => [],
        ]);

        $this->assertTrue($v['admit'], 'claim-only learning must be admitted; got '.$v['reason']);
    }

    public function test_admits_non_latin_and_acronym_dense_learnings(): void
    {
        // pt-BR operator rule + CJK finance rule + acronym-dense gotcha must all survive
        // (the ASCII-only tokenizer used to score them ~0).
        $cases = [
            ['kind' => 'memory', 'claim' => 'Nunca usar o modo fraco do Hermes; sempre o modo mais forte.', 'content' => []],
            ['kind' => 'memory', 'claim' => '投资组合优化必须使用 N-deflated 夏普比率 加 PBO 与 holdout，禁止使用胜率。', 'content' => []],
            ['kind' => 'failure_pattern', 'claim' => 'N+1 on SQL hit p99; JIT off, GC churn, OOM in CI.', 'content' => ['err' => 'oom']],
        ];
        foreach ($cases as $c) {
            $v = $this->gate()->assess($c);
            $this->assertTrue($v['admit'], 'must admit: '.$c['claim'].' — got '.$v['reason'].' score '.$v['quality_score']);
        }
    }

    public function test_rejects_the_contentless_template(): void
    {
        // The exact empty template that produced 68 identical proposals this week.
        $v = $this->gate()->assess([
            'kind' => 'failure_pattern',
            'claim' => '',
            'content' => ['should_repromote_sources' => [], 'should_demote_noise_count' => 0, 'target_context_sufficiency_min' => 70],
        ]);

        $this->assertFalse($v['admit']);
        $this->assertSame(AtlasCaptureQualityGate::REASON_CONTENTLESS, $v['reason']);
    }

    public function test_rejects_the_meta_stub(): void
    {
        $v = $this->gate()->assess([
            'kind' => 'routing_memory',
            'claim' => 'Specialist flow atlas_conversation emitted a learning signal contract for future routing, retrieval and execution evaluation.',
            'content' => [],
        ]);

        $this->assertFalse($v['admit']);
        $this->assertSame(AtlasCaptureQualityGate::REASON_META_STUB, $v['reason']);
    }

    public function test_rejects_the_fixture_echo(): void
    {
        $v = $this->gate()->assess([
            'kind' => 'routing_memory',
            'claim' => 'Flow Reply with exactly: ATLAS_COMPOUND_OK produced passed outcome.',
            'content' => [],
        ]);

        $this->assertFalse($v['admit']);
        $this->assertSame(AtlasCaptureQualityGate::REASON_FIXTURE_ECHO, $v['reason']);
    }

    public function test_identical_content_collapses_to_one_hash_despite_different_metadata(): void
    {
        $a = $this->gate()->assess([
            'kind' => 'memory',
            'claim' => 'summary text A',
            'content' => ['action' => 'retry on blip', 'candidate_hash' => 'aaa', 'flow_id' => 'f1'],
        ]);
        $b = $this->gate()->assess([
            'kind' => 'memory',
            'claim' => 'a completely different summary B',
            'content' => ['action' => 'retry on blip', 'candidate_hash' => 'zzz', 'flow_id' => 'f2'],
        ]);

        // Same meaningful content + volatile metadata stripped ⇒ same hash (dedups).
        $this->assertSame($a['content_hash'], $b['content_hash']);
    }

    public function test_different_content_yields_different_hashes(): void
    {
        $a = $this->gate()->assess(['kind' => 'memory', 'content' => ['action' => 'retry on blip']]);
        $b = $this->gate()->assess(['kind' => 'memory', 'content' => ['action' => 'park the cycle']]);

        $this->assertNotSame($a['content_hash'], $b['content_hash']);
    }

    /**
     * F5 (sweep O-1): "smoke test" é vocabulário normal de engenharia — um learning REAL
     * de falha de teste que o mencione NÃO pode ser dropado como fixture_echo (em enforce
     * o drop é transiente e destrutivo: o conteúdo some sem trilha).
     */
    public function test_a_real_learning_mentioning_smoke_test_is_admitted(): void
    {
        $v = $this->gate()->assess([
            'kind' => 'failure_pattern',
            'claim' => 'Remediation failed: smoke test atlas_dev_smoke timing out after paratest upgrade; pin paratest 7.4 and keep the 512M memory floor.',
            'content' => [
                'trigger' => 'paratest 7.5 upgrade broke the timeout budget',
                'action' => 'pin paratest 7.4 + 512M floor until upstream fix',
            ],
        ]);

        $this->assertTrue($v['admit'], 'learning real mencionando smoke test deve ser admitido; got '.$v['reason']);
        $this->assertSame(AtlasCaptureQualityGate::REASON_OK, $v['reason']);
    }

    public function test_a_real_fixture_echo_with_multiple_weak_markers_is_still_rejected(): void
    {
        $v = $this->gate()->assess([
            'kind' => 'routing_memory',
            'claim' => 'Deterministic smoke run for readiness simulation completed and produced a passed outcome.',
            'content' => [],
        ]);

        $this->assertFalse($v['admit']);
        $this->assertSame(AtlasCaptureQualityGate::REASON_FIXTURE_ECHO, $v['reason']);
    }

    /**
     * F6 (sweep O-1): variação trivial — caixa, whitespace, ordem de chaves aninhadas,
     * lista de escalares reordenada — é exatamente o que um gerador LLM produz a cada
     * run; o hash de dedup tem que colapsar tudo isso para o MESMO valor.
     */
    public function test_trivial_variation_collapses_to_the_same_hash(): void
    {
        $base = $this->gate()->assess([
            'kind' => 'memory',
            'content' => ['action' => 'Retry on blip', 'detail' => ['a' => 1, 'b' => 2], 'refs' => ['ev-1', 'ev-2']],
        ]);
        $caseAndWhitespace = $this->gate()->assess([
            'kind' => 'memory',
            'content' => ['action' => '  retry   ON blip ', 'detail' => ['a' => 1, 'b' => 2], 'refs' => ['ev-1', 'ev-2']],
        ]);
        $nestedKeyOrder = $this->gate()->assess([
            'kind' => 'memory',
            'content' => ['refs' => ['ev-1', 'ev-2'], 'detail' => ['b' => 2, 'a' => 1], 'action' => 'retry on blip'],
        ]);
        $scalarListOrder = $this->gate()->assess([
            'kind' => 'memory',
            'content' => ['action' => 'retry on blip', 'detail' => ['a' => 1, 'b' => 2], 'refs' => ['ev-2', 'ev-1']],
        ]);

        $this->assertSame($base['content_hash'], $caseAndWhitespace['content_hash'], 'caixa/whitespace não pode quebrar dedup');
        $this->assertSame($base['content_hash'], $nestedKeyOrder['content_hash'], 'ordem de chaves aninhadas não pode quebrar dedup');
        $this->assertSame($base['content_hash'], $scalarListOrder['content_hash'], 'lista de escalares reordenada não pode quebrar dedup');
    }
}
