<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\RealExecution\AtlasRepoVerifiedDeliveryService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Operator entrypoint for a REAL code delivery — a provider turns a
 * natural-language goal into a syntax-verified artifact in an isolated
 * sandbox, certified for review (never merged to the working repo).
 *
 * This is the real path that replaces the fixture diff in
 * AtlasRealEngineeringExecutionKernelService::executePatch.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-engineering-run-conductor.md
 */
class AtlasEngineeringDeliverCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:engineering:deliver
        {goal : Natural-language code goal}
        {--provider=codex_cli : Provider that generates the code (read-only)}
        {--model= : Optional model alias/id}
        {--target-file=AtlasGeneratedSnippet.php : Sandbox-relative artifact path}
        {--timeout=120 : Per-provider timeout seconds}
        {--verify-run : Ask for + run self-tests (hardened sandbox); certify only if they pass}
        {--multi-file : Allow a multi-file delivery (entry + libs); the entry requires the rest}
        {--repo-verify : Gold standard: generate impl + a PHPUnit test, run the project'."'".'s real php artisan test in an isolated worktree}
        {--impl-file=app/Services/Ai/Generated/AtlasGeneratedArtifact.php : Repo path for the impl (--repo-verify)}
        {--test-file=tests/Unit/Generated/AtlasGeneratedArtifactTest.php : Repo path for the test (--repo-verify)}
        {--apply-to-branch : (--repo-verify) On certification, commit to a review branch (never main, never pushed)}
        {--max-attempts=1 : (--repo-verify) Self-repair iterations: regenerate on test failure up to N times}
        {--json : Emit the delivery envelope as JSON}';

    protected $description = 'Real code delivery: a provider generates a syntax-verified artifact in an isolated sandbox, certified for review (never merged).';

    public function handle(AtlasLiveCodeDeliveryService $delivery, AtlasRepoVerifiedDeliveryService $repoVerified): int
    {
        $model = (string) $this->option('model');
        $modelOpt = $model !== '' ? $model : null;

        if ((bool) $this->option('repo-verify')) {
            $envelope = $repoVerified->deliver((string) $this->argument('goal'), [
                'provider' => (string) $this->option('provider'),
                'model' => $modelOpt,
                'impl_file' => (string) $this->option('impl-file'),
                'test_file' => (string) $this->option('test-file'),
                'timeout_seconds' => (int) $this->option('timeout'),
                'apply_to_branch' => (bool) $this->option('apply-to-branch'),
                'max_attempts' => (int) $this->option('max-attempts'),
            ]);
        } else {
            $envelope = $delivery->deliver((string) $this->argument('goal'), [
                'provider' => (string) $this->option('provider'),
                'model' => $modelOpt,
                'target_file' => (string) $this->option('target-file'),
                'timeout_seconds' => (int) $this->option('timeout'),
                'verify_run' => (bool) $this->option('verify-run'),
                'multi_file' => (bool) $this->option('multi-file'),
            ]);
        }

        if ($this->option('json')) {
            $this->line($this->encode($envelope));
        } else {
            $this->info(sprintf(
                'Delivery [%s] provider=%s target=%s certified=%s',
                (string) ($envelope['status'] ?? 'unknown'),
                (string) ($envelope['provider'] ?? '?'),
                (string) ($envelope['target_file'] ?? '?'),
                YesNo::format($envelope['certified'] ?? false),
            ));
            $syntax = $envelope['syntax_check'] ?? null;
            if (is_array($syntax)) {
                $this->line('  syntax: '.(($syntax['ok'] ?? false) ? 'ok' : 'FAILED'));
            }
            if (isset($envelope['blocked_reason'])) {
                $this->line('  blocked: '.(string) $envelope['blocked_reason']);
            }
            if (isset($envelope['sandbox_path'])) {
                $this->line('  artifact: '.(string) $envelope['sandbox_path'].' (review-only, not merged)');
            }
        }

        return ($envelope['certified'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
