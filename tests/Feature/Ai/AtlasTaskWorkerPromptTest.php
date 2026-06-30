<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * PART 2 — the single-prompt worker: pasting this prompt turns any AI session into a self-driving Atlas worker.
 */
final class AtlasTaskWorkerPromptTest extends TestCase
{
    public function test_prints_a_self_contained_worker_prompt(): void
    {
        $exit = Artisan::call('atlas:task:worker-prompt', ['--client' => 'codex-7']);
        $out = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('codex-7', $out, 'the client id is baked in');
        $this->assertStringContainsString('atlas:task next --client="codex-7"', $out, 'the pull command is ready to run');
        $this->assertStringContainsString('--outcome=success --commit', $out, 'the resolve/commit step is present');
        $this->assertStringContainsString('outcome=give_back', $out, 'the give-back path is present');
        $this->assertStringContainsString('ONLY', $out, 'the allowed_files-only rule is present');
        $this->assertStringContainsString('NEVER `git add -A`', $out, 'the no-broad-git rule is present (shared main safety)');
        $this->assertStringContainsString('QUALITY BOOST', $out, 'the prompt uses spare budget for duplicate/root-cause guardrails');
    }

    public function test_auto_generates_a_unique_client_id_when_unspecified(): void
    {
        Artisan::call('atlas:task:worker-prompt');
        $a = Artisan::output();
        Artisan::call('atlas:task:worker-prompt');
        $b = Artisan::output();

        $this->assertStringContainsString('worker-', $a);
        $this->assertNotSame($a, $b, 'each invocation yields a distinct worker id (so N sessions never collide)');
        $this->assertLessThan(4000, mb_strlen(trim($a)), 'auto-generated worker prompts must fit the paste limit');
        $this->assertLessThan(4000, mb_strlen(trim($b)), 'auto-generated worker prompts must fit the paste limit');
    }

    public function test_default_worker_prompt_polls_when_queue_is_empty(): void
    {
        Artisan::call('atlas:task:worker-prompt');

        $this->assertStringContainsString('wait 60s and go back to step 1', Artisan::output());
    }

    public function test_stop_on_empty_changes_the_empty_queue_behaviour(): void
    {
        Artisan::call('atlas:task:worker-prompt', ['--stop-on-empty' => true]);

        $this->assertStringContainsString('queue has truly drained', Artisan::output());
    }

    public function test_worker_prompt_uses_the_paste_budget_without_exceeding_it(): void
    {
        Artisan::call('atlas:task:worker-prompt', ['--client' => 'musculo-1']);
        $prompt = trim(Artisan::output());

        $this->assertGreaterThanOrEqual(3924, mb_strlen($prompt));
        $this->assertLessThan(4000, mb_strlen($prompt));
    }

    public function test_prompt_contains_no_raw_git_commit_command(): void
    {
        Artisan::call('atlas:task:worker-prompt', ['--client' => 'codex-7']);
        $out = Artisan::output();

        $this->assertStringNotContainsString('`git commit', $out, 'no backtick-quoted git commit instruction — Atlas commits via --commit flag only');
    }

    public function test_prompt_contains_no_atlas_task_serving_on_instruction(): void
    {
        Artisan::call('atlas:task:worker-prompt', ['--client' => 'codex-7']);
        $out = Artisan::output();

        $this->assertStringNotContainsString('atlas:task:serving on', $out, 'disabled state must not instruct worker to re-enable the serving system');
    }

    public function test_disabled_state_instructs_give_back_and_stop_not_re_enable(): void
    {
        Artisan::call('atlas:task:worker-prompt', ['--client' => 'codex-7']);
        $out = Artisan::output();

        $this->assertStringContainsString('give_back', $out, 'disabled path must reference give_back');
        $this->assertStringContainsString('STOP', $out, 'disabled path must tell worker to stop');
    }
}
