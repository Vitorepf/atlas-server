<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationDebtLedger;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationDebtLedgerTest extends TestCase
{
    public function test_each_entry_carries_required_fields(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                [
                    'category' => 'duplicate_circuit',
                    'affected_targets' => ['app/Services/Ai/SelfConstruction/A.php', 'app/Services/Ai/SelfConstruction/B.php'],
                    'evidence_refs' => ['docs/x.md'],
                ],
            ],
        ]);

        $entry = $result['entries'][0];
        $this->assertSame('duplicate_circuit', $entry['category']);
        $this->assertSame(['app/Services/Ai/SelfConstruction/A.php', 'app/Services/Ai/SelfConstruction/B.php'], $entry['affected_targets']);
        $this->assertSame(['docs/x.md'], $entry['evidence_refs']);
        $this->assertArrayHasKey('impact', $entry);
        $this->assertArrayHasKey('risk', $entry);
        $this->assertArrayHasKey('next_action', $entry);
        $this->assertArrayHasKey('accountable_gate', $entry);
    }

    public function test_duplicate_circuit_stale_scaffold_cosmetic_and_high_risk_entries(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'cosmetic', 'affected_targets' => ['x.php'], 'evidence_refs' => []],
                ['category' => 'stale_scaffold', 'affected_targets' => ['y.php'], 'evidence_refs' => []],
                ['category' => 'duplicate_circuit', 'affected_targets' => ['z.php'], 'evidence_refs' => []],
                ['category' => 'broken_contract', 'affected_targets' => ['w.php'], 'evidence_refs' => []],
            ],
        ]);

        $categories = array_column($result['entries'], 'category');
        $this->assertSame(['broken_contract', 'duplicate_circuit', 'stale_scaffold', 'cosmetic'], $categories);

        $brokenContract = $result['entries'][0];
        $this->assertSame('high', $brokenContract['risk']);
        $this->assertSame('repair_contract_before_next_wave', $brokenContract['next_action']);
    }

    public function test_cosmetic_only_ranked_below_debt_reducing_duplicated_decision_paths(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'cosmetic', 'affected_targets' => [], 'evidence_refs' => []],
                ['category' => 'duplicate_circuit', 'affected_targets' => [], 'evidence_refs' => []],
            ],
        ]);

        $this->assertSame('duplicate_circuit', $result['entries'][0]['category']);
        $this->assertSame('cosmetic', $result['entries'][1]['category']);
        $this->assertSame('cosmetic_only', $result['entries'][1]['impact']);
        $this->assertSame('none', $result['entries'][1]['risk']);
    }

    public function test_unknown_category_is_ignored(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([
            'entries' => [
                ['category' => 'not_a_real_category', 'affected_targets' => [], 'evidence_refs' => []],
            ],
        ]);

        $this->assertSame([], $result['entries']);
    }

    public function test_no_entries_returns_empty_ledger(): void
    {
        $result = (new AtlasSelfConstructionSimplificationDebtLedger)->record([]);

        $this->assertSame([], $result['entries']);
        $this->assertSame('atlas.self_construction.simplification.debt_ledger.v1', $result['schema']);
    }
}
