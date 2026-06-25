<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Tests\TestCase;

final class Loop8PhaseCycleCanonicalDocTest extends TestCase
{
    private const PHASES = [
        'orient',
        'comprehend',
        'decide-leverage',
        'architect',
        'decompose',
        'implement',
        'certify',
        'close-on-main',
    ];

    private function doc(): string
    {
        return (string) file_get_contents(base_path('docs/loop-8-phase-cycle-canonical.md'));
    }

    public function test_all_eight_phase_names_appear_in_canonical_order(): void
    {
        $doc = $this->doc();
        $this->assertNotSame('', $doc, 'doc must exist and be non-empty');

        $cursor = 0;
        foreach (self::PHASES as $phase) {
            $pos = strpos($doc, $phase, $cursor);
            $this->assertNotFalse($pos, "phase '{$phase}' must appear after the previous one");
            $cursor = $pos + strlen($phase);
        }
    }

    public function test_at_least_four_atlas_loop_symbol_anchors_are_present(): void
    {
        $doc = $this->doc();

        preg_match_all('/Atlas[A-Za-z][A-Za-z0-9_]+/', $doc, $matches);
        $anchors = array_values(array_unique($matches[0]));

        $this->assertGreaterThanOrEqual(
            4,
            count($anchors),
            'expected at least 4 distinct Atlas* symbol anchors, found '.count($anchors).': '.implode(', ', $anchors),
        );
    }

    public function test_doc_forbids_one_shot_faxina_proxy_work(): void
    {
        $doc = strtolower($this->doc());

        foreach (['one-shot', 'faxina', 'proxy'] as $needle) {
            $this->assertStringContainsString($needle, $doc, "doc must explicitly mention '{$needle}'");
        }
    }
}
