<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\SelfConstruction\Support\NormalizesToStringList;

/**
 * PART 2 · axis 8 — the task-packet SELF-SUFFICIENCY inspector (the "packet-quality scorer" the operator's
 * loop asks for). A served TaskEnvelope must be implementable by a COLD client — an AI with a meta/loop and
 * ZERO context from the conversation that produced it. This inspector emits the concrete, deterministic FACTS
 * that decide whether a packet meets that bar; it NEVER emits a scalar/rank (the pétreo "facts not score").
 *
 * The serving builder/validator already block an empty objective, empty allowed_files, and a bare-dir-only
 * scope — but NOT a packet that is missing its acceptance criteria or required evidence. Such a packet is
 * served today (verified) and strands the client: no acceptance ⇒ it can't know when it's done; no required
 * evidence ⇒ the `report` completion gate can't validate the work. These are BLOCKING deficiencies.
 *
 * BLOCKING (a cold client literally cannot implement or prove the task):
 *   - missing_objective · empty_allowed_files · missing_acceptance_criteria · missing_required_evidence
 *   - bare_directory_in_allowed_files: a directory in the WRITE set (not a concrete file). The completion
 *     enforcement matches changed files exactly against allowed_files (MF-12), so a bare dir guarantees a
 *     `files_changed_outside_allowed_scope` failure — the task is doomed before it starts.
 * ADVISORY (worth surfacing, not disqualifying):
 *   - scope_incoherent: an allowed_file not covered by scope_in.
 */
final class AtlasTaskPacketQualityInspector
{
    use NormalizesToStringList;

    public const SCHEMA = 'atlas.task_serving.packet_quality.v1';

    public const BLOCKING_DEFICIENCIES = [
        'missing_objective',
        'empty_allowed_files',
        'missing_acceptance_criteria',
        'missing_required_evidence',
        'bare_directory_in_allowed_files',
        // A packet that asks the worker to AUTHOR a test but grants no `tests/...` path in allowed_files cannot
        // be proved by that worker. Do not confuse this with ordinary "run existing gates" evidence, which is
        // legitimate for code-only tasks.
        'test_evidence_without_test_in_allowed_files',
        // Scope repair can make an originally code+test packet look "safe" by moving the forbidden
        // implementation target to forbidden_files while leaving only tests writable. If the original task still
        // names that removed target, the worker can only edit proof for code it is forbidden to change.
        'scope_repair_removed_required_target_from_allowed_files',
        // A packet whose allowed_files include a PÉTREO forbidden self-target (e.g. config/atlas.php, the loop
        // judge/guard/master-switch) is DOOMED: the worker implements + proves it, then AtlasTaskScopedCommitter
        // refuses the commit with `forbidden_self_target` → forced give_back, wasted work + tokens (confirmed
        // live on loop-cortex-memory-cli-1020 / loop-recovery-backup-composer-w840). Reject it BEFORE serving so
        // a cold worker never touches an uncommittable packet — uses the SAME guard the committer enforces, so
        // "servable" and "committable" can never disagree.
        'forbidden_self_target_in_allowed_files',
        // The final Atlas Self-Construction OS must be Atlas-native and simple: humans/Codex/Claude/Cursor can
        // bootstrap it, but a served packet must not encode a PERMANENT dependency on an operator, human approval
        // loop or external provider as the runtime owner.
        'permanent_human_or_external_provider_dependency',
        // Shared local main + exact allowed_files is the default topology that keeps multi-agent work simple.
        // Worktree/sandbox isolation can exist only as an explicit exceptional-risk tool, never as the default
        // serving contract for ordinary packets.
        'default_worktree_or_sandbox_policy',
        // Excellence gate: a TRUNCATED objective is a definitive structural defect (blocks). vague_objective and
        // acceptance_not_runnable are QUALITY signals — surfaced in deficiencies but ADVISORY (not blocking) here,
        // because inspect() is UNIVERSAL (runs on internal/minimal/test packets too) and must not starve the
        // auto-replenisher or false-block valid minimal packets. Excellence is enforced at the AUTHORING/serve
        // boundary (where a cold worker actually receives a packet), not by hard-blocking every internal packet.
        'content_truncated',
    ];

    /**
     * Concrete reference tokens that prove an objective points at something real. The check is
     * intentionally conservative — any one of these signals presence of a concrete anchor.
     */
    private const CONCRETE_REFERENCE_TOKENS = [
        '\\', // FQCN separator
        'Atlas', // class-prefix used everywhere in the codebase
        '.php', // file extension
        'php artisan', // CLI invocation
    ];

    /**
     * Runnable signals that make an acceptance criterion provable by a cold worker.
     */
    private const RUNNABLE_ACCEPTANCE_TOKENS = [
        'test',
        'artisan',
        'php ',
        'runs ',
        'executes ',
    ];

    private const TRUNCATION_MARKERS = ["\u{2026}", '...'];

    public function __construct(
        private readonly ?AtlasLoopHarnessGuard $guard = null,
    ) {}

    /**
     * Inspect a task packet (or its served projection — both expose the same fields). Returns the FACTS and a
     * boolean `self_sufficient` (no BLOCKING deficiency). Pure + deterministic.
     *
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    public function inspect(array $packet): array
    {
        $objective = trim((string) data_get($packet, 'objective', ''));
        $allowed = $this->stringList((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', [])));
        $scopeIn = $this->stringList((array) data_get($packet, 'normalized_scope.scope_in', data_get($packet, 'scope_in', [])));
        $forbidden = $this->stringList((array) data_get($packet, 'normalized_scope.forbidden_files', data_get($packet, 'forbidden_files', [])));
        $acceptance = $this->stringList((array) data_get($packet, 'acceptance_criteria', []));
        // The raw packet stores the evidence list under `evidence_requirements.required`; the served projection
        // exposes it as `required_evidence`. Read both shapes so the inspector judges either.
        $evidence = $this->stringList((array) data_get($packet, 'required_evidence', data_get($packet, 'evidence_requirements.required', [])));

        $bareDirs = array_values(array_filter($allowed, fn (string $p): bool => $this->isBareDirectory($p)));
        $uncovered = $this->uncoveredByScopeIn($allowed, $scopeIn);
        // PÉTREO commit-safety: any allowed_file the scoped committer would refuse makes the whole packet
        // uncommittable. Use the SAME guard the committer uses, so a served packet is always committable.
        $guard = $this->guard ?? new AtlasLoopHarnessGuard;
        $forbiddenTargets = array_values(array_filter($allowed, fn (string $p): bool => $guard->isForbiddenSelfTarget($p)));
        $scopeRepairRemovedTargets = $this->scopeRepairRemovedRequiredTargets($objective, $acceptance, $forbidden);
        $scopeRepairAcceptanceTargets = $this->targetsMentionedInText(implode("\n", $acceptance), $scopeRepairRemovedTargets);
        $policyText = $objective."\n".implode("\n", $acceptance);
        $permanentExternalDependencies = $this->permanentHumanOrExternalProviderDependencies($policyText);
        $contractAutonomyViolations = $this->simplicityContractAutonomyViolations($packet);
        $defaultIsolationViolations = $this->defaultWorktreeOrSandboxViolations($packet, $policyText);
        $blindOrphanWiringProxy = $objective !== '' && $this->objectiveIsBlindOrphanWiringProxy($objective);

        $deficiencies = [];
        if ($objective === '') {
            $deficiencies[] = 'missing_objective';
        }
        if ($allowed === []) {
            $deficiencies[] = 'empty_allowed_files';
        }
        if ($acceptance === []) {
            $deficiencies[] = 'missing_acceptance_criteria';
        }
        if ($evidence === []) {
            $deficiencies[] = 'missing_required_evidence';
        }
        if ($bareDirs !== []) {
            $deficiencies[] = 'bare_directory_in_allowed_files';
        }
        if ($forbiddenTargets !== []) {
            $deficiencies[] = 'forbidden_self_target_in_allowed_files';
        }
        if ($uncovered !== []) {
            $deficiencies[] = 'scope_incoherent'; // advisory
        }
        // Test-authoring ⇒ test-path coverage. `tests_or_gates_result` alone can mean "run existing gates",
        // so only fail closed when the acceptance criteria require creating/extending a test without granting
        // a tests/ path.
        $requiresTestEvidence = in_array('tests_or_gates_result', $evidence, true);
        $requiresTestAuthoring = $this->acceptanceRequiresTestAuthoring($acceptance);
        $hasTestInAllowed = $this->allowedFilesIncludeTestPath($allowed);
        if ($requiresTestEvidence && $requiresTestAuthoring && ! $hasTestInAllowed) {
            $deficiencies[] = 'test_evidence_without_test_in_allowed_files';
        }
        if ($scopeRepairRemovedTargets !== [] && ($this->allowedFilesAreOnlyTests($allowed) || $scopeRepairAcceptanceTargets !== [])) {
            $deficiencies[] = 'scope_repair_removed_required_target_from_allowed_files';
        }
        if ($permanentExternalDependencies !== [] || $contractAutonomyViolations !== []) {
            $deficiencies[] = 'permanent_human_or_external_provider_dependency';
        }
        if ($defaultIsolationViolations !== []) {
            $deficiencies[] = 'default_worktree_or_sandbox_policy';
        }

        // EXCELLENCE gate: only run when the basic structure is present (objective + acceptance),
        // so we never double-count missing fields as "vague" or "not runnable".
        if ($objective !== '' && $this->objectiveIsVague($objective)) {
            $deficiencies[] = 'vague_objective';
        }
        if ($acceptance !== [] && ! $this->acceptanceHasRunnableSignal($acceptance)) {
            $deficiencies[] = 'acceptance_not_runnable';
        }
        if ($objective !== '' && $this->objectiveEndsWithTruncationMarker($objective)) {
            $deficiencies[] = 'content_truncated';
        }
        // ANTI-PROXY (Checkpoint A author≠judge milestone): the alignment dimension the structural judge
        // lacked. ADVISORY for now (surfaces the signal without changing admission), so the judge can SEE a
        // proxy packet it previously waved through. Promotion to BLOCKING is a measured follow-up.
        if ($blindOrphanWiringProxy) {
            $deficiencies[] = 'blind_orphan_wiring_proxy';
        }
        // PRESENCE → ADEQUACY: missing_acceptance_criteria catches an EMPTY list, but a non-empty list that
        // never names any code allowed_file is just as fake — a packet writing to FooService.php can clear the
        // "presence" gate with acceptance="phpunit passes" while the criteria never bind to the change at all.
        // Surface it as an ADVISORY signal (kept OUT of BLOCKING_DEFICIENCIES on purpose: a generic runnable
        // hook is still legitimate proof for many internal/minimal packets) — anti-fake without false-blocking.
        if ($acceptance !== [] && $this->acceptanceFailsToCoverAnyAllowed($acceptance, $allowed)) {
            $deficiencies[] = 'acceptance_coverage_mismatch';
        }

        $blocking = array_values(array_intersect($deficiencies, self::BLOCKING_DEFICIENCIES));

        return [
            'schema' => self::SCHEMA,
            'self_sufficient' => $blocking === [],
            'deficiencies' => $deficiencies,
            'blocking_deficiencies' => $blocking,
            'facts' => [
                'has_objective' => $objective !== '',
                'allowed_files_count' => count($allowed),
                'acceptance_criteria_count' => count($acceptance),
                'required_evidence_count' => count($evidence),
                'bare_directories' => $bareDirs,
                'scope_uncovered_allowed_files' => $uncovered,
                'forbidden_self_targets' => $forbiddenTargets,
                'scope_repair_removed_required_targets' => $scopeRepairRemovedTargets,
                'scope_repair_removed_targets_mentioned_in_acceptance' => $scopeRepairAcceptanceTargets,
                'requires_test_authoring' => $requiresTestAuthoring,
                'permanent_human_or_external_provider_dependencies' => $permanentExternalDependencies,
                'simplicity_contract_autonomy_violations' => $contractAutonomyViolations,
                'default_worktree_or_sandbox_violations' => $defaultIsolationViolations,
                'blind_orphan_wiring_proxy' => $blindOrphanWiringProxy,
            ],
        ];
    }

    /**
     * ANTI-PROXY detector (Checkpoint A author≠judge milestone). Flags a packet whose ONLY justification
     * for wiring a capability is that the capability is itself unused/orphaned — "built-but-unused" +
     * "zero production callers" / "confirmed orphan" + a wire action — WITHOUT offering the disposition
     * alternative (delete / decide). Wiring dead code into the live flow purely because it is unused is the
     * canonical proxy pattern that flooded the queue on 2026-06-25: it ADDS legacy complexity instead of
     * serving a real need. A genuine wiring task justifies itself by a NEED; a genuine cleanup task offers
     * DELETE; a wire-or-delete decision task offers DECIDE. Any disposition token spares the packet.
     * Deterministic + conservative (precise conjunction → near-zero false positives).
     */
    private function objectiveIsBlindOrphanWiringProxy(string $objective): bool
    {
        $o = mb_strtolower($objective);

        $orphanJustification = str_contains($o, 'built-but-unused')
            || str_contains($o, 'built but unused')
            || str_contains($o, 'zero production callers')
            || str_contains($o, 'confirmed orphan');
        if (! $orphanJustification) {
            return false;
        }

        $wireAction = str_contains($o, 'into the live flow')
            || str_contains($o, 'wire ')
            || str_contains($o, 'integrate ');
        if (! $wireAction) {
            return false;
        }

        // A disposition-choice escape — the packet asks to DELETE or DECIDE rather than blindly wire, so it
        // is aligned (eliminate legacy / judge), not proxy.
        $offersDisposition = str_contains($o, 'delete')
            || str_contains($o, 'remove the')
            || str_contains($o, 'wire-or-delete')
            || str_contains($o, 'wire or delete')
            || str_contains($o, 'decide whether')
            || str_contains($o, 'disposition');

        return ! $offersDisposition;
    }

    /**
     * @return list<string>
     */
    private function permanentHumanOrExternalProviderDependencies(string $text): array
    {
        $normalized = $this->normalizePolicyText($text);
        if ($normalized === '') {
            return [];
        }

        $patterns = [
            '/\b(depend\w*|rely|relies|relying|require\w*|manual\w*|approval|handoff)\b.{0,80}\b(operator|human|claude(?: code)?|codex|cursor|external provider|provider)\b/',
            '/\b(operator|human|claude(?: code)?|codex|cursor|external provider|provider)\b.{0,80}\b(depend\w*|rely|relies|relying|require\w*|manual\w*|approval|handoff)\b/',
            '/\b(depend\w*|requer\w*|exig\w*|manual|aprov\w*|handoff)\b.{0,80}\b(operador|humano|claude(?: code)?|codex|cursor|provedor(?:es)? externo)\b/',
            '/\b(operador|humano|claude(?: code)?|codex|cursor|provedor(?:es)? externo)\b.{0,80}\b(depend\w*|requer\w*|exig\w*|manual|aprov\w*|handoff)\b/',
        ];

        return $this->policySnippets($normalized, $patterns, requirePermanentMarker: true);
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return list<string>
     */
    private function simplicityContractAutonomyViolations(array $packet): array
    {
        $contract = data_get($packet, 'simplicity_contract');
        if (! is_array($contract)) {
            return [];
        }

        $violations = [];
        foreach ([
            'human_or_external_provider_dependency_allowed',
            'operator_dependency_allowed',
            'human_dependency_allowed',
            'external_provider_dependency_allowed',
            'steady_state_requires_operator',
            'steady_state_requires_human',
            'steady_state_requires_external_provider',
        ] as $field) {
            if ($this->truthyPolicyValue(data_get($contract, $field))) {
                $violations[] = 'simplicity_contract.'.$field.'=true';
            }
        }

        $finalOwner = strtolower(trim((string) data_get($contract, 'final_runtime_owner', '')));
        if ($finalOwner !== '' && $finalOwner !== 'atlas_native') {
            $violations[] = 'simplicity_contract.final_runtime_owner='.$finalOwner;
        }

        $steadyOwner = strtolower(trim((string) data_get($contract, 'steady_state_runtime_owner', '')));
        if ($steadyOwner !== '' && ! in_array($steadyOwner, ['atlas_server', 'atlas_native'], true)) {
            $violations[] = 'simplicity_contract.steady_state_runtime_owner='.$steadyOwner;
        }

        $workerRole = $this->normalizePolicyText((string) data_get($contract, 'external_worker_role', ''));
        if ($workerRole !== '' && preg_match('/\b(required|permanent|runtime owner|steady state owner|authority)\b/', $workerRole) === 1) {
            $violations[] = 'simplicity_contract.external_worker_role='.$workerRole;
        }

        return array_values(array_unique($violations));
    }

    private function truthyPolicyValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'required', 'allowed'], true);
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return list<string>
     */
    private function defaultWorktreeOrSandboxViolations(array $packet, string $text): array
    {
        $violations = [];
        $policy = data_get($packet, 'workspace_policy', []);
        $exceptional = (bool) data_get($packet, 'workspace_policy.exceptional_isolation_required', false);
        $isolation = is_array($policy)
            ? strtolower(trim((string) data_get($policy, 'isolation', '')))
            : strtolower(trim((string) $policy));
        $hasCurrentSimplicityContract = is_array(data_get($packet, 'simplicity_contract'));

        $badIsolation = [
            'simulated_worktree',
            'simulated_worktree_per_packet',
            'isolated',
            'isolated_worktree',
            'isolated_worktree_required',
            'worktree',
            'worktree_required',
            'sandbox',
            'sandbox_required',
        ];
        if ($isolation !== '' && $hasCurrentSimplicityContract && in_array($isolation, $badIsolation, true) && ! $exceptional) {
            $violations[] = 'workspace_policy.isolation='.$isolation;
        }

        $normalized = $this->normalizePolicyText($text);
        $violations = array_merge($violations, $this->policySnippets($normalized, [
            '/\b(worktree|sandbox|branch)\b.{0,60}\b(default|every task|all tasks|per task|per packet|per worker|per session)\b/',
            '/\b(default|every task|all tasks|per task|per packet|per worker|per session)\b.{0,60}\b(worktree|sandbox|branch)\b/',
            '/\b(worktree|sandbox|branch)\b.{0,60}\b(padrao|padrão|toda task|todas as tasks|por task|por worker|por sessao|por sessão)\b/',
            '/\b(padrao|padrão|toda task|todas as tasks|por task|por worker|por sessao|por sessão)\b.{0,60}\b(worktree|sandbox|branch)\b/',
        ]));

        return array_values(array_unique($violations));
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private function policySnippets(string $normalized, array $patterns, bool $requirePermanentMarker = false): array
    {
        $hits = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $normalized, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            foreach ((array) ($matches[0] ?? []) as $match) {
                $snippet = trim((string) ($match[0] ?? ''));
                $offset = max(0, (int) ($match[1] ?? 0));
                $context = trim(substr($normalized, max(0, $offset - 24), strlen($snippet) + 24));
                if ($snippet === '' || $this->isNegatedPolicySnippet($context)) {
                    continue;
                }
                if ($requirePermanentMarker && ! $this->hasPermanentDependencyMarker($context)) {
                    continue;
                }
                $hits[] = $snippet;
            }
        }

        return array_values(array_unique($hits));
    }

    private function normalizePolicyText(string $text): string
    {
        $text = strtolower(str_replace(['_', '-'], ' ', $text));
        $text = str_replace(['não', 'nao'], 'not', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function hasPermanentDependencyMarker(string $context): bool
    {
        foreach ([
            'permanent',
            'permanently',
            'final',
            'runtime owner',
            'runtime dependency',
            '24/7',
            'every task',
            'every 24/7',
            'all tasks',
            'always',
            'sempre',
            'permanente',
            'dependencia de runtime',
            'dependência de runtime',
            'toda task',
            'todas as tasks',
            'toda evolucao',
            'toda evolução',
            'atlas self construction depend',
            'atlas self-construction depend',
            'atlas self construction dependen',
            'atlas self-construction dependen',
        ] as $marker) {
            if (str_contains($context, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isNegatedPolicySnippet(string $snippet): bool
    {
        foreach (['no ', 'not ', 'never ', 'without ', 'sem ', 'nunca ', 'bootstrap only', 'apenas bootstrap', 'nao ', 'não '] as $marker) {
            if (str_contains($snippet, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** Convenience: just the boolean. */
    private function objectiveIsVague(string $objective): bool
    {
        if (mb_strlen($objective) < 40) {
            return true;
        }
        foreach (self::CONCRETE_REFERENCE_TOKENS as $token) {
            if (str_contains($objective, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $acceptance
     */
    private function acceptanceHasRunnableSignal(array $acceptance): bool
    {
        foreach ($acceptance as $criterion) {
            $haystack = strtolower($criterion);
            foreach (self::RUNNABLE_ACCEPTANCE_TOKENS as $token) {
                if (str_contains($haystack, strtolower($token))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function objectiveEndsWithTruncationMarker(string $objective): bool
    {
        $trimmed = rtrim($objective);
        foreach (self::TRUNCATION_MARKERS as $marker) {
            if ($marker !== '' && str_ends_with($trimmed, $marker)) {
                return true;
            }
        }

        return false;
    }

    public function isSelfSufficient(array $packet): bool
    {
        return (bool) $this->inspect($packet)['self_sufficient'];
    }

    /**
     * Adequacy check (vs. presence): does the acceptance text mention ANY allowed_file? An adequate criterion
     * binds to the thing being changed — naming the basename (with or without `.php`) is the cheapest, false-
     * positive-resistant signal. Skipped when allowed contains only test paths (the test file is its own proof)
     * or when there is no acceptance text yet (already caught by `missing_acceptance_criteria`).
     *
     * @param  list<string>  $acceptance
     * @param  list<string>  $allowed
     */
    private function acceptanceFailsToCoverAnyAllowed(array $acceptance, array $allowed): bool
    {
        if ($allowed === [] || $this->allowedFilesAreOnlyTests($allowed)) {
            return false; // empty allowed is BLOCKING elsewhere; test-only packets self-prove via the test file.
        }

        $basenames = [];
        foreach ($allowed as $p) {
            $norm = ltrim(str_replace('\\', '/', trim($p)), '/');
            if ($norm === '' || str_starts_with($norm, 'tests/') || str_contains($norm, '/tests/')) {
                continue; // a non-test packet that happens to also touch tests/ still proves via the non-test target.
            }
            $base = basename($norm);
            if ($base === '') {
                continue;
            }
            $basenames[] = strtolower($base);
            $stem = (string) preg_replace('/\.php$/i', '', $base);
            if ($stem !== '' && $stem !== $base) {
                $basenames[] = strtolower($stem);
            }
        }
        if ($basenames === []) {
            return false; // nothing non-test to bind against (e.g., a docs-only packet).
        }

        $haystack = strtolower(implode("\n", $acceptance));
        foreach ($basenames as $name) {
            if (str_contains($haystack, $name)) {
                return false; // at least one acceptance criterion binds to a real allowed_file.
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowedFilesIncludeTestPath(array $allowed): bool
    {
        foreach ($allowed as $p) {
            $norm = ltrim(str_replace('\\', '/', trim($p)), '/');
            if ($norm === '') {
                continue;
            }
            if (str_starts_with($norm, 'tests/') || str_contains($norm, '/tests/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowedFilesAreOnlyTests(array $allowed): bool
    {
        if ($allowed === []) {
            return false;
        }
        foreach ($allowed as $p) {
            $norm = ltrim(str_replace('\\', '/', trim($p)), '/');
            if ($norm === '' || (! str_starts_with($norm, 'tests/') && ! str_contains($norm, '/tests/'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $acceptance
     */
    private function acceptanceRequiresTestAuthoring(array $acceptance): bool
    {
        $text = strtolower(implode("\n", $acceptance));
        if ($text === '') {
            return false;
        }
        $mentionsTest = str_contains($text, 'test') || str_contains($text, 'phpunit') || str_contains($text, 'pest') || str_contains($text, 'tests/');
        if (! $mentionsTest) {
            return false;
        }

        return preg_match('/\b(write|create|add|author|amend|update|extend|introduce|exercise|exercises|cover|covers)\b/', $text) === 1
            || preg_match('#tests/[^\\s]+\\.php#', $text) === 1;
    }

    /**
     * Detect the poisonous legacy repair shape:
     *   original objective mentions Foo.php/Foo
     *   Scope repair: Foo.php was removed from allowed_files...
     *   remaining allowed_files are only tests
     *
     * The repair note itself mentions the removed path, so we only use the pre-note objective plus acceptance
     * text to prove the target is still semantically required.
     *
     * @param  list<string>  $acceptance
     * @param  list<string>  $forbidden
     * @return list<string>
     */
    private function scopeRepairRemovedRequiredTargets(string $objective, array $acceptance, array $forbidden): array
    {
        if ($forbidden === [] || stripos($objective, 'Scope repair:') === false || stripos($objective, 'removed from allowed_files') === false) {
            return [];
        }

        $removedText = '';
        if (preg_match('/Scope repair:\s*(.*?)\s+was removed from allowed_files/i', $objective, $m) === 1) {
            $removedText = (string) ($m[1] ?? '');
        }
        if ($removedText === '') {
            return [];
        }

        $preRepairObjective = trim((string) preg_replace('/\s*Scope repair:.*$/is', '', $objective));
        $requirementsText = $preRepairObjective."\n".implode("\n", $acceptance);
        if (trim($requirementsText) === '') {
            return [];
        }

        $removed = [];
        foreach ($forbidden as $target) {
            if ($this->textMentionsTarget($removedText, $target) && $this->textMentionsTarget($requirementsText, $target)) {
                $removed[] = $target;
            }
        }

        return array_values(array_unique($removed));
    }

    /**
     * @param  list<string>  $targets
     * @return list<string>
     */
    private function targetsMentionedInText(string $text, array $targets): array
    {
        $mentioned = [];
        foreach ($targets as $target) {
            if ($this->textMentionsTarget($text, $target)) {
                $mentioned[] = $target;
            }
        }

        return array_values(array_unique($mentioned));
    }

    private function textMentionsTarget(string $text, string $target): bool
    {
        $haystack = strtolower(str_replace('\\', '/', $text));
        $path = strtolower(str_replace('\\', '/', trim($target)));
        if ($haystack === '' || $path === '') {
            return false;
        }

        $base = strtolower(basename($path));
        $stem = preg_replace('/\.[^.]+$/', '', $base) ?? $base;
        $needles = [$path, $base];
        // Short stems such as "atlas" from config/atlas.php are too broad in this codebase and would turn any
        // Atlas-related sentence into a false target mention. Class/script stems remain useful and specific.
        if (strlen($stem) >= 6) {
            $needles[] = $stem;
        }
        foreach (array_filter($needles) as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** A path is a bare directory when it has no filename extension in its last segment (or ends with '/'). */
    private function isBareDirectory(string $path): bool
    {
        $raw = trim($path);
        if ($raw === '') {
            return false;
        }
        if (str_ends_with($raw, '/')) {
            return true;
        }
        $normalized = rtrim(str_replace('\\', '/', $raw), '/');
        $base = basename($normalized);

        // A concrete file has an extension in its final segment (e.g. Foo.php); a directory does not.
        return ! str_contains($base, '.');
    }

    /**
     * Allowed (write) paths not covered by any scope_in (read) path — a read/write scope incoherence.
     *
     * @param  list<string>  $allowed
     * @param  list<string>  $scopeIn
     * @return list<string>
     */
    private function uncoveredByScopeIn(array $allowed, array $scopeIn): array
    {
        if ($scopeIn === []) {
            return $allowed;
        }
        $uncovered = [];
        foreach ($allowed as $a) {
            if (WriteSetOverlap::collidingPaths([$a], $scopeIn) === []) {
                $uncovered[] = $a;
            }
        }

        return $uncovered;
    }
}
