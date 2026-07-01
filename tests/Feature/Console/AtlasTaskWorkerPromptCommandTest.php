<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class AtlasTaskWorkerPromptCommandTest extends TestCase
{
    public function test_prompt_tells_workers_to_verify_downstream_consumer_evidence(): void
    {
        $this->artisan('atlas:task:worker-prompt', ['--client' => 'test-client'])
            ->expectsOutputToContain('downstream_consumer/proof_gate name a REAL consumer')
            ->assertExitCode(0);
    }

    public function test_prompt_requires_give_back_for_detached_or_unprovable_tasks(): void
    {
        $this->artisan('atlas:task:worker-prompt', ['--client' => 'test-client'])
            ->expectsOutputToContain('give_back as detached if')
            ->assertExitCode(0);
    }

    public function test_prompt_preserves_one_task_at_a_time_rule(): void
    {
        $this->artisan('atlas:task:worker-prompt', ['--client' => 'test-client'])
            ->expectsOutputToContain('One task at a time: resolve or give_back before pulling the next')
            ->assertExitCode(0);
    }

    public function test_prompt_preserves_allowed_files_only_rule(): void
    {
        $this->artisan('atlas:task:worker-prompt', ['--client' => 'test-client'])
            ->expectsOutputToContain('Edit ONLY allowed_files. Another worker owns the rest.')
            ->assertExitCode(0);
    }

    public function test_prompt_stays_within_claude_code_char_budget(): void
    {
        \Illuminate\Support\Facades\Artisan::call('atlas:task:worker-prompt', ['--client' => 'test-client']);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertLessThan(4000, mb_strlen($output));
    }

    public function test_stop_on_empty_variant_also_stays_within_char_budget(): void
    {
        \Illuminate\Support\Facades\Artisan::call('atlas:task:worker-prompt', ['--client' => 'test-client', '--stop-on-empty' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertLessThan(4000, mb_strlen($output));
    }
}
