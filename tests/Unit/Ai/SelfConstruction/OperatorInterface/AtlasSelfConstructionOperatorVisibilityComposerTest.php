<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\OperatorInterface;

use App\Services\Ai\SelfConstruction\OperatorInterface\AtlasSelfConstructionOperatorVisibilityComposer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionOperatorVisibilityComposer: groups every supplied section into a status
 * block with a categorical status string; preserves the machine payload verbatim; envelope declares
 * autonomy_owner='atlas_native' and interface_role='read_only_with_emergency_stop'; absence of any
 * runtime-mutation side effect is observable by the function being pure (no constructor I/O).
 */
final class AtlasSelfConstructionOperatorVisibilityComposerTest extends TestCase
{
    public function test_summary_composition_yields_a_block_per_known_section(): void
    {
        $facts = [
            'autonomy_mode' => ['mode' => 'execute_continuous'],
            'control_plane' => ['ready' => true],
            'strategy_council' => ['ambition_level' => 'standard'],
            'safety_stop' => ['action' => 'continue'],
            'verification_court' => ['verdict' => 'passed'],
            'merge_governor' => ['decision' => 'admitted'],
            'knowledge_sync' => ['conformant' => true],
        ];
        $r = (new AtlasSelfConstructionOperatorVisibilityComposer)->compose($facts, '2026-06-25T00:00:00Z');

        $this->assertCount(count(AtlasSelfConstructionOperatorVisibilityComposer::SECTIONS), $r['blocks']);
        $byLabel = [];
        foreach ($r['blocks'] as $b) {
            $byLabel[$b['label']] = $b;
        }
        $this->assertSame('execute_continuous', $byLabel['autonomy_mode']['status']);
        $this->assertSame('ready', $byLabel['control_plane']['status']);
        $this->assertSame('standard', $byLabel['strategy_council']['status']);
        $this->assertSame('continue', $byLabel['safety_stop']['status']);
        $this->assertSame('passed', $byLabel['verification_court']['status']);
        $this->assertSame('admitted', $byLabel['merge_governor']['status']);
        $this->assertSame('conformant', $byLabel['knowledge_sync']['status']);
    }

    public function test_missing_section_yields_status_unknown(): void
    {
        $r = (new AtlasSelfConstructionOperatorVisibilityComposer)->compose([]);
        foreach ($r['blocks'] as $b) {
            $this->assertSame('unknown', $b['status']);
        }
    }

    public function test_envelope_declares_autonomy_owner_and_read_only_role(): void
    {
        $r = (new AtlasSelfConstructionOperatorVisibilityComposer)->compose([]);
        $this->assertSame(AtlasSelfConstructionOperatorVisibilityComposer::AUTONOMY_OWNER, $r['autonomy_owner']);
        $this->assertSame(AtlasSelfConstructionOperatorVisibilityComposer::ROLE, $r['interface_role']);
    }

    public function test_machine_payload_is_preserved_verbatim_per_block(): void
    {
        $row = ['mode' => 'execute_guarded', 'arbitrary' => ['nested' => 1]];
        $r = (new AtlasSelfConstructionOperatorVisibilityComposer)->compose(['autonomy_mode' => $row]);
        $byLabel = array_column($r['blocks'], null, 'label');
        $this->assertSame($row, $byLabel['autonomy_mode']['machine']);
    }

    public function test_two_compositions_with_same_input_are_byte_identical(): void
    {
        $facts = ['autonomy_mode' => ['mode' => 'observe']];
        $c = new AtlasSelfConstructionOperatorVisibilityComposer;
        $a = json_encode($c->compose($facts, '2026-06-25T00:00:00Z'));
        $b = json_encode($c->compose($facts, '2026-06-25T00:00:00Z'));
        $this->assertSame($a, $b);
    }
}
