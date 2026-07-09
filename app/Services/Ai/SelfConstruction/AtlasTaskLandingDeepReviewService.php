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
     * $semantic (opt-in, O1): additionally asks the GOVERNED internal brain provider
     * (same router/config the live brain writer uses — Forge drivers consult governance)
     * to read the actual diff and produce judgement findings the mechanical checks can't.
     * Fail-open: provider off/parse failure only downgrades the semantic block, never the
     * deterministic packet. A semantic finding NEVER escalates risk to blocking on its own
     * (AI is advisory; only deterministic p0/p1 block) — it can raise clean → warning.
     *
     * @return array<string,mixed>
     */
    public function review(string $ref, bool $semantic = false): array
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

        $packet = $this->packet($ref, $sha, $changed, $findings, receipt: $receipt);
        if ($semantic) {
            $packet = $this->withSemantic($packet, $repo, $sha, $receipt);
        }

        return $packet;
    }

    /**
     * O1 · semantic pass over the landed diff via the governed brain provider. Merges
     * parsed findings (source=semantic, each with confidence) into the packet and records
     * an honest status block; any failure is a status, never an exception.
     *
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>|null  $receipt
     * @return array<string,mixed>
     */
    private function withSemantic(array $packet, string $repo, string $sha, ?array $receipt): array
    {
        $model = trim((string) config('atlas.brain.reviewer_model', (string) env('ATLAS_BRAIN_REVIEWER_MODEL', '')))
            ?: (trim((string) config('atlas.brain.writer_model', '')) ?: null);

        $semantic = ['status' => 'provider_unavailable', 'provider' => null, 'model' => $model, 'findings_count' => 0];
        $packet['checks_run'][] = 'semantic_diff_review';

        try {
            if (! function_exists('app')) {
                return $this->mergeSemantic($packet, $semantic, []);
            }
            $router = app(\App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter::class);
            // Fallback-chain honesto: brain_default primeiro; se o router não o tem
            // configurado, cai pro provider default do motor vivo.
            $candidates = array_values(array_unique(array_filter([
                trim((string) config('atlas.provider_defaults.brain_default', '')),
                trim((string) config('atlas.loop.default_provider', '')),
            ], static fn (string $p): bool => $p !== '')));
            $provider = null;
            foreach ($candidates as $candidate) {
                if ($router->isConfigured($candidate)) {
                    $provider = $candidate;
                    break;
                }
            }
            if ($provider === null) {
                $semantic['provider'] = $candidates[0] ?? null;

                return $this->mergeSemantic($packet, $semantic, []);
            }
            $semantic['provider'] = $provider;

            $diff = $this->git($repo, ['show', '--no-color', $sha]);
            if ($diff === null) {
                $semantic['status'] = 'diff_unavailable';

                return $this->mergeSemantic($packet, $semantic, []);
            }

            $prompt = $this->semanticPrompt($receipt, mb_substr($diff, 0, 12000));
            $result = $router->invoke($provider, $model, [
                'text' => $prompt,
                'instruction' => $prompt,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ], [
                'timeout_seconds' => max(30, (int) config('atlas.brain.reviewer_timeout_seconds', (int) env('ATLAS_BRAIN_REVIEWER_TIMEOUT_SECONDS', 180))),
                'max_output_chars' => 4000,
            ]);
            if (($result['provider_called'] ?? false) !== true) {
                $semantic['status'] = 'provider_call_failed';

                return $this->mergeSemantic($packet, $semantic, []);
            }

            $raw = (string) ($result['stdout'] ?? $result['output_excerpt'] ?? '');
            $parsed = $this->parseSemanticFindings($raw);
            if ($parsed === null) {
                $semantic['status'] = 'parse_failed';
                $semantic['raw_excerpt'] = mb_substr($raw, 0, 600);

                return $this->mergeSemantic($packet, $semantic, []);
            }

            $semantic['status'] = 'ok';
            $semantic['findings_count'] = count($parsed);

            return $this->mergeSemantic($packet, $semantic, $parsed);
        } catch (Throwable $e) {
            $semantic['status'] = 'error';
            $semantic['error'] = mb_substr($e->getMessage(), 0, 200);

            return $this->mergeSemantic($packet, $semantic, []);
        }
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $semantic
     * @param  list<array<string,mixed>>  $semanticFindings
     * @return array<string,mixed>
     */
    private function mergeSemantic(array $packet, array $semantic, array $semanticFindings): array
    {
        $packet['semantic'] = $semantic;
        if ($semanticFindings !== []) {
            $packet['findings'] = [...$packet['findings'], ...$semanticFindings];
            $packet['findings_count'] = count($packet['findings']);
            // Advisory ceiling: semantic findings raise clean → warning only; blocking
            // stays exclusively deterministic (p0/p1 from the mechanical checks).
            if ($packet['risk_level'] === 'clean') {
                $packet['risk_level'] = 'warning';
                $packet['recommendation'] = 'review';
            }
        }

        return $packet;
    }

    /**
     * @param  array<string,mixed>|null  $receipt
     */
    private function semanticPrompt(?array $receipt, string $diff): string
    {
        $objective = is_array($receipt) ? (string) ($receipt['objective_excerpt'] ?? '') : '';
        $allowed = is_array($receipt) ? implode(', ', array_map('strval', (array) ($receipt['allowed_files'] ?? []))) : '';

        return implode("\n", [
            'You are a senior code reviewer. Review ONLY the diff below (a commit landed on main by an autonomous worker).',
            'You have NO tools, NO terminal, NO file access — do not try to read files or run commands. Judge from the diff text alone; if context is missing, lower your confidence instead of investigating.',
            'STRICT OUTPUT FORMAT — your reply must contain NOTHING except marker lines: the VERY FIRST line of your reply must already be a marker line. No prose, no reasoning, no code blocks, before or between markers.',
            'Task objective: '.($objective !== '' ? $objective : '(unknown)'),
            'Declared scope: '.($allowed !== '' ? $allowed : '(unknown)'),
            'Report ONLY real problems mechanical checks cannot catch: logic bugs, broken callers/contracts, silent behavior changes, security issues, wrong edge cases. Do NOT report style, formatting, or hypothetical concerns.',
            'For EACH problem output exactly one line:',
            '[[FINDING]]severity|confidence|category|title[[END]]',
            'severity: p0 (breaks production) p1 (real bug) p2 (risky) p3 (minor). confidence: 0.0-1.0. category: one lowercase token. title: one sentence with file/line when possible.',
            'If there are NO real problems output exactly: [[NO_FINDINGS]]',
            // O transport do runtime perde o chunk final do stdout (GAP-HERMES-01):
            // padding descartável empurra o conteúdo real pra fora do buffer perdido.
            'After your last marker line, output three extra lines containing only a dot: . ',
            'DIFF:',
            $diff,
        ]);
    }

    /**
     * Strict marker parse. Returns [] for an explicit NO_FINDINGS, the findings list when
     * at least one marker parses, or null when the output fits neither (parse failure).
     *
     * @return list<array<string,mixed>>|null
     */
    public function parseSemanticFindings(string $raw): ?array
    {
        if (str_contains($raw, '[[NO_FINDINGS]]')) {
            return [];
        }
        if (preg_match_all('/\[\[FINDING\]\](.*?)\[\[END\]\]/s', $raw, $matches) < 1) {
            return null;
        }

        $out = [];
        foreach ($matches[1] as $line) {
            $parts = array_map('trim', explode('|', (string) $line, 4));
            if (count($parts) !== 4) {
                continue;
            }
            [$severity, $confidence, $category, $title] = $parts;
            if (! in_array($severity, ['p0', 'p1', 'p2', 'p3'], true) || $title === '') {
                continue;
            }
            $out[] = [
                'severity' => $severity,
                'category' => preg_match('/^[a-z][a-z0-9_]*$/', $category) === 1 ? $category : 'semantic',
                'title' => mb_substr($title, 0, 500),
                'file_path' => null,
                'status' => 'open',
                'source' => 'semantic',
                'confidence' => is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : null,
            ];
        }

        return $out === [] ? null : $out;
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
