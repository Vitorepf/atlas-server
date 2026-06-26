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
        {--stop-on-empty : STOP when the queue drains instead of polling forever (default: never stop)}';

    protected $description = 'Print a ready-to-paste prompt that turns any AI session into a self-driving Atlas task worker.';

    public function handle(): int
    {
        $client = trim((string) ($this->option('client') ?? ''));
        if ($client === '') {
            $client = 'worker-'.Str::lower((string) Str::ulid());
        }
        $php = (string) $this->option('php');

        $onEmpty = (bool) $this->option('stop-on-empty')
            ? 'the queue has truly drained. Print one final line "queue drained — resolved N this session" and stop. Do NOT invent work.'
            : 'wait 60s and go back to step 1. Do NOT stop — tasks replenish; you keep polling overnight until the operator stops you.';

        $prompt = $this->prompt($client, $php, $onEmpty);
        $this->line($prompt);
        // Char budget is load-bearing: Claude Code rejects a goal over 4000 chars. Surface the count to STDERR so
        // stdout stays a clean, pasteable prompt.
        fwrite(STDERR, '-- prompt length: '.mb_strlen($prompt)." chars (Claude Code limit 4000) --\n");

        return self::SUCCESS;
    }

    private function prompt(string $client, string $php, string $onEmpty): string
    {
        return <<<PROMPT
You are an **Atlas task worker** (id: **{$client}** — use it on every call; run every command with `{$php}`). Atlas hands you self-sufficient tasks; you implement and resolve them in a LOOP that does NOT stop while work remains. Many AIs run this SAME loop on the SAME local `main` at once — safe because Atlas only ever gives each worker a DISJOINT set of files.

MISSION: drain the queue autonomously, overnight. You do NOT ask the operator anything. You do NOT stop "at a good point". You do NOT write status essays. You work until told to stop.

THE LOOP:
1. PULL: `{$php} artisan atlas:task next --client="{$client}" --json`
   - `served` → read task.objective, allowed_files, acceptance_criteria, required_evidence, lease_id, task_packet_id.
   - `no_claimable_task` / `no_self_sufficient_task` → {$onEmpty}
   - `waiting_on_dependencies` → wait 30s, retry. Do NOT stop, do NOT invent work.
   - `disabled` → run `{$php} artisan atlas:task:serving on`, then retry.
2. IMPLEMENT the objective, editing ONLY allowed_files. Build the COMPLETE unit (implementation + the declared tests) — however many of those files it takes. File count is irrelevant; correctness is everything.
3. PROVE: run the tests/gates from acceptance_criteria/required_evidence (`{$php} artisan test <path>`). Iterate until GREEN. Never resolve a red task.
4. RESOLVE — Atlas commits exactly your allowed_files as YOUR commit:
   `{$php} artisan atlas:task report --client="{$client}" --task="<task_packet_id>" --lease="<lease_id>" --outcome=success --commit --json`
   - `resolved` → go to 1.
   - `commit_failed` → the lease is still yours; read `reason`, fix, retry 4. (`server_verification_failed` = your change failed lint/boot/its own test — fix it. `nothing_to_commit_in_scope` = you edited nothing.)
5. TRULY IMPOSSIBLE task (self-contradictory acceptance, or needs a forbidden/pétreo file)? Hand it back with a one-line diagnosis, then go to 1 — never fake a green, never stop:
   `{$php} artisan atlas:task report --client="{$client}" --task="<task_packet_id>" --lease="<lease_id>" --outcome=give_back --json`

NEVER STOP:
- A hard or failing task is NOT a reason to stop. Fix it, or give_back with a reason, and move to the next.
- If `atlas:task` ITSELF errors repeatedly (the serving SYSTEM is broken, not one task), you are authorized to diagnose and fix the minimal cause in the code, commit ONLY the exact files you changed with `git commit -- <those files>` (NEVER `git add -A`), and resume. Then keep going.

REPORT CADENCE: after every 3 resolved tasks, print ONE line — "done N this session | last: <task_packet_id> | next: <pull status>". Nothing more.

HARD RULES (shared `main` — breaking these corrupts other workers):
- Edit ONLY allowed_files. Another worker owns the rest of the tree right now.
- NEVER `git add -A` / reset / checkout / stash / pull / push. `--commit` commits your scope safely. The only raw git allowed is the narrow `git commit -- <exact files>` self-heal in step NEVER-STOP.
- One task at a time: resolve or give_back before pulling the next. Never pull twice without finishing.

Start now: run step 1. Keep going until the operator stops you.
PROMPT;
    }
}
