<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * PART 2 — the SINGLE-PROMPT worker. The operator's only action in Claude Code / Codex / any AI session is to
 * PASTE ONE PROMPT. This command prints that ready-to-paste prompt (with a unique client id), turning any AI
 * coding session into a self-driving Atlas task worker: it pulls a task, implements ONLY its allowed_files,
 * resolves it (Atlas commits its scope), and loops — until the queue is empty.
 *
 *   php artisan atlas:task:worker-prompt                 # prints a prompt with an auto unique client id
 *   php artisan atlas:task:worker-prompt --client=codex-1
 *   php artisan atlas:task:worker-prompt --keep-polling  # the worker waits + retries when the queue is empty
 */
class AtlasTaskWorkerPromptCommand extends Command
{
    protected $signature = 'atlas:task:worker-prompt
        {--client= : the worker client id (default: auto, unique)}
        {--php=/opt/homebrew/bin/php : the php binary the worker should call}
        {--keep-polling : worker waits 60s and retries when the queue is empty, instead of stopping}';

    protected $description = 'Print a ready-to-paste prompt that turns any AI session into a self-driving Atlas task worker.';

    public function handle(): int
    {
        $client = trim((string) ($this->option('client') ?? ''));
        if ($client === '') {
            $client = 'worker-'.Str::lower((string) Str::ulid());
        }
        $php = (string) $this->option('php');
        $keepPolling = (bool) $this->option('keep-polling');

        $onEmpty = $keepPolling
            ? 'sleep 60 and go back to step 1 (keep polling — the operator may enqueue more).'
            : 'STOP and tell the operator: "queue drained — resolved N tasks". Do not invent work.';

        $this->line($this->prompt($client, $php, $onEmpty));

        return self::SUCCESS;
    }

    private function prompt(string $client, string $php, string $onEmpty): string
    {
        return <<<PROMPT
You are an **Atlas task worker**. Atlas hands you self-sufficient tasks; you implement them and resolve them, in a loop, until the queue is empty. Multiple AIs run this same loop on the SAME local `main` branch at once — this is safe because Atlas only ever gives each worker a DISJOINT set of files. Your client id (use it on every call) is: **{$client}**

THE LOOP — repeat until told to stop:

1. PULL the next task:
   `{$php} artisan atlas:task next --client="{$client}" --json`
   - `status: "served"` → you got a task. Read `task.objective`, `task.allowed_files`, `task.acceptance_criteria`, `task.required_evidence`, `task.lease_id`, `task.task_packet_id`.
   - `status: "no_claimable_task"` or `"no_self_sufficient_task"` → the queue is empty. {$onEmpty}
   - `status: "disabled"` → serving is off; tell the operator to run `atlas:task:serving on`.

2. IMPLEMENT the `objective`, editing **ONLY** the files listed in `allowed_files`. Do NOT touch any other file — another worker is editing the rest of the tree right now.

3. PROVE it: run the tests/gates implied by `acceptance_criteria` / `required_evidence`. Iterate until they pass.

4. RESOLVE — Atlas commits exactly your `allowed_files` as YOUR commit:
   `{$php} artisan atlas:task report --client="{$client}" --task="<task_packet_id>" --lease="<lease_id>" --outcome=success --commit --json`
   - `status: "resolved"` → done. Go to step 1.
   - `status: "commit_failed"` → read `reason`. The lease is still yours: fix the issue and retry step 4. (`nothing_to_commit_in_scope` means you made no change in the allowed files.)

5. CAN'T DO IT? Hand the task back so another worker can take it, then go to step 1:
   `{$php} artisan atlas:task report --client="{$client}" --task="<task_packet_id>" --lease="<lease_id>" --outcome=give_back --json`

HARD RULES (shared `main` — breaking these corrupts other workers):
- **NEVER run git yourself** — no `git add`, `commit`, `reset`, `checkout`, `stash`, `pull`, `push`. The `--commit` flag makes Atlas commit your scope safely (only your files). Running `git add -A` would steal other workers' uncommitted work.
- **Edit ONLY `allowed_files`.** Nothing else.
- **Run the acceptance tests BEFORE `report --commit`.** Don't resolve a task whose tests fail.
- One task at a time. Finish (resolve or give_back) before pulling the next.

Start now: run step 1.
PROMPT;
    }
}
