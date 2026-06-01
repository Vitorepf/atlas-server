<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasWorkSplitterContractService;
use Tests\TestCase;

/**
 * Pins the executable decision rules from the doc: disjoint write sets, hot
 * scope withholding, collision_risk=high blocking assignment (migrations etc.),
 * the max-5 cap with "prefer fewer safe packets", Assignment Policy ordering,
 * the missing-structural-contract failure mode, and the unknown-generated-file
 * human-review failure mode. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
 */
class AtlasWorkSplitterContractTest extends TestCase
{
    private function service(): AtlasWorkSplitterContractService
    {
        return new AtlasWorkSplitterContractService;
    }

    /** A clean, dependency-free, low-risk docs item with a validator. */
    private function cleanItem(string $id, string $lane, string $file): array
    {
        return [
            'id' => $id,
            'lane' => $lane,
            'objective' => 'obj '.$id,
            'write_files' => [$file],
            'scope_validator_command' => 'php artisan atlas:aaeos:scope-validator-contract --json',
        ];
    }

    public function test_two_items_writing_the_same_file_cannot_both_be_emitted(): void
    {
        // Decision: "Parallel AI work is allowed only through disjoint write sets."
        // The later overlapping item is withheld as write_set_overlap, never a 2nd packet.
        $out = $this->service()->split([
            'split_id' => 'SPLIT-20260601-0001',
            'backlog' => [
                $this->cleanItem('A', 'docs', 'docs/shared.md'),
                $this->cleanItem('B', 'docs', 'docs/shared.md'),
            ],
        ]);

        $this->assertSame(1, $out['packet_count']);
        $this->assertSame(1, $out['withheld_count']);
        $this->assertSame('write_set_overlap', $out['withheld_work'][0]['reason_code']);
        $this->assertSame(['docs/shared.md'], $out['withheld_work'][0]['forbidden_scope']);
    }

    public function test_hot_scope_item_is_withheld_and_hot_files_are_forbidden_on_every_packet(): void
    {
        // Decision: "Hot external files block assignment, not validation."
        $out = $this->service()->split([
            'split_id' => 'SPLIT-20260601-0002',
            'hot_scopes' => ['runtimes/python/voice_realtime/'],
            'backlog' => [
                $this->cleanItem('SAFE', 'docs', 'docs/ok.md'),
                $this->cleanItem('HOT', 'runtime_scoped', 'runtimes/python/voice_realtime/server.py'),
            ],
        ]);

        // Hot item withheld...
        $reasons = array_column($out['withheld_work'], 'reason_code');
        $this->assertContains('hot_scope', $reasons);
        // ...and the emitted safe packet still lists the hot scope as forbidden.
        $this->assertSame(1, $out['packet_count']);
        $this->assertSame(['runtimes/python/voice_realtime/'], $out['packets'][0]['forbidden_files']);
    }

    public function test_migration_write_forces_high_collision_and_blocks_assignment(): void
    {
        // Collision Detection: migrations involved => high; high blocks assignment.
        $item = $this->cleanItem('MIG', 'runtime_scoped', 'database/migrations/2026_01_01_000000_x.php');
        $assessment = $this->service()->assessCollision($item, [], []);
        $this->assertSame(AtlasWorkSplitterContractService::RISK_HIGH, $assessment['risk']);
        $this->assertContains('migration', $assessment['reasons']);

        $out = $this->service()->split([
            'split_id' => 'SPLIT-20260601-0003',
            'backlog' => [$item],
        ]);
        $this->assertSame(0, $out['packet_count']);
        $this->assertSame('no_safe_packets', $out['status']);
        $this->assertSame('collision_high', $out['withheld_work'][0]['reason_code']);
    }

    public function test_missing_scope_validator_command_makes_collision_high(): void
    {
        // Collision Detection: "packet lacks a scope validator command" => high.
        $item = [
            'id' => 'NOVAL',
            'lane' => 'docs',
            'objective' => 'no validator',
            'write_files' => ['docs/noval.md'],
            // no scope_validator_command
        ];
        $assessment = $this->service()->assessCollision($item, [], []);
        $this->assertSame(AtlasWorkSplitterContractService::RISK_HIGH, $assessment['risk']);
        $this->assertContains('missing_scope_validator_command', $assessment['reasons']);
    }

    public function test_caps_at_max_five_and_prefers_fewer_safe_packets(): void
    {
        // Failure Mode: "Too many packets: emit fewer, safer packets." Hard cap 5.
        $backlog = [];
        for ($i = 1; $i <= 8; $i++) {
            $backlog[] = $this->cleanItem('I'.$i, 'docs', 'docs/file'.$i.'.md');
        }
        $out = $this->service()->split([
            'split_id' => 'SPLIT-20260601-0004',
            'max_packets' => 99, // requested above ceiling
            'backlog' => $backlog,
        ]);

        $this->assertSame(5, $out['max_packets']);   // ceiling enforced
        $this->assertSame(5, $out['packet_count']);  // never more than 5
        $this->assertSame(3, $out['withheld_count']); // the extra 3 withheld
        $this->assertSame('over_capacity', $out['withheld_work'][0]['reason_code']);
    }

    public function test_assignment_policy_orders_docs_before_tests_before_scoped(): void
    {
        // Assignment Policy: docs/contracts (1) -> read-only surfaces (3) -> tests (4) -> scoped (5).
        $out = $this->service()->split([
            'split_id' => 'SPLIT-20260601-0005',
            'backlog' => [
                $this->cleanItem('Z-TEST', 'tests', 'tests/Unit/Foo.php'),
                $this->cleanItem('Y-DOC', 'docs', 'docs/foo.md'),
                $this->cleanItem('X-CMD', 'command_surface', 'app/Console/Commands/Foo.php'),
            ],
        ]);

        $lanes = array_column($out['packets'], 'lane');
        $this->assertSame(['docs', 'command_surface', 'tests'], $lanes);
    }

    public function test_runtime_item_without_structural_contract_is_withheld(): void
    {
        // Failure Mode: "Missing structural contract: emit documentation packet first."
        $out = $this->service()->split([
            'split_id' => 'SPLIT-20260601-0006',
            'backlog' => [[
                'id' => 'NEEDS-CONTRACT',
                'lane' => 'runtime_read_only',
                'objective' => 'runtime needing a contract',
                'write_files' => ['app/Services/Foo.php'],
                'scope_validator_command' => 'php artisan atlas:aaeos:scope-validator-contract --json',
                'requires_structural_contract' => true,
                'has_structural_contract' => false,
            ]],
        ]);

        $this->assertSame(0, $out['packet_count']);
        $this->assertSame('missing_structural_contract', $out['withheld_work'][0]['reason_code']);
        $this->assertContains('missing_structural_contract:NEEDS-CONTRACT', $out['blocking_reasons']);
    }

    public function test_unknown_generated_file_outside_write_set_requires_human_review(): void
    {
        // Failure Mode: "Unknown generated file: mark unknown and require human review."
        $out = $this->service()->split([
            'split_id' => 'SPLIT-20260601-0007',
            'backlog' => [[
                'id' => 'GEN',
                'lane' => 'docs',
                'objective' => 'doc with stray generated output',
                'write_files' => ['docs/source.md'],
                'generated_files' => ['docs/code-intel/generated-index.json'],
                'scope_validator_command' => 'php artisan atlas:aaeos:scope-validator-contract --json',
            ]],
        ]);

        $this->assertCount(1, $out['human_review_required']);
        $this->assertSame('GEN', $out['human_review_required'][0]['item']);
        $this->assertSame(
            ['docs/code-intel/generated-index.json'],
            $out['human_review_required'][0]['unknown_generated'],
        );
    }

    public function test_split_is_deterministic_and_execution_is_never_allowed(): void
    {
        $input = [
            'split_id' => 'SPLIT-20260601-0008',
            'backlog' => [$this->cleanItem('A', 'docs', 'docs/a.md')],
        ];
        $a = $this->service()->split($input);
        $b = $this->service()->split($input);

        $this->assertSame($a['split_hash'], $b['split_hash']);
        $this->assertFalse($a['execution_allowed']);
        // Emitted packet ids derive the YYYYMMDD stamp from the split id.
        $this->assertSame('AIP-20260601-0001', $a['packets'][0]['packet_id']);
    }
}
