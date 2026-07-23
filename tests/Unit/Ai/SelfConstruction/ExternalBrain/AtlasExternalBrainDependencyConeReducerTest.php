<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDependencyConeReducer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDependencyConeReducerTest extends TestCase
{
    private function reducer(): AtlasExternalBrainDependencyConeReducer
    {
        return new AtlasExternalBrainDependencyConeReducer;
    }

    private function safeCandidate(string $dependency, array $overrides = []): array
    {
        return array_merge([
            'dependency' => $dependency,
            'removable' => true,
            'shared_public_contract' => false,
            'consumers_known' => true,
            'preserved_contract_count' => 0,
        ], $overrides);
    }

    // ── AC: safe_dependency_reduction_case ─────────────────────────────────

    public function test_safe_dependency_reduction_case_is_ranked_not_held(): void
    {
        $r = $this->reducer()->rank(['candidates' => [$this->safeCandidate('LegacyHelper')]]);

        $this->assertContains('LegacyHelper', $r['ranked_candidates']);
        $this->assertSame([], $r['held']);
    }

    public function test_ranks_by_fewer_preserved_contracts_first(): void
    {
        $r = $this->reducer()->rank(['candidates' => [
            $this->safeCandidate('HighContractDep', ['preserved_contract_count' => 3]),
            $this->safeCandidate('LowContractDep', ['preserved_contract_count' => 0]),
        ]]);

        $this->assertSame(['LowContractDep', 'HighContractDep'], $r['ranked_candidates']);
    }

    // ── AC: unknown_consumer_hold_case ──────────────────────────────────────

    public function test_unknown_consumer_hold_case(): void
    {
        $r = $this->reducer()->rank(['candidates' => [
            $this->safeCandidate('MysteryDep', ['consumers_known' => false]),
        ]]);

        $this->assertNotContains('MysteryDep', $r['ranked_candidates']);
        $this->assertSame('MysteryDep', $r['held'][0]['dependency']);
        $this->assertContains('consumer_enumeration_proof', $r['held'][0]['required_proof']);
    }

    // ── AC: shared public contract must be held ─────────────────────────────

    public function test_shared_public_contract_hold_case(): void
    {
        $r = $this->reducer()->rank(['candidates' => [
            $this->safeCandidate('PublicApiDep', ['shared_public_contract' => true]),
        ]]);

        $this->assertNotContains('PublicApiDep', $r['ranked_candidates']);
        $this->assertContains('public_contract_migration_proof', $r['held'][0]['required_proof']);
    }

    public function test_not_yet_removable_dependency_is_held(): void
    {
        $r = $this->reducer()->rank(['candidates' => [
            $this->safeCandidate('UnprovenDep', ['removable' => false]),
        ]]);

        $this->assertContains('removability_proof', $r['held'][0]['required_proof']);
    }

    public function test_multiple_hold_reasons_are_all_named(): void
    {
        $r = $this->reducer()->rank(['candidates' => [
            $this->safeCandidate('BadDep', [
                'removable' => false,
                'shared_public_contract' => true,
                'consumers_known' => false,
            ]),
        ]]);

        $this->assertCount(3, $r['held'][0]['required_proof']);
    }

    // ── mixed batch ──────────────────────────────────────────────────────────

    public function test_mixed_batch_splits_ranked_and_held(): void
    {
        $r = $this->reducer()->rank(['candidates' => [
            $this->safeCandidate('Safe'),
            $this->safeCandidate('Unsafe', ['shared_public_contract' => true]),
        ]]);

        $this->assertContains('Safe', $r['ranked_candidates']);
        $this->assertSame('Unsafe', $r['held'][0]['dependency']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_rank_is_deterministic(): void
    {
        $facts = ['candidates' => [$this->safeCandidate('A'), $this->safeCandidate('B', ['shared_public_contract' => true])]];
        $a = $this->reducer()->rank($facts);
        $b = $this->reducer()->rank($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->reducer()->rank([]);
        $this->assertSame(AtlasExternalBrainDependencyConeReducer::SCHEMA, $r['schema']);
    }
}
