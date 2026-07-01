<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStaticAnalysisBridge;
use Tests\TestCase;

final class AtlasExternalBrainStaticAnalysisBridgeTest extends TestCase
{
    private function bridge(): AtlasExternalBrainStaticAnalysisBridge
    {
        return new AtlasExternalBrainStaticAnalysisBridge;
    }

    public function test_schema_present(): void
    {
        $r = $this->bridge()->normalize(['findings' => []]);
        $this->assertSame(AtlasExternalBrainStaticAnalysisBridge::SCHEMA, $r['schema']);
    }

    public function test_empty_findings_is_compression_eligible(): void
    {
        $r = $this->bridge()->normalize(['findings' => []]);
        $this->assertTrue($r['compression_eligible']);
        $this->assertSame([], $r['blocking_findings']);
    }

    // ── AC: normalize each finding kind into compression facts ───────────────

    public function test_unused_symbol_normalizes_to_non_blocking_deletion_eligible(): void
    {
        $r = $this->bridge()->normalize(['findings' => [
            ['finding_id' => 'f1', 'symbol' => 'DeadHelper', 'kind' => 'unused_symbol'],
        ]]);

        $finding = $r['normalized_findings'][0];
        $this->assertTrue($finding['deletion_eligible']);
        $this->assertFalse($finding['blocking']);
        $this->assertTrue($r['compression_eligible']);
    }

    public function test_unreachable_path_normalizes_to_non_blocking_deletion_eligible(): void
    {
        $r = $this->bridge()->normalize(['findings' => [
            ['finding_id' => 'f2', 'symbol' => 'DeadBranch', 'kind' => 'unreachable_path'],
        ]]);

        $finding = $r['normalized_findings'][0];
        $this->assertTrue($finding['deletion_eligible']);
        $this->assertFalse($finding['blocking']);
        $this->assertTrue($r['compression_eligible']);
    }

    // ── AC: type errors or dependency cycles block deletion eligibility ──────

    public function test_dependency_cycle_blocks_deletion_eligibility(): void
    {
        $r = $this->bridge()->normalize(['findings' => [
            ['finding_id' => 'f3', 'symbol' => 'CyclicA', 'kind' => 'dependency_cycle'],
        ]]);

        $finding = $r['normalized_findings'][0];
        $this->assertFalse($finding['deletion_eligible']);
        $this->assertTrue($finding['blocking']);
        $this->assertFalse($r['compression_eligible']);
        $this->assertContains('f3', $r['blocking_findings']);
    }

    public function test_type_error_blocks_deletion_eligibility(): void
    {
        $r = $this->bridge()->normalize(['findings' => [
            ['finding_id' => 'f4', 'symbol' => 'BadTypes', 'kind' => 'type_error'],
        ]]);

        $finding = $r['normalized_findings'][0];
        $this->assertFalse($finding['deletion_eligible']);
        $this->assertTrue($finding['blocking']);
        $this->assertFalse($r['compression_eligible']);
    }

    // ── unrecognized finding kinds fail closed ────────────────────────────────

    public function test_unrecognized_kind_blocks_by_default(): void
    {
        $r = $this->bridge()->normalize(['findings' => [
            ['finding_id' => 'f5', 'symbol' => 'Mystery', 'kind' => 'something_new'],
        ]]);

        $finding = $r['normalized_findings'][0];
        $this->assertFalse($finding['deletion_eligible']);
        $this->assertTrue($finding['blocking']);
        $this->assertFalse($r['compression_eligible']);
    }

    // ── one blocking finding blocks eligibility even with other safe findings ──

    public function test_one_blocking_finding_blocks_overall_eligibility(): void
    {
        $r = $this->bridge()->normalize(['findings' => [
            ['finding_id' => 'safe', 'symbol' => 'Unused', 'kind' => 'unused_symbol'],
            ['finding_id' => 'unsafe', 'symbol' => 'Cyclic', 'kind' => 'dependency_cycle'],
        ]]);

        $this->assertFalse($r['compression_eligible']);
        $this->assertSame(['unsafe'], $r['blocking_findings']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_normalize_is_deterministic(): void
    {
        $input = ['findings' => [
            ['finding_id' => 'a', 'kind' => 'unused_symbol'],
            ['finding_id' => 'b', 'kind' => 'type_error'],
        ]];

        $this->assertSame(
            json_encode($this->bridge()->normalize($input)),
            json_encode($this->bridge()->normalize($input)),
        );
    }

    public function test_malformed_findings_are_skipped(): void
    {
        $r = $this->bridge()->normalize(['findings' => [
            ['kind' => 'unused_symbol'], // missing finding_id
            ['finding_id' => 'valid', 'kind' => 'unused_symbol'],
        ]]);

        $this->assertCount(1, $r['normalized_findings']);
        $this->assertSame('valid', $r['normalized_findings'][0]['finding_id']);
    }
}
