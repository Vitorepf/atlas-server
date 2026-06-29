<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainProvenanceLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * EXTERNAL BRAIN · the single-prompt DECIDER worker. Prints ONE ready-to-paste prompt that turns any AI
 * session into the brain's decide-loop: pull an originated spec, comprehend it, document it, write the specs to
 * a temp JSON file, dry-run the seed gates, then seed for real — looping until the scope is dry or disabled.
 *
 * Provider-agnostic (plain NL + `php artisan atlas:brain:*` shell calls; the opaque --client is forwarded).
 * Pure string printer — no loop, no I/O, no provider call. The char count goes to STDERR (paste limit 4000).
 *
 * author≠judge is hard-stated in the prompt: the worker NEVER edits app/, NEVER commits, NEVER merges. And
 * 'disabled' PRINTS + STOPS (the brain switch is operator-only — the worker NEVER turns it back on).
 */
final class AtlasBrainWorkerPromptCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:brain:worker-prompt
        {--client= : opaque worker client id (default: auto, unique)}
        {--php=/opt/homebrew/bin/php : the php binary the worker should call}
        {--scope=autonomous : the scope slug the brain evolves}
        {--mode=originate : prompt mode — originate (the pasted model IS the brain, default) or drive (Atlas-internal originator)}
        {--baseline-seeded= : seeded count at quota start; default is current actor seeded count}
        {--target-seeds=0 : valid real-enqueued seed quota; 0 means until dry/disabled}';

    /** @var string */
    protected $description = 'Print a ready-to-paste prompt that turns any AI session into the external-brain decide-loop (author≠judge).';

    public function handle(): int
    {
        $client = trim((string) ($this->option('client') ?? '')) ?: 'brain-'.Str::lower((string) Str::ulid());
        $php = $this->phpCommand((string) $this->option('php'));
        $scope = trim((string) ($this->option('scope') ?? '')) ?: 'loop';
        $mode = trim((string) ($this->option('mode') ?? '')) === 'drive' ? 'drive' : 'originate';
        $targetSeeds = max(0, (int) ($this->option('target-seeds') ?? 0));
        $baselineOption = $this->option('baseline-seeded');
        $baselineSeeded = $targetSeeds > 0
            ? ($baselineOption === null || $baselineOption === ''
                ? $this->seededCountForActor($scope, $client)
                : max(0, (int) $baselineOption))
            : 0;

        $prompt = $mode === 'drive'
            ? $this->drivePrompt($client, $php, $scope, $targetSeeds, $baselineSeeded)
            : $this->originatePrompt($client, $php, $scope, $targetSeeds, $baselineSeeded);
        $prompt = $this->fillQuotaPromptBudget($prompt, $targetSeeds);
        $this->line($prompt);
        fwrite(STDERR, '-- prompt length: '.mb_strlen($prompt)." chars (paste limit 4000) --\n");

        return self::SUCCESS;
    }

    private function quotaBlock(int $targetSeeds, int $baselineSeeded, string $client, string $php, string $scope): string
    {
        if ($targetSeeds <= 0) {
            return '';
        }

        $clientArg = escapeshellarg($client);
        $scopeArg = escapeshellarg($scope);

        return "TARGET QUOTA: {$targetSeeds} CREDITED VALID SEEDS. ACTOR={$client} BASELINE_SEEDED={$baselineSeeded}. QUOTA PREFLIGHT before step1: `{$php} artisan atlas:brain:state --scope={$scopeArg} --target-seeds={$targetSeeds} --baseline-seeded={$baselineSeeded} --actor={$clientArg} --json` => quota.credited_valid_seeds/status; any quota result=>PREFLIGHT. quota_met=>STOP. quota.first_action.command/must_run_now nonempty=>EXECUTE VERBATIM NOW; no prose/analysis; analysis_before_must_run_now=contract_violation. quota.unattributed_seeded>0=>do_not_count. stalled_before_quota=>RUN must_run_now; obedience_failure=>RUN must_run_now; external_actor_must_execute=>true; operator_input_required=>false; never ask/stop. temp_spec_already_done=>never reseed;auto_recovery_required;analysis_allowed_before_recovery=false;discard_existing_spec_command+step1. seed_existing_spec_first=>dry_run_existing_spec_command+seed_existing_spec_command. counts.credited>0+no warnings=valid; skipped_done_set=>RUN next_command before seed; missing_actor_attribution invalid. use suggested_next_path; rotate/expand via patterns+web if under quota. Pre-dry=>harden. Never proxy.\n\n";
    }

    private function phpCommand(string $php): string
    {
        $cmd = trim($php) ?: '/opt/homebrew/bin/php';

        return $cmd === '/opt/homebrew/bin/php'
            ? '/opt/homebrew/bin/php -d memory_limit=4096M -d pcov.enabled=0'
            : $cmd;
    }

    private function fillQuotaPromptBudget(string $prompt, int $targetSeeds): string
    {
        if ($targetSeeds <= 0 || mb_strlen($prompt) >= 3900) {
            return $prompt;
        }

        $extra = "\nSPARE-BUDGET: no microtasks/wrapper farms; no arm-dormant->print-schema; each seed=distinct lever. Prefer bug/runtime/gate/decision.";
        $room = 3990 - mb_strlen($prompt);

        return $room > 0 ? $prompt.mb_substr($extra, 0, $room) : $prompt;
    }

    private function drivePrompt(string $client, string $php, string $scope, int $targetSeeds, int $baselineSeeded): string
    {
        $quota = $this->quotaBlock($targetSeeds, $baselineSeeded, $client, $php, $scope);
        $firstAction = $targetSeeds > 0 ? "FIRST ACTION: quota preflight.\n" : '';
        $clientArg = escapeshellarg($client);
        $scopeArg = escapeshellarg($scope);
        $tempSpecsArg = escapeshellarg("/tmp/brain-{$client}.json");
        $startLine = $targetSeeds > 0 ? 'Start: QUOTA PREFLIGHT, then step1.' : 'Start step 1.';
        $loopLine = $targetSeeds > 0 ? 'Preflight.' : 'Go to 1.';

        return <<<PROMPT
{$firstAction}You are the **Atlas external-brain DECIDER** (id **{$client}**; run with `{$php}`). BRAIN, not muscle: ORIGINATE + DOCUMENT the next evolution for **{$scope}**. STOP is Atlas dry/disabled, never yours.

{$quota}
AUTHOR ≠ JUDGE — HARD RULES (breaking any voids the run):
- NEVER edit app/. NEVER `git commit`, push, merge, or touch the serving queue by hand.
- Write ONLY docs/ + done-set. `atlas:brain:seed` is the enqueue gate.
- NEVER turn the brain switch on. On `disabled`, PRINT + STOP — switch is operator-only.

THE LOOP:
1. PULL: `{$php} artisan atlas:brain:next {$scopeArg} --scope-signals --actor={$clientArg} --json`
   - `served` → read packet.specs.packets[0]. Journal is already written.
   - `dry` → the scope is genuinely exhausted (the dry-probe decided, not you). Print "scope dry — N cycles" and STOP.
   - `disabled` → the brain master switch is OFF. Print "brain disabled — operator must flip ATLAS_BRAIN_MASTER_ENABLED" and STOP. Do NOT try to enable it.
   - `refused` / `abstain` / `already_done` / `forbidden_target` / `prepare_blocked` → a non-origination cycle was recorded for you. Go back to 1 (the dry-probe converges over these). Never fake work.
2. COMPREHEND: objective concrete, target real, acceptance runnable. Do NOT implement.
3. DOCUMENT: optionally append one how/why line to docs/loop-evolution-journal/{$scope}.md — docs only.
4. WRITE the spec to a temp JSON file (NOT under storage/app/atlas/task-serving or storage/ledgers), e.g.:
   `printf '%s' '{"packets":[<the served packet spec>]}' > {$tempSpecsArg}`
5. DRY-RUN the gates (zero enqueue): `{$php} artisan atlas:brain:seed --specs={$tempSpecsArg} --scope={$scopeArg} --actor={$clientArg} --require-actor --dry-run --json`
   - inspect counts.blocked; if blocked, read results[].stage/reasons. A blocked spec is NOT seeded — go back to 1 (do not force it).
6. SEED FOR REAL (only if dry-run was clean): `{$php} artisan atlas:brain:seed --specs={$tempSpecsArg} --scope={$scopeArg} --actor={$clientArg} --require-actor --cleanup-specs --json`
   - `enqueued` → if quota exists, VALID_SEEDS += counts.credited (not counts.enqueued). Workers pick it up via `atlas:task next`. {$loopLine}
   - `dry_run` with reason `brain_switch_off` → the brain switch is OFF; STOP as in step 1 `disabled`.
7. Repeat from 1 UNTIL `atlas:brain:next` returns `dry` or `disabled`.

NEVER: invent a target, edit a pétreo/forbidden file, seed a blocked spec, or self-enable the switch. If `atlas:brain:next` errors repeatedly (the brain SYSTEM is broken, not one cycle), print the error and STOP — do NOT patch app/ to "fix" it.

{$startLine}
PROMPT;
    }

    private function originatePrompt(string $client, string $php, string $scope, int $targetSeeds, int $baselineSeeded): string
    {
        $quota = $this->quotaBlock($targetSeeds, $baselineSeeded, $client, $php, $scope);
        $firstAction = $targetSeeds > 0 ? "FIRST ACTION: quota preflight.\n" : '';
        $clientArg = escapeshellarg($client);
        $scopeArg = escapeshellarg($scope);
        $tempSpecsArg = escapeshellarg("/tmp/brain-{$client}.json");
        $startLine = $targetSeeds > 0 ? 'Start: QUOTA PREFLIGHT, then step1.' : 'Start step 1.';
        $loopLine = $targetSeeds > 0 ? 'Preflight.' : 'Go to 1.';
        $altitude = $targetSeeds > 0
            ? "RULES: never edit app/; no commit/merge/queue. Write ONLY docs/. Queue via `atlas:brain:seed`. `disabled`=>STOP; never switch on; ATLAS_BRAIN_MASTER_ENABLED operator-only. No proxy/dup; STOP only on `disabled`/`dry`.\nAMBITION: max-leverage; rotate. Hermes/refused/no_proposal=>ORIGINATE+SEED; no final. Obra/prod/pipeline valid; no self-contained dry. `dry`/`disabled` stop; else change path."
            : "HARD CONSTRAINTS:\n- NEVER edit app/; no commit/merge/queue. Write ONLY docs/. Queue via `atlas:brain:seed`.\n- NEVER turn the brain switch on; ATLAS_BRAIN_MASTER_ENABLED operator-only.\n- NO proxy/faxina/duplicate. STOP only on an ATLAS signal: `disabled` or `dry`.\nAMBITION:\n- ROTATE the self-improvement portfolio; seek the most exponential lift.\n- Hermes/refused/no_proposal=>ORIGINATE+SEED; no final. Obra/prod/pipeline valid; no self-contained dry.\n- A refusal/blocked path is NOT a stop; change path.";

        return <<<PROMPT
{$firstAction}Atlas BRAIN {$scope} id {$client} (`{$php}`). author≠judge.

{$quota}
{$altitude}

1. PULL: `{$php} artisan atlas:brain:next {$scopeArg} --scope-signals --actor={$clientArg} --json`
   - `disabled`=>print "brain disabled"; STOP. `dry`=>print "scope dry"; STOP. `served`=>use packet.specs.packets[0].
   - `refused`/`abstain`/`already_done`/`prepare_blocked`/`forbidden_target`=>ORIGINATE; do not stop/final/fake.
   - `scope_signals`? use `leverage_brief.action_hint` + `recommended_draft`.
2. ORIGINATE: read real files+journal; pick ONE max-leverage evolution.
3. AUTHOR self-sufficient spec:
   - objective ≥40 chars names real FQCN/`.php`/`php artisan`; allowed_files real; one runnable acceptance (`php artisan test --filter=<OneTest>`); include scope_in/evidence/deps/wave/risk≤medium.
   - CREDIT fields REQUIRED: problem, expected_delta, value, duplicate_key=`surface|root_cause|delta`, freshness_check, anti_proxy. Test-only=>contract(target+risk+3 cases). Existing files=>modifies_existing_files+delta.
4. WRITE the spec to a temp file (NOT under storage/app/atlas/task-serving or storage/ledgers):
   `printf '%s' '{"packets":[<spec>]}' > {$tempSpecsArg}`
5. GATE (zero enqueue): `{$php} artisan atlas:brain:seed --specs={$tempSpecsArg} --scope={$scopeArg} --actor={$clientArg} --require-actor --dry-run --json`
   - blocked(proxy/vague/acceptance_not_runnable/forbidden_target/harness_gated)=>FIX+re-run; never force.
6. SEED real (only after clean dry-run): `{$php} artisan atlas:brain:seed --specs={$tempSpecsArg} --scope={$scopeArg} --actor={$clientArg} --require-actor --cleanup-specs --json`
   - `enqueued` → if quota exists, VALID_SEEDS += counts.credited (not counts.enqueued); muscle uses `atlas:task next`. {$loopLine}
   - `brain_enabled:false`=>switch OFF; tell operator to flip ATLAS_BRAIN_MASTER_ENABLED and STOP.
7. DOCUMENT: append objective + why to the journal. {$loopLine}

Never proxy; under quota search until Atlas dry. {$startLine}
PROMPT;
    }

    private function seededCountForActor(string $scope, string $actor): int
    {
        return count(array_filter(
            app(AtlasBrainProvenanceLedger::class)->tail($scope, PHP_INT_MAX),
            static fn (array $row): bool => (string) ($row['actor'] ?? '') === $actor
        ));
    }
}
