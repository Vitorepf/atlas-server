<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Discovery\DevContextBudgetDistiller;
use PHPUnit\Framework\TestCase;

final class DevContextBudgetDistillerTest extends TestCase
{
    private function distiller(): DevContextBudgetDistiller
    {
        return new DevContextBudgetDistiller;
    }

    public function test_schema_present(): void
    {
        $result = $this->distiller()->distill(['owner_docs' => 'x'], 100);

        $this->assertSame(DevContextBudgetDistiller::SCHEMA, $result['schema']);
    }

    // ── (b) sections already inside the budget pass through untouched ────────

    public function test_sections_within_budget_pass_through_untouched(): void
    {
        $sections = [
            'owner_docs' => 'short doc',
            'callers' => 'short caller',
        ];

        $result = $this->distiller()->distill($sections, 1000);

        $this->assertSame($sections, $result['sections']);
        $this->assertSame([], $result['dropped_report']);
        $this->assertSame(['owner_docs', 'callers'], $result['included_labels']);
    }

    public function test_empty_sections_within_budget_yields_empty_output(): void
    {
        $result = $this->distiller()->distill([], 100);

        $this->assertSame([], $result['sections']);
        $this->assertSame([], $result['dropped_report']);
        $this->assertSame(0, $result['total_chars']);
    }

    // ── (a) caller/test/decision evidence survives cuts over generic prose ───

    public function test_callers_tests_decisions_survive_over_generic_owner_docs_when_budget_forces_cuts(): void
    {
        $sections = [
            'owner_docs' => str_repeat('d', 50),
            'callers' => str_repeat('c', 20),
            'tests' => str_repeat('t', 20),
            'decisions' => str_repeat('e', 20),
        ];
        // Budget only fits callers + tests + decisions (60 chars), owner_docs must be cut.
        $result = $this->distiller()->distill($sections, 60);

        $this->assertArrayHasKey('callers', $result['sections']);
        $this->assertArrayHasKey('tests', $result['sections']);
        $this->assertArrayHasKey('decisions', $result['sections']);
        $this->assertArrayNotHasKey('owner_docs', $result['sections']);
        $this->assertContains('owner_docs', array_column($result['dropped_report'], 'label'));
    }

    public function test_symbols_and_risks_outranked_by_callers_tests_decisions(): void
    {
        $sections = [
            'callers' => str_repeat('c', 10),
            'tests' => str_repeat('t', 10),
            'decisions' => str_repeat('e', 10),
            'symbols' => str_repeat('s', 10),
            'risks' => str_repeat('r', 10),
        ];
        $result = $this->distiller()->distill($sections, 30);

        $this->assertSame(['callers', 'decisions', 'tests'], $this->sortedKeys($result['sections']));
    }

    private function sortedKeys(array $arr): array
    {
        $keys = array_keys($arr);
        sort($keys, SORT_STRING);

        return $keys;
    }

    public function test_criticality_override_can_promote_a_label_above_defaults(): void
    {
        $sections = [
            'owner_docs' => str_repeat('d', 20),
            'callers' => str_repeat('c', 20),
        ];
        // Force owner_docs above callers via explicit criticality override.
        $result = $this->distiller()->distill($sections, 20, ['owner_docs' => 999, 'callers' => 1]);

        $this->assertArrayHasKey('owner_docs', $result['sections']);
        $this->assertArrayNotHasKey('callers', $result['sections']);
    }

    // ── (c) dropped_report names every cut section with a reason ─────────────

    public function test_dropped_report_names_every_cut_section_with_a_reason(): void
    {
        $sections = [
            'callers' => str_repeat('c', 50),
            'owner_docs' => str_repeat('d', 50),
            'symbols' => str_repeat('s', 50),
        ];
        $result = $this->distiller()->distill($sections, 50);

        $labels = array_column($result['dropped_report'], 'label');
        $this->assertContains('owner_docs', $labels);
        $this->assertContains('symbols', $labels);
        foreach ($result['dropped_report'] as $entry) {
            $this->assertArrayHasKey('reason', $entry);
            $this->assertArrayHasKey('chars', $entry);
            $this->assertContains($entry['reason'], [DevContextBudgetDistiller::REASON_OVER_BUDGET, DevContextBudgetDistiller::REASON_TRUNCATED]);
        }
    }

    public function test_partially_fitting_section_is_truncated_not_dropped_entirely(): void
    {
        $sections = [
            'callers' => str_repeat('c', 30),
        ];
        $result = $this->distiller()->distill($sections, 10);

        $this->assertSame(str_repeat('c', 10), $result['sections']['callers']);
        $this->assertSame([['label' => 'callers', 'reason' => DevContextBudgetDistiller::REASON_TRUNCATED, 'chars' => 20]], $result['dropped_report']);
    }

    public function test_truncation_cuts_on_line_boundary_never_mid_entry(): void
    {
        // Each line is one logical fact; the cut must land on a newline so the
        // prompt never carries half a fact.
        $sections = [
            'callers' => "fact-one\nfact-two\nfact-three\n",
        ];
        // Budget lands mid "fact-two" (chars 0..12 = "fact-one\nfact").
        $result = $this->distiller()->distill($sections, 13);

        $this->assertSame('fact-one', $result['sections']['callers'], 'the cut backs off to the last full line');
        $this->assertSame(DevContextBudgetDistiller::REASON_TRUNCATED, $result['dropped_report'][0]['reason']);
    }

    public function test_zero_budget_drops_everything(): void
    {
        $sections = ['callers' => 'abc'];
        $result = $this->distiller()->distill($sections, 0);

        $this->assertSame([], $result['sections']);
        $this->assertSame(DevContextBudgetDistiller::REASON_OVER_BUDGET, $result['dropped_report'][0]['reason']);
    }

    // ── (d) determinism ───────────────────────────────────────────────────────

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $distiller = $this->distiller();
        $sections = [
            'owner_docs' => str_repeat('d', 50),
            'callers' => str_repeat('c', 20),
            'tests' => str_repeat('t', 20),
        ];

        $this->assertSame($distiller->distill($sections, 40), $distiller->distill($sections, 40));
    }

    public function test_result_is_deterministic_regardless_of_input_array_key_order(): void
    {
        $distiller = $this->distiller();
        $a = ['owner_docs' => 'x', 'callers' => 'y', 'tests' => 'z'];
        $b = ['tests' => 'z', 'callers' => 'y', 'owner_docs' => 'x'];

        $this->assertSame($distiller->distill($a, 2), $distiller->distill($b, 2));
    }
}
