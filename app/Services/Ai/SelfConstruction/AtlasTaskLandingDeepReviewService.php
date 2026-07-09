<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * GAP-COCKPIT-04 · review GENERATOR for a landed autonomous commit (the recorder,
 * `atlas:review:deep`, only stores findings the caller already has — this service PRODUCES
 * them from evidence).
 *
 * Read-only by construction: it inspects the landed commit with `git show`, lints the touched
 * php files, checks the diff against the receipt's allowed_files scope and the pétreo
 * forbidden-self-target list. It never writes to git, the queue, or the inbox.
 *
 * Honest ceiling: these are DETERMINISTIC checks (syntax/scope/safety/test-presence), not a
 * semantic code review — findings say what was mechanically verified, nothing more.
 */
final class AtlasTaskLandingDeepReviewService
{
    public const SCHEMA_VERSION = 'atlas.task_landing.deep_review.v1';

    public function __construct(
        private readonly ?AtlasLoopHarnessGuard $guard = null,
        private readonly ?string $repoRootOverride = null,
        private readonly ?string $receiptsPathOverride = null,
    ) {}

    /**
     * Generate findings for one landed commit. $ref is a commit sha (7-40 hex) or a
     * task_packet_id (resolved to its sha via the resolved-receipts ledger).
     *
     * @return array<string,mixed>
     */
    public function review(string $ref): array
    {
        $receipt = $this->findReceipt($ref);
        $sha = $this->isSha($ref) ? $ref : (string) ($receipt['commit_sha'] ?? '');
        if (! $this->isSha($sha)) {
            return $this->packet($ref, null, [], [$this->finding(
                'p1', 'unresolvable_ref',
                'Referência não resolve para um commit: nem sha válido, nem task com receipt de landing.',
                null,
            )], receipt: $receipt);
        }

        $repo = $this->repoRootOverride ?? base_path();
        $changed = $this->changedFiles($repo, $sha);
        if ($changed === null) {
            return $this->packet($ref, $sha, [], [$this->finding(
                'p1', 'commit_not_found',
                'git não encontrou o commit '.$sha.' neste repositório.',
                null,
            )], receipt: $receipt);
        }

        $findings = [
            ...$this->scopeFindings($changed, $receipt),
            ...$this->forbiddenTargetFindings($changed),
            ...$this->lintFindings($repo, $sha, $changed),
            ...$this->testPresenceFindings($changed),
        ];

        return $this->packet($ref, $sha, $changed, $findings, receipt: $receipt);
    }

    /**
     * @param  list<string>  $changed
     * @return list<array<string,mixed>>
     */
    private function scopeFindings(array $changed, ?array $receipt): array
    {
        $allowed = array_values(array_filter(array_map(
            static fn (mixed $f): ?string => is_string($f) && trim($f) !== '' ? trim($f) : null,
            is_array($receipt['allowed_files'] ?? null) ? $receipt['allowed_files'] : [],
        )));
        if ($allowed === []) {
            return [$this->finding(
                'p3', 'scope_unverifiable',
                'Sem receipt/allowed_files para este commit — escopo não pôde ser conferido mecanicamente.',
                null,
            )];
        }

        $out = [];
        foreach ($changed as $path) {
            if (! in_array($path, $allowed, true)) {
                $out[] = $this->finding(
                    'p1', 'scope_violation',
                    'Arquivo commitado FORA do allowed_files da task: '.$path,
                    $path,
                );
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $changed
     * @return list<array<string,mixed>>
     */
    private function forbiddenTargetFindings(array $changed): array
    {
        $guard = $this->guard ?? new AtlasLoopHarnessGuard;
        $out = [];
        foreach ($changed as $path) {
            try {
                if ($guard->isForbiddenSelfTarget($path)) {
                    $out[] = $this->finding(
                        'p0', 'forbidden_self_target',
                        'Landing tocou um alvo pétreo proibido (juiz/guard/master switch): '.$path,
                        $path,
                    );
                }
            } catch (Throwable) {
                // guard unavailable ⇒ skip silently; scope/lint findings still stand
            }
        }

        return $out;
    }

    /**
     * Lints each touched php file AS COMMITTED (`git show sha:path | php -l`), so the verdict
     * is about the landing itself, not about whatever the working tree looks like now.
     *
     * @param  list<string>  $changed
     * @return list<array<string,mixed>>
     */
    private function lintFindings(string $repo, string $sha, array $changed): array
    {
        $out = [];
        foreach ($changed as $path) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            $content = $this->git($repo, ['show', $sha.':'.$path]);
            if ($content === null) {
                continue; // deleted in this commit — nothing to lint
            }
            $lint = $this->lintSource($content);
            if ($lint !== null) {
                $out[] = $this->finding('p0', 'syntax_error', 'php -l falhou no conteúdo commitado: '.$lint, $path);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $changed
     * @return list<array<string,mixed>>
     */
    private function testPresenceFindings(array $changed): array
    {
        $touchedApp = array_filter($changed, static fn (string $p): bool => str_starts_with($p, 'app/') && str_ends_with($p, '.php'));
        $touchedTests = array_filter($changed, static fn (string $p): bool => str_starts_with($p, 'tests/'));
        if ($touchedApp !== [] && $touchedTests === []) {
            return [$this->finding(
                'p3', 'no_test_touched',
                'Landing altera '.count($touchedApp).' arquivo(s) em app/ sem tocar nenhum teste.',
                null,
            )];
        }

        return [];
    }

    /**
     * @param  list<string>  $changed
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,mixed>
     */
    private function packet(string $ref, ?string $sha, array $changed, array $findings, ?array $receipt): array
    {
        $severities = array_column($findings, 'severity');
        $risk = in_array('p0', $severities, true) || in_array('p1', $severities, true)
            ? 'blocking'
            : ($findings !== [] ? 'warning' : 'clean');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ref' => $ref,
            'sha' => $sha,
            'task_packet_id' => is_array($receipt) ? ($receipt['task_packet_id'] ?? null) : null,
            'agent_id' => is_array($receipt) ? ($receipt['agent_id'] ?? null) : null,
            'files_changed' => $changed,
            'findings' => $findings,
            'findings_count' => count($findings),
            'risk_level' => $risk,
            'recommendation' => $risk === 'blocking' ? 'reject' : ($risk === 'warning' ? 'review' : 'approve'),
            'checks_run' => ['scope_vs_allowed_files', 'forbidden_self_target', 'php_lint_committed_content', 'test_presence'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function finding(string $severity, string $category, string $title, ?string $filePath): array
    {
        return [
            'severity' => $severity,
            'category' => $category,
            'title' => $title,
            'file_path' => $filePath,
            'status' => 'open',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findReceipt(string $ref): ?array
    {
        $path = $this->receiptsPathOverride ?? AgentControlPlaneTaskQueueOrchestrator::resolvedReceiptsPath();
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines)) {
            return null;
        }
        // Walk backwards: the newest receipt for the ref wins.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $decoded = json_decode($lines[$i], true);
            if (! is_array($decoded)) {
                continue;
            }
            if (($decoded['commit_sha'] ?? null) === $ref || ($decoded['task_packet_id'] ?? null) === $ref) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null null when the commit is unknown to git
     */
    private function changedFiles(string $repo, string $sha): ?array
    {
        $out = $this->git($repo, ['show', '--name-only', '--format=', $sha]);
        if ($out === null) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $l): bool => $l !== ''));
    }

    private function lintSource(string $source): ?string
    {
        try {
            $process = new Process(['php', '-l']);
            $process->setInput($source);
            $process->setTimeout(20);
            $process->run();
            if ($process->getExitCode() === 0) {
                return null;
            }

            return trim($process->getOutput().' '.$process->getErrorOutput());
        } catch (Throwable $e) {
            return null; // lint unavailable ⇒ fail-open on this check only
        }
    }

    private function git(string $repo, array $args): ?string
    {
        try {
            $process = new Process(array_merge(['git'], $args), $repo);
            $process->setTimeout(30);
            $process->run();
            if ($process->getExitCode() !== 0) {
                return null;
            }

            return $process->getOutput();
        } catch (Throwable) {
            return null;
        }
    }

    private function isSha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{7,40}$/i', $value) === 1;
    }
}
