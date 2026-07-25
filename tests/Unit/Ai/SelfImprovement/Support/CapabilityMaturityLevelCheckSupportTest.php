<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfImprovement\Support;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementCapabilityMaturityScoreService as Mother;
use App\Services\Ai\SelfImprovement\Support\CapabilityMaturityLevelCheckSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure Support peel for capability maturity level checkers — no I/O, no mother instance, no DB.
 */
final class CapabilityMaturityLevelCheckSupportTest extends TestCase
{
    #[Test]
    public function string_or_null_and_int_in_range(): void
    {
        $this->assertNull(Support::stringOrNull(null));
        $this->assertNull(Support::stringOrNull(12));
        $this->assertNull(Support::stringOrNull('  '));
        $this->assertSame('doc.md', Support::stringOrNull('  doc.md  '));

        $this->assertNull(Support::intInRange(null, 0, 10));
        $this->assertNull(Support::intInRange('x', 0, 10));
        $this->assertNull(Support::intInRange(-1, 0, 10));
        $this->assertNull(Support::intInRange(11, 0, 10));
        $this->assertSame(4, Support::intInRange(4, 0, 10));
        $this->assertSame(0, Support::intInRange('0', 0, 10));
        $this->assertSame(-1, Support::intInRange(-1, -1, 10));
    }

    #[Test]
    public function level0_to_level6_reason_codes_and_existence_flags(): void
    {
        $this->assertSame('no_doc_path_provided', Support::level0(null, false)['reason']);
        $this->assertSame('doc_file_missing', Support::level0('a.md', false)['reason']);
        $this->assertTrue(Support::level0('a.md', true)['passed']);

        $this->assertSame('no_service_class_provided', Support::level1(null, false)['reason']);
        $this->assertSame('service_class_missing', Support::level1('App\\X', false)['reason']);
        $this->assertTrue(Support::level1('App\\X', true)['passed']);

        $this->assertSame('no_command_signature_provided', Support::level2(null, false)['reason']);
        $this->assertSame('command_class_missing', Support::level2('App\\Cmd', false)['reason']);
        $this->assertTrue(Support::level2('App\\Cmd', true)['passed']);
        $this->assertSame('invalid_command_signature', Support::level2('artisan:foo', false)['reason']);
        $this->assertTrue(Support::level2('atlas:self-improvement:proposal-gate', false)['passed']);

        $this->assertSame('no_api_route_provided', Support::level3(null, false, '')['reason']);
        $this->assertSame('routes_file_missing', Support::level3('/x', false, '')['reason']);
        $this->assertSame('api_route_not_found_in_routes_file', Support::level3('/x', true, 'other')['reason']);
        $this->assertTrue(Support::level3('/self-improvement/proposal-gate', true, "Route::get('/self-improvement/proposal-gate')")['passed']);

        $this->assertSame('no_test_class_provided', Support::level4(null, false)['reason']);
        $this->assertSame('test_file_missing', Support::level4('tests/Foo.php', false)['reason']);
        $this->assertTrue(Support::level4('tests/Foo.php', true)['passed']);
        $this->assertSame('test_class_missing', Support::level4('Tests\\Foo', false)['reason']);
        $this->assertTrue(Support::level4('Tests\\Foo', true)['passed']);
        $this->assertSame('invalid_test_class_descriptor', Support::level4('Unit/Foo', false)['reason']);

        $this->assertSame('no_ui_path_provided', Support::level5(null, false)['reason']);
        $this->assertSame('ui_file_missing', Support::level5('Panel.tsx', false)['reason']);
        $this->assertTrue(Support::level5('Panel.tsx', true)['passed']);

        $this->assertSame('no_state_field_provided', Support::level6(null, false, '')['reason']);
        $this->assertSame('state_controller_missing', Support::level6('self_improvement_governance', false, '')['reason']);
        $this->assertSame('state_field_not_in_controller', Support::level6('missing_field', true, 'other')['reason']);
        $this->assertTrue(Support::level6('self_improvement_governance', true, "return ['self_improvement_governance' => []]")['passed']);
    }

    #[Test]
    public function level7_to_level10_pure_descriptor_checks(): void
    {
        $this->assertSame('no_audit_block_provided', Support::level7(null, [])['reason']);
        $this->assertSame('audit_block_missing', Support::level7('block_a', [])['reason']);
        $this->assertSame('audit_block_invalid', Support::level7('block_a', ['block_a' => 'raw'])['reason']);
        $this->assertSame('audit_block_invalid', Support::level7('block_a', ['block_a' => ['ok' => true]])['reason']);
        $this->assertTrue(Support::level7('block_a', ['block_a' => ['schema_version' => 'v1']])['passed']);

        $this->assertSame('no_evidence_paths_provided', Support::level8([])['reason']);
        $this->assertSame('no_evidence_paths_provided', Support::level8(['evidence_paths' => []])['reason']);
        $this->assertTrue(Support::level8(['evidence_paths' => ['docs/evidence/x.md']])['passed']);

        $this->assertSame('no_rivals_or_delta_evidence_declared', Support::level9([])['reason']);
        $this->assertTrue(Support::level9(['rivals_or_delta_evidence' => true])['passed']);

        $this->assertSame('production_ready_not_declared', Support::level10([])['reason']);
        $this->assertTrue(Support::level10(['production_ready' => true])['passed']);
    }

    #[Test]
    public function achieved_level_is_contiguous_and_next_action_climbs(): void
    {
        $fail = ['passed' => false, 'reason' => 'x'];
        $pass = ['passed' => true, 'reason' => null];

        $this->assertSame(-1, Support::achievedLevel([0 => $fail]));
        $this->assertSame(0, Support::achievedLevel([0 => $pass, 1 => $fail]));
        $this->assertSame(3, Support::achievedLevel([
            0 => $pass, 1 => $pass, 2 => $pass, 3 => $pass, 4 => $fail,
        ]));

        // Non-contiguous later pass does not skip the gap.
        $checks = [];
        foreach (range(0, Mother::MAX_LEVEL) as $i) {
            $checks[$i] = $i === 5 ? $pass : ($i <= 2 ? $pass : $fail);
        }
        $this->assertSame(2, Support::achievedLevel($checks));

        $full = [];
        foreach (range(0, Mother::MAX_LEVEL) as $i) {
            $full[$i] = $pass;
        }
        $this->assertSame(Mother::MAX_LEVEL, Support::achievedLevel($full));
        $this->assertSame('capability_production_ready', Support::nextAction(Mother::MAX_LEVEL));
        $this->assertSame('climb_to_level_5:ui_exists', Support::nextAction(4));
        $this->assertSame('climb_to_level_0:doc_only', Support::nextAction(-1));
    }

    #[Test]
    public function levels_rows_cover_all_canonical_labels(): void
    {
        $checks = [];
        foreach (range(0, Mother::MAX_LEVEL) as $i) {
            $checks[$i] = [
                'passed' => $i <= 1,
                'reason' => $i <= 1 ? null : 'pending',
            ];
        }
        $rows = Support::levelsRows($checks);
        $this->assertCount(Mother::MAX_LEVEL + 1, $rows);
        $this->assertSame(Mother::LEVELS[0], $rows[0]['label']);
        $this->assertSame(Mother::LEVELS[10], $rows[10]['label']);
        $this->assertTrue($rows[1]['passed']);
        $this->assertFalse($rows[2]['passed']);
        $this->assertSame('pending', $rows[2]['reason']);
    }
}
