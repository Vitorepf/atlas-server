<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\AtlasContextObservabilityToRankingReflexiveBridgeService;
use Tests\TestCase;

class AtlasContextObservabilityToRankingReflexiveBridgeServiceTest extends TestCase
{
    private string $streamPath;

    private AtlasContextObservabilityToRankingReflexiveBridgeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->streamPath = sys_get_temp_dir().'/atlas_acop_acrs_'.uniqid('', true).'.jsonl';
        $this->svc = new AtlasContextObservabilityToRankingReflexiveBridgeService;
        $this->svc->setStreamPathForTesting($this->streamPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->streamPath);
        parent::tearDown();
    }

    public function test_emit_envelope_shape(): void
    {
        $s = $this->svc->emit([
            'kind' => 'context_quality',
            'severity' => 'medium',
            'value' => 0.62,
            'scope' => ['domain' => 'engineering'],
            'rationale' => 'recent retrievals show drop',
        ]);
        $this->assertSame(AtlasContextObservabilityToRankingReflexiveBridgeService::SIGNAL_SCHEMA, $s['schema_version']);
        $this->assertStringStartsWith('acop_', $s['signal_id']);
        $this->assertStringStartsWith('sha256:', $s['signal_hash']);
    }

    public function test_unknown_kind_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->emit(['kind' => 'martian']);
    }

    public function test_unknown_severity_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->emit([
            'kind' => 'context_quality',
            'severity' => 'galactic',
        ]);
    }

    public function test_signals_persisted_append_only(): void
    {
        $this->svc->emit(['kind' => 'context_quality', 'severity' => 'low']);
        $this->svc->emit(['kind' => 'retrieval_latency', 'severity' => 'high']);
        $this->svc->emit(['kind' => 'leak_risk', 'severity' => 'medium']);
        $this->assertCount(3, $this->svc->listSignals());
    }

    public function test_list_respects_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->svc->emit(['kind' => 'cost_pressure', 'severity' => 'low']);
        }
        $this->assertCount(5, $this->svc->listSignals(5));
        $this->assertCount(10, $this->svc->listSignals(0));
    }

    public function test_summary_tallies_by_kind_severity(): void
    {
        $this->svc->emit(['kind' => 'context_quality', 'severity' => 'low']);
        $this->svc->emit(['kind' => 'context_quality', 'severity' => 'high']);
        $this->svc->emit(['kind' => 'retrieval_latency', 'severity' => 'medium']);
        $s = $this->svc->summary();
        $this->assertSame(3, $s['total_signals']);
        $this->assertSame(1, $s['by_kind']['context_quality']['low']);
        $this->assertSame(1, $s['by_kind']['context_quality']['high']);
        $this->assertSame(1, $s['by_kind']['retrieval_latency']['medium']);
    }
}
