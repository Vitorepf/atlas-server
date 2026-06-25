<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskWorkerInstructionLint;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasTaskWorkerInstructionLint: clean instructions ⇒ accepted=true; forbidden git wording ⇒
 * run_git_manually finding; outside-scope edit wording ⇒ edit_outside_allowed_files; bootstrap/visibility
 * wording is ALLOWED (bootstrap exception); ask-human-for-normal-progress ⇒ flagged unless bootstrap;
 * duplicate canonical candidates ⇒ implement_duplicate_canonical_symbol per name; ignore_give_back
 * wording ⇒ ignore_give_back_when_capability_exists.
 */
final class AtlasTaskWorkerInstructionLintTest extends TestCase
{
    public function test_clean_instructions_yield_accepted_true(): void
    {
        $r = (new AtlasTaskWorkerInstructionLint)->lint([
            'packet_id' => 'p',
            'objective' => 'add a small helper',
            'worker_instructions' => 'implement the helper inside the allowed_files; run phpunit.',
            'allowed_files' => ['app/Foo.php'],
        ]);
        $this->assertTrue($r['accepted']);
        $this->assertSame([], $r['findings']);
    }

    public function test_forbidden_git_wording_is_flagged(): void
    {
        $r = (new AtlasTaskWorkerInstructionLint)->lint([
            'packet_id' => 'p',
            'worker_instructions' => 'After implementation, git push to main and merge.',
        ]);
        $this->assertContains('run_git_manually', $r['findings']);
    }

    public function test_outside_scope_edit_wording_is_flagged(): void
    {
        $r = (new AtlasTaskWorkerInstructionLint)->lint([
            'packet_id' => 'p',
            'worker_instructions' => 'Feel free to edit files outside allowed_files if needed.',
        ]);
        $this->assertNotEmpty(array_filter($r['findings'], static fn (string $f): bool => str_starts_with($f, 'edit_outside_allowed_files')));
    }

    public function test_ask_human_wording_is_flagged_normally(): void
    {
        $r = (new AtlasTaskWorkerInstructionLint)->lint([
            'packet_id' => 'p',
            'worker_instructions' => 'When unsure, ask the operator to confirm.',
        ]);
        $this->assertContains('ask_human_for_normal_progress', $r['findings']);
    }

    public function test_bootstrap_visibility_wording_is_allowed(): void
    {
        $r = (new AtlasTaskWorkerInstructionLint)->lint([
            'packet_id' => 'p',
            'worker_instructions' => 'bootstrap-only: operator dashboard shows status; emergency stop available; ask the operator for one-time bootstrap approval.',
        ]);
        $this->assertSame([], $r['findings']);
    }

    public function test_duplicate_canonical_candidates_emit_findings_per_name(): void
    {
        $r = (new AtlasTaskWorkerInstructionLint)->lint([
            'packet_id' => 'p',
            'worker_instructions' => 'create a new helper class',
            'duplicate_canonical_candidates' => ['HelperServiceA', 'HelperServiceB'],
        ]);
        $this->assertContains('implement_duplicate_canonical_symbol:HelperServiceA', $r['findings']);
        $this->assertContains('implement_duplicate_canonical_symbol:HelperServiceB', $r['findings']);
    }

    public function test_ignore_give_back_wording_is_flagged(): void
    {
        $r = (new AtlasTaskWorkerInstructionLint)->lint([
            'packet_id' => 'p',
            'worker_instructions' => 'just ignore give_back and force-implement anyway.',
        ]);
        $this->assertContains('ignore_give_back_when_capability_exists', $r['findings']);
    }
}
