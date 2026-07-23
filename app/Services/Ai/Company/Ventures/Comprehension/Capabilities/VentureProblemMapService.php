<?php

declare(strict_types=1);

namespace App\Services\Ai\Company\Ventures\Comprehension\Capabilities;

use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Services\Ai\Company\Ventures\Comprehension\ComprehensionCapability;
use App\Services\Ai\Company\Ventures\Comprehension\ComprehensionRecorder;
use App\Services\Ai\Company\Ventures\Comprehension\FindingDraft;
use App\Services\Ai\Company\Ventures\Comprehension\WorkspaceReader;

/**
 * Comprehension capability `problem`: the consolidated, severity-ranked map of
 * every problem in a venture's source workspace, each one CITED.
 *
 * The map is built by deterministic detectors only — it reads the real
 * repository through the {@see WorkspaceReader} and never depends on a provider
 * or LLM, so it works fully offline. Every finding carries a concrete severity,
 * a fix recommendation and evidence (path + line + snippet) wherever a code
 * location exists; the rule is cite or omit — never invent a location.
 *
 * Detectors (kind / severity):
 *   - secret           critical  hardcoded credentials (sk_live, sk_test, api key, password literals)
 *   - dangerous_code   high      eval/exec/unserialize-on-input, raw SQL string concatenation
 *   - debt_marker      low|med   TODO/FIXME/HACK/XXX comments (FIXME/HACK = medium)
 *   - debug_leftover   low       dd()/dump()/var_dump()/console.log() in non-test source
 *   - swallowed_error  medium    empty catch blocks with no handling
 *   - large_file       low       source files over 400 lines
 *   - test_gap         medium    Controllers/ & Services/ classes with no matching *Test.php
 */
class VentureProblemMapService implements ComprehensionCapability
{
    public const KIND_SECRET = 'secret';

    public const KIND_DANGEROUS_CODE = 'dangerous_code';

    public const KIND_DEBT_MARKER = 'debt_marker';

    public const KIND_DEBUG_LEFTOVER = 'debug_leftover';

    public const KIND_SWALLOWED_ERROR = 'swallowed_error';

    public const KIND_LARGE_FILE = 'large_file';

    public const KIND_TEST_GAP = 'test_gap';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_INFO = 'info';

    /** Source files over this many lines are flagged as oversized. */
    private const LARGE_FILE_LINES = 400;

    /** Per-detector match cap so a pathological repo can't unbound the scan. */
    private const MAX_MATCHES = 500;

    public function __construct(private readonly ComprehensionRecorder $recorder) {}

    public function capability(): string
    {
        return AiVentureComprehensionFinding::CAPABILITY_PROBLEM;
    }

    /**
     * Scan the workspace, persist every cited problem and return a structured,
     * severity-ranked report.
     *
     * @return array<string,mixed>
     */
    public function scan(AiVentureComprehensionRun $run, WorkspaceReader $reader): array
    {
        /** @var list<FindingDraft> $drafts */
        $drafts = [];

        foreach ($this->detectSecrets($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->detectDangerousCode($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->detectDebtMarkers($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->detectDebugLeftovers($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->detectSwallowedErrors($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->detectLargeFiles($reader) as $draft) {
            $drafts[] = $draft;
        }
        foreach ($this->detectTestGaps($reader) as $draft) {
            $drafts[] = $draft;
        }

        $recorded = $this->recorder->recordMany($run, $drafts);

        return $this->report($recorded);
    }

    /**
     * Hardcoded credentials: live/test secret keys, api keys and password
     * literals. Highest severity — a leaked secret is shippable damage.
     *
     * @return list<FindingDraft>
     */
    private function detectSecrets(WorkspaceReader $reader): array
    {
        // Tier 1 — unambiguous provider tokens: their shape alone proves a leak,
        // so they are flagged with no value guard. Tier 2 — the generic
        // password/secret assignment form is INHERENTLY noisy in a JS/React
        // codebase (URL macros like `token: '{keyword}'`, test mocks, i18n
        // messages), so it is only flagged when the value is a strong,
        // high-entropy credential AND the file is not a test fixture. This is
        // what keeps the critical list honest instead of grotesque.
        $patterns = [
            ['re' => '/(sk_live_[A-Za-z0-9]{8,})/', 'guard' => false, 'label' => 'Stripe live secret key', 'fix' => 'Rotate the leaked live key immediately and load it from an environment variable / secret store, never source.'],
            ['re' => '/(sk_test_[A-Za-z0-9]{8,})/', 'guard' => false, 'label' => 'Stripe test secret key', 'fix' => 'Move the test key out of source into an environment variable; even test keys should not be committed.'],
            ['re' => '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/', 'guard' => false, 'label' => 'AWS access key id', 'fix' => 'Rotate the AWS key immediately and load credentials from the environment / instance role.'],
            ['re' => '/\bAIza[0-9A-Za-z_\-]{20,}/', 'guard' => false, 'label' => 'Google API key', 'fix' => 'Rotate the Google API key and restrict it; load it from configuration, never source.'],
            ['re' => '/\bghp_[A-Za-z0-9]{20,}/', 'guard' => false, 'label' => 'GitHub access token', 'fix' => 'Revoke the GitHub token immediately and store tokens in a secret manager.'],
            ['re' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}/', 'guard' => false, 'label' => 'Slack token', 'fix' => 'Revoke the Slack token and load it from the environment.'],
            ['re' => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/', 'guard' => false, 'label' => 'Private key block', 'fix' => 'Remove the private key from source, rotate the key pair and store keys outside the repository.'],
            ['re' => '/(?<![\w.])(?:password|passwd|pwd|secret|api[_-]?key|client[_-]?secret|access[_-]?token|auth[_-]?token)\s*[:=]\s*["\']([^"\']{8,})["\']/i', 'guard' => true, 'label' => 'Hardcoded credential literal', 'fix' => 'Never embed a credential in source; read it from a secret manager or environment variable and rotate it.'],
        ];

        $drafts = [];
        foreach ($patterns as $pattern) {
            foreach ($reader->grep($pattern['re'], [], self::MAX_MATCHES) as $match) {
                if ($pattern['guard']) {
                    if ($this->isTestPath($match['path'])) {
                        continue; // a credential-shaped literal in a test fixture is not a production leak
                    }
                    if (preg_match($pattern['re'], $match['text'], $m) !== 1) {
                        continue;
                    }
                    if (! $this->looksLikeStrongCredential($m[1] ?? '')) {
                        continue; // a message / template / placeholder / low-entropy value -> not a leak
                    }
                }

                $drafts[] = new FindingDraft(
                    capability: $this->capability(),
                    kind: self::KIND_SECRET,
                    title: $pattern['label'].' hardcoded in source',
                    category: 'security',
                    detail: 'A credential literal was found in source. Anyone with repository access can read it.',
                    severity: self::SEVERITY_CRITICAL,
                    impactScore: 10.0,
                    effortScore: 2.0,
                    confidence: 0.95,
                    evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                    evidencePath: $match['path'],
                    evidenceLine: $match['line'],
                    evidenceSnippet: $reader->snippet($match['path'], $match['line']),
                    recommendation: $pattern['fix'],
                );
            }
        }

        return $drafts;
    }

    /**
     * A real leaked credential for the generic assignment form: printable ASCII,
     * no whitespace, at least 16 chars, carrying a digit and a letter, and NOT a
     * template/placeholder. This separates an actual secret ("Pr0d!Secret_92xZ")
     * from URL macros ("{keyword}"), test stubs ("token-test") and pt-BR
     * validation messages (spaces + accents).
     */
    private function looksLikeStrongCredential(string $value): bool
    {
        if (preg_match('/^[\x21-\x7E]{16,200}$/', $value) !== 1) {
            return false; // spaces, accents or too short to be a real secret
        }
        if ($this->isPlaceholder($value)) {
            return false;
        }

        return preg_match('/[0-9]/', $value) === 1 && preg_match('/[A-Za-z]/', $value) === 1;
    }

    private function isPlaceholder(string $value): bool
    {
        // Template / interpolation markers, or obvious non-secret words.
        if (preg_match('/[{}<>$%]/', $value) === 1) {
            return true;
        }

        return preg_match('/(?:test|example|sample|dummy|changeme|placeholder|your[_-]?|lorem|xxxx|\.\.\.|fake|mock)/i', $value) === 1;
    }

    /**
     * Dangerous constructs: dynamic code execution and raw SQL string
     * concatenation that opens injection / RCE surface.
     *
     * @return list<FindingDraft>
     */
    private function detectDangerousCode(WorkspaceReader $reader): array
    {
        $patterns = [
            ['re' => '/\beval\s*\(/', 'label' => 'Dynamic code execution via eval()', 'fix' => 'Replace eval() with an explicit, allow-listed dispatch; never execute dynamically built code.'],
            ['re' => '/\b(?:shell_)?exec\s*\(/', 'label' => 'Shell/command execution', 'fix' => 'Avoid exec(); if a shell call is unavoidable, escape arguments and allow-list the command.'],
            ['re' => '/\bunserialize\s*\(\s*\$/', 'label' => 'unserialize() on untrusted input', 'fix' => 'Do not unserialize() request/external data; use json_decode() or a typed parser.'],
            ['re' => '/DB::raw\s*\(.*\.\s*\$/', 'label' => 'DB::raw with string concatenation', 'fix' => 'Use bound parameters instead of concatenating variables into DB::raw().'],
            ['re' => '/(?:->query|->select|->statement|->whereRaw)\s*\(\s*["\'][^"\']*["\']\s*\.\s*\$/', 'label' => 'Raw SQL built by string concatenation', 'fix' => 'Parameterize the query (bindings / prepared statement); never concatenate variables into SQL.'],
        ];

        $drafts = [];
        foreach ($patterns as $pattern) {
            foreach ($reader->grep($pattern['re'], ['php'], self::MAX_MATCHES) as $match) {
                $drafts[] = new FindingDraft(
                    capability: $this->capability(),
                    kind: self::KIND_DANGEROUS_CODE,
                    title: $pattern['label'],
                    category: 'security',
                    detail: 'A construct with injection / remote-code-execution surface was found.',
                    severity: self::SEVERITY_HIGH,
                    impactScore: 8.0,
                    effortScore: 4.0,
                    confidence: 0.8,
                    evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                    evidencePath: $match['path'],
                    evidenceLine: $match['line'],
                    evidenceSnippet: $reader->snippet($match['path'], $match['line']),
                    recommendation: $pattern['fix'],
                );
            }
        }

        return $drafts;
    }

    /**
     * Debt markers left in code: TODO/FIXME/HACK/XXX. FIXME and HACK signal a
     * known defect (medium); TODO/XXX are deferred work (low).
     *
     * @return list<FindingDraft>
     */
    private function detectDebtMarkers(WorkspaceReader $reader): array
    {
        $drafts = [];
        foreach ($reader->grep('/\b(TODO|FIXME|HACK|XXX)\b/', [], self::MAX_MATCHES) as $match) {
            if (preg_match('/\b(TODO|FIXME|HACK|XXX)\b/', $match['text'], $m) !== 1) {
                continue;
            }
            $marker = strtoupper($m[1]);
            $severity = in_array($marker, ['FIXME', 'HACK'], true)
                ? self::SEVERITY_MEDIUM
                : self::SEVERITY_LOW;

            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::KIND_DEBT_MARKER,
                title: $marker.' marker — deferred / known issue',
                category: 'maintainability',
                detail: 'A debt marker records work or a defect that was deferred in code.',
                severity: $severity,
                impactScore: $severity === self::SEVERITY_MEDIUM ? 4.0 : 2.0,
                effortScore: 3.0,
                confidence: 0.9,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $match['path'],
                evidenceLine: $match['line'],
                evidenceSnippet: $reader->snippet($match['path'], $match['line']),
                recommendation: 'Triage the '.$marker.': either resolve it or convert it into a tracked task, then remove the marker.',
                payload: ['marker' => $marker],
            );
        }

        return $drafts;
    }

    /**
     * Debug statements left in non-test source: dd/dump/var_dump/console.log.
     *
     * @return list<FindingDraft>
     */
    private function detectDebugLeftovers(WorkspaceReader $reader): array
    {
        $patterns = [
            ['re' => '/\bdd\s*\(/', 'exts' => ['php'], 'label' => 'dd() debug call'],
            ['re' => '/\bdump\s*\(/', 'exts' => ['php'], 'label' => 'dump() debug call'],
            ['re' => '/\bvar_dump\s*\(/', 'exts' => ['php'], 'label' => 'var_dump() debug call'],
            ['re' => '/\bconsole\.log\s*\(/', 'exts' => ['js', 'jsx', 'ts', 'tsx', 'vue'], 'label' => 'console.log() debug call'],
        ];

        $drafts = [];
        foreach ($patterns as $pattern) {
            foreach ($reader->grep($pattern['re'], $pattern['exts'], self::MAX_MATCHES) as $match) {
                if ($this->isTestPath($match['path'])) {
                    continue;
                }
                $drafts[] = new FindingDraft(
                    capability: $this->capability(),
                    kind: self::KIND_DEBUG_LEFTOVER,
                    title: $pattern['label'].' left in source',
                    category: 'maintainability',
                    detail: 'A debugging statement remains in non-test source; it can leak data and clutter output.',
                    severity: self::SEVERITY_LOW,
                    impactScore: 2.0,
                    effortScore: 1.0,
                    confidence: 0.85,
                    evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                    evidencePath: $match['path'],
                    evidenceLine: $match['line'],
                    evidenceSnippet: $reader->snippet($match['path'], $match['line']),
                    recommendation: 'Remove the debug statement or replace it with a proper logger.',
                );
            }
        }

        return $drafts;
    }

    /**
     * Swallowed errors: empty catch blocks that discard the exception with no
     * handling, hiding failures.
     *
     * @return list<FindingDraft>
     */
    private function detectSwallowedErrors(WorkspaceReader $reader): array
    {
        // Matches `catch (...) {` followed only by whitespace/newlines then `}`.
        $re = '/catch\s*\([^)]*\)\s*\{\s*\}/s';

        $drafts = [];
        foreach ($reader->files(['php']) as $rel) {
            $content = $reader->read($rel);
            if ($content === null) {
                continue;
            }
            if (! preg_match_all($re, $content, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as $hit) {
                $line = substr_count($content, "\n", 0, (int) $hit[1]) + 1;
                $drafts[] = new FindingDraft(
                    capability: $this->capability(),
                    kind: self::KIND_SWALLOWED_ERROR,
                    title: 'Empty catch block swallows the exception',
                    category: 'reliability',
                    detail: 'An exception is caught and silently discarded, hiding failures from logs and operators.',
                    severity: self::SEVERITY_MEDIUM,
                    impactScore: 5.0,
                    effortScore: 2.0,
                    confidence: 0.8,
                    evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                    evidencePath: $rel,
                    evidenceLine: $line,
                    evidenceSnippet: $reader->snippet($rel, $line),
                    recommendation: 'Handle, log or rethrow the caught exception; never swallow it silently.',
                );
                if (count($drafts) >= self::MAX_MATCHES) {
                    return $drafts;
                }
            }
        }

        return $drafts;
    }

    /**
     * Oversized source files (over LARGE_FILE_LINES lines) — file-level
     * finding, so the cited line is the file's last line (no single offending
     * line exists).
     *
     * @return list<FindingDraft>
     */
    private function detectLargeFiles(WorkspaceReader $reader): array
    {
        $codeExts = ['php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'py', 'rb', 'go', 'java'];

        $drafts = [];
        foreach ($reader->files($codeExts) as $rel) {
            $count = count($reader->lines($rel));
            if ($count <= self::LARGE_FILE_LINES) {
                continue;
            }
            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::KIND_LARGE_FILE,
                title: "Oversized source file ({$count} lines)",
                category: 'maintainability',
                detail: "The file has {$count} lines (threshold ".self::LARGE_FILE_LINES.'); large files are hard to review and test.',
                severity: self::SEVERITY_LOW,
                impactScore: 3.0,
                effortScore: 6.0,
                confidence: 1.0,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $rel,
                evidenceLine: $count,
                evidenceSnippet: $reader->snippet($rel, $count),
                recommendation: 'Split the file along responsibility boundaries into smaller, cohesive units.',
                payload: ['line_count' => $count],
            );
            if (count($drafts) >= self::MAX_MATCHES) {
                break;
            }
        }

        return $drafts;
    }

    /**
     * Missing test coverage: Controllers/ and Services/ PHP classes that have
     * no matching *Test.php anywhere in the repo. File-level finding (line is
     * the class declaration where observable, else null).
     *
     * @return list<FindingDraft>
     */
    private function detectTestGaps(WorkspaceReader $reader): array
    {
        // Index every test class basename present in the repo, e.g.
        // "BillingControllerTest.php" -> "billingcontroller".
        $testedNames = [];
        foreach ($reader->files(['php']) as $rel) {
            $base = basename($rel);
            if (preg_match('/^(.+)Test\.php$/', $base, $m) === 1) {
                $testedNames[strtolower($m[1])] = true;
            }
        }

        $drafts = [];
        foreach ($reader->files(['php']) as $rel) {
            if ($this->isTestPath($rel) || ! $this->isTestableSourcePath($rel)) {
                continue;
            }
            $base = basename($rel, '.php');
            if ($base === '' || isset($testedNames[strtolower($base)])) {
                continue;
            }

            $line = $this->classDeclarationLine($reader, $rel);
            $drafts[] = new FindingDraft(
                capability: $this->capability(),
                kind: self::KIND_TEST_GAP,
                title: "No test for {$base}",
                category: 'testing',
                detail: 'A controller/service class has no matching *Test.php anywhere in the repository.',
                severity: self::SEVERITY_MEDIUM,
                impactScore: 5.0,
                effortScore: 5.0,
                confidence: 0.85,
                evidenceKind: AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
                evidencePath: $rel,
                evidenceLine: $line,
                evidenceSnippet: $line !== null ? $reader->snippet($rel, $line) : null,
                recommendation: "Add a {$base}Test covering the public behavior of this class.",
                payload: ['expected_test' => $base.'Test.php'],
            );
            if (count($drafts) >= self::MAX_MATCHES) {
                break;
            }
        }

        return $drafts;
    }

    /**
     * Build the severity-ranked report from the persisted findings.
     *
     * @param  array<int,AiVentureComprehensionFinding>  $recorded
     * @return array<string,mixed>
     */
    private function report(array $recorded): array
    {
        $bySeverity = [
            self::SEVERITY_CRITICAL => 0,
            self::SEVERITY_HIGH => 0,
            self::SEVERITY_MEDIUM => 0,
            self::SEVERITY_LOW => 0,
            self::SEVERITY_INFO => 0,
        ];
        $byKind = [];
        $topCritical = [];

        foreach ($recorded as $finding) {
            $severity = $finding->severity ?? self::SEVERITY_INFO;
            if (! array_key_exists($severity, $bySeverity)) {
                $bySeverity[$severity] = 0;
            }
            $bySeverity[$severity]++;

            $byKind[$finding->kind] = ($byKind[$finding->kind] ?? 0) + 1;

            if ($severity === self::SEVERITY_CRITICAL) {
                $topCritical[] = [
                    'kind' => $finding->kind,
                    'title' => $finding->title,
                    'path' => $finding->evidence_path,
                    'line' => $finding->evidence_line,
                    'recommendation' => $finding->recommendation,
                ];
            }
        }

        return [
            'capability' => $this->capability(),
            'total' => count($recorded),
            'by_severity' => $bySeverity,
            'by_kind' => $byKind,
            'top_critical' => $topCritical,
        ];
    }

    private function isTestPath(string $relativePath): bool
    {
        $lower = strtolower($relativePath);

        return preg_match('#(?:^|/)(?:tests?|__tests__|__mocks__|spec)/#', $lower) === 1
            || preg_match('#\.(?:test|spec|stories)\.[a-z]+$#', $lower) === 1
            || str_ends_with($lower, 'test.php');
    }

    private function isTestableSourcePath(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', $relativePath);

        return preg_match('#(^|/)(Controllers|Services)/#', $normalized) === 1;
    }

    private function classDeclarationLine(WorkspaceReader $reader, string $relativePath): ?int
    {
        foreach ($reader->lines($relativePath) as $i => $text) {
            if (preg_match('/\b(?:final\s+|abstract\s+)?class\s+\w+/', $text) === 1) {
                return $i + 1;
            }
        }

        return null;
    }
}
