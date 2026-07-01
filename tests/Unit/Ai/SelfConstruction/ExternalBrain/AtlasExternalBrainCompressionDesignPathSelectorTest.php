<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionDesignPathSelector;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionDesignPathSelectorTest extends TestCase
{
    private function selector(): AtlasExternalBrainCompressionDesignPathSelector
    {
        return new AtlasExternalBrainCompressionDesignPathSelector;
    }

    public function test_design_path_selection_case_merge_with_proof(): void
    {
        $r = $this->selector()->select([
            'duplication_score' => 0.8,
            'required_proof' => ['behavior_lock'],
            'proof_available' => ['behavior_lock'],
        ]);

        $this->assertSame('merge', $r['design_path']);
        $this->assertSame([], $r['missing_proof']);
    }

    public function test_dead_code_selects_delete_with_top_priority(): void
    {
        $r = $this->selector()->select([
            'dead_code' => true,
            'duplication_score' => 0.9,
            'required_proof' => ['owner_confirmed'],
            'proof_available' => ['owner_confirmed'],
        ]);

        $this->assertSame('delete', $r['design_path']);
    }

    public function test_indirection_selects_collapse_indirection(): void
    {
        $r = $this->selector()->select([
            'indirection_score' => 0.7,
            'required_proof' => [],
        ]);

        $this->assertSame('collapse_indirection', $r['design_path']);
    }

    public function test_undocumented_public_surface_selects_extract_contract(): void
    {
        $r = $this->selector()->select([
            'undocumented_public_surface' => true,
        ]);

        $this->assertSame('extract_contract', $r['design_path']);
    }

    public function test_proof_debt_selects_add_proof_without_requiring_prior_proof(): void
    {
        $r = $this->selector()->select([
            'proof_debt_score' => 0.9,
        ]);

        $this->assertSame('add_proof', $r['design_path']);
        $this->assertSame([], $r['missing_proof']);
    }

    public function test_missing_proof_no_safe_path_case(): void
    {
        $r = $this->selector()->select([
            'duplication_score' => 0.9,
            'required_proof' => ['behavior_lock', 'parity_proof'],
            'proof_available' => ['behavior_lock'],
        ]);

        $this->assertSame('no_safe_path', $r['design_path']);
        $this->assertContains('parity_proof', $r['missing_proof']);
    }

    public function test_no_symptoms_returns_no_safe_path(): void
    {
        $r = $this->selector()->select([]);

        $this->assertSame('no_safe_path', $r['design_path']);
    }

    public function test_dead_code_still_blocks_on_missing_proof(): void
    {
        $r = $this->selector()->select([
            'dead_code' => true,
            'required_proof' => ['zero_consumers_proof'],
            'proof_available' => [],
        ]);

        $this->assertSame('no_safe_path', $r['design_path']);
        $this->assertContains('zero_consumers_proof', $r['missing_proof']);
    }

    public function test_schema_present(): void
    {
        $r = $this->selector()->select([]);

        $this->assertSame(AtlasExternalBrainCompressionDesignPathSelector::SCHEMA, $r['schema']);
    }
}
