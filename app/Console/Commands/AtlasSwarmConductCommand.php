<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasEngineeringRunConductorService;
use Illuminate\Console\Command;

/**
 * Operator entrypoint for one governed, provider-agnostic engineering run that
 * consolidates the Patamar 4 swarm into a single sovereignty-safe call.
 *
 * Distinct from:
 *   - `atlas:swarm:execute`  (synthetic smoke resolver — never real providers)
 *   - `atlas:engineering:run` (single-provider harness runner for one task)
 *
 * This drives {@see AtlasEngineeringRunConductorService}: plan/route (Kernel +
 * Admission + ADML) -> execute (REAL cross-provider swarm in LIVE via the
 * Production Resolver; deterministic plan in SHADOW) -> optional blocking
 * verify -> governed envelope (+ compounding-candidate signal).
 *
 * SHADOW is the default (no provider spend). LIVE requires --mode=live AND the
 * production-resolver flag AND admission authorizing spend (ALLOW_AUTONOMOUS,
 * or ALLOW_WITH_APPROVAL + --approved). Otherwise it downgrades to SHADOW with
 * an explicit reason — never a silent escalation to real spend.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-engineering-run-conductor.md
 */
class AtlasSwarmConductCommand extends Command
{
    protected $signature = 'atlas:swarm:conduct
        {task : Natural-language engineering task / task_category}
        {--role=primary : Role used for routing}
        {--framework= : Optional framework hint}
        {--parallelism=2 : Number of arms to dispatch (1..5)}
        {--mode=shadow : shadow|live (live needs the production-resolver flag + admission)}
        {--autonomy=execute_with_approval : Requested autonomy level}
        {--approved : Operator approval for live spend when admission is allow_with_approval}
        {--provider= : Operator-directed provider override to bootstrap a real run before ADML routes (e.g. codex_cli)}
        {--provider-model= : Model alias/id for the --provider override}
        {--input= : Execution input/prompt (defaults to the task)}
        {--privacy=normal : Privacy class for the routing scope}
        {--verify : Run the blocking verification gate on the winning output}
        {--changed-files= : Comma-separated changed files for the verification gate}
        {--spec= : JSON-encoded spec for the opt-in SDD scope gate (blocks ambiguous specs pre-dispatch)}
        {--evidence-refs= : Comma-separated evidence refs carried into the compounding-candidate signal}
        {--rich-context : Assemble + inject the full Context Pack (code-intel + KB) into the prompt}
        {--compound : Feed the real LIVE outcome to the compounding pipeline so it learns (LIVE-only)}
        {--deliver-code : (LIVE) the routed provider produces a syntax-verified code artifact in a sandbox}
        {--target-file=AtlasGeneratedSnippet.php : Sandbox-relative artifact path for --deliver-code}
        {--verify-run : Run the delivered artifact'."'".'s self-tests (hardened sandbox) — certify only if they pass}
        {--multi-file : Allow a multi-file delivery (entry + libs); the entry requires the rest}
        {--json : Emit the governed run envelope as JSON}';

    protected $description = 'Run one governed, provider-agnostic cross-provider engineering swarm: plan/route -> execute -> (verify) -> governed envelope.';

    public function handle(AtlasEngineeringRunConductorService $conductor): int
    {
        $task = (string) $this->argument('task');
        $framework = (string) $this->option('framework');
        $input = (string) $this->option('input');

        $forcedProvider = (string) $this->option('provider');
        $forcedModel = (string) $this->option('provider-model');

        $work = [
            'task_category' => $task,
            'role' => (string) $this->option('role'),
            'framework' => $framework !== '' ? $framework : null,
            'parallelism' => (int) $this->option('parallelism'),
            'requested_autonomy' => (string) $this->option('autonomy'),
            'privacy_class' => (string) $this->option('privacy'),
            'scope' => ['privacy_class' => (string) $this->option('privacy')],
            'input' => $input !== '' ? $input : $task,
            'forced_provider' => $forcedProvider !== '' ? $forcedProvider : null,
            'forced_model' => $forcedModel !== '' ? $forcedModel : null,
        ];

        $changedFiles = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->option('changed-files'))),
            static fn (string $path): bool => $path !== '',
        ));

        $decodedSpec = json_decode((string) $this->option('spec'), true);
        $evidenceRefs = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->option('evidence-refs'))),
            static fn (string $ref): bool => $ref !== '',
        ));

        $options = [
            'mode' => (string) $this->option('mode'),
            'operator_approved' => (bool) $this->option('approved'),
            'verify' => (bool) $this->option('verify'),
            'changed_files' => $changedFiles,
            'spec' => is_array($decodedSpec) ? $decodedSpec : [],
            'evidence_refs' => $evidenceRefs,
            'rich_context' => (bool) $this->option('rich-context'),
            'compound' => (bool) $this->option('compound'),
            'deliver_code' => (bool) $this->option('deliver-code'),
            'target_file' => (string) $this->option('target-file'),
            'verify_run' => (bool) $this->option('verify-run'),
            'multi_file' => (bool) $this->option('multi-file'),
        ];

        $envelope = $conductor->run($work, $options);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHuman($envelope);
        }

        return ($envelope['status'] ?? null) === AtlasEngineeringRunConductorService::STATUS_VERIFIED_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function renderHuman(array $envelope): void
    {
        $downgrade = $envelope['mode_downgrade_reason'] ?? null;
        $this->info(sprintf(
            'Engineering run [%s] status=%s mode=%s (requested=%s%s) effective_parallelism=%d',
            (string) ($envelope['dispatch_id'] ?? 'n/a'),
            (string) ($envelope['status'] ?? 'unknown'),
            (string) ($envelope['mode'] ?? 'unknown'),
            (string) ($envelope['requested_mode'] ?? 'unknown'),
            is_string($downgrade) && $downgrade !== '' ? ' downgrade='.$downgrade : '',
            (int) ($envelope['effective_parallelism'] ?? 0),
        ));

        $winner = $envelope['winner'] ?? null;
        if (is_array($winner)) {
            $this->line(sprintf(
                '  winner: provider=%s model=%s result=%s',
                (string) ($winner['provider'] ?? '?'),
                (string) ($winner['model'] ?? '?'),
                (string) ($winner['result'] ?? '?'),
            ));
        }

        $verification = $envelope['verification'] ?? null;
        if (is_array($verification)) {
            $this->line('  verification: '.(string) ($verification['status'] ?? 'n/a'));
        }
    }
}
