<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneVerificationCourt;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the project-lane verification court is live at the operator surface: an input with an unmet
 * precondition is blocked with a non-empty blockers list; a complete, hash-backed, policy-passing input passes;
 * a missing required rerun holds.
 */
final class AtlasLoopLaneVerdictCommandTest extends TestCase
{
    private function adjudicate(array $input): array
    {
        $exit = Artisan::call('atlas:loop:lane-verdict', [
            '--input' => (string) json_encode($input),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_unmet_precondition_is_blocked(): void
    {
        // no verification_policy ⇒ policy:missing blocker
        ['exit' => $exit, 'd' => $d] = $this->adjudicate(['project_id' => 'p1']);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasProjectLaneVerificationCourt::SCHEMA, $d['schema_version']);
        $this->assertFalse($d['passed'], (string) json_encode($d));
        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_BLOCKED, $d['verdict']);
        $this->assertNotEmpty($d['blockers']);
    }

    public function test_complete_input_passes(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->adjudicate([
            'project_id' => 'p1',
            'verification_policy' => ['allowed' => true],
            'evidence_records' => [
                ['project_id' => 'p1', 'gate' => 'tests', 'evidence_hash' => 'h1', 'passed' => true],
            ],
            'required_rerun_evidence' => ['tests'],
            'leak_facts' => [],
        ]);

        $this->assertSame(0, $exit);
        $this->assertTrue($d['passed'], (string) json_encode($d));
        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_PASS, $d['verdict']);
        $this->assertSame([], $d['blockers']);
        $this->assertContains('h1', $d['evidence_hashes']);
    }

    public function test_missing_required_rerun_holds(): void
    {
        ['d' => $d] = $this->adjudicate([
            'project_id' => 'p1',
            'verification_policy' => ['allowed' => true],
            'evidence_records' => [['project_id' => 'p1', 'gate' => 'tests', 'evidence_hash' => 'h1']],
            'required_rerun_evidence' => ['mutation'], // not provided
        ]);

        $this->assertSame(AtlasProjectLaneVerificationCourt::VERDICT_HOLD, $d['verdict'], (string) json_encode($d));
        $this->assertFalse($d['passed']);
    }

    public function test_empty_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:lane-verdict', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
