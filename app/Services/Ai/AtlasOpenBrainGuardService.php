<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Throwable;

/**
 * AOBG N2.F2 — the SENTINEL: the brain checks a proposed edit BEFORE it lands.
 *
 * N2.F1 ({@see AtlasOpenBrainFileContextService}) made the brain INTERVENE AFTER a
 * tool ran (PostToolUse: "this file is governed by decision X"). N2.F2 makes the
 * brain intervene BEFORE the edit lands (PreToolUse): given a proposed Edit/Write
 * (a target path + the diff/new content), it evaluates the change against the brain
 * and returns a decision — the killer-safety surface.
 *
 * It builds NO parallel engine. It ASKS the same already-proven, already-provider-
 * safe brains the N1 pack / N2.F1 delta use — the AURG reality graph + semantic
 * memory (for governed decisions) and the code-graph read-model (for duplication) —
 * SEEDED FROM THE PROPOSED EDIT rather than a prompt or an after-the-fact file open.
 *
 * THREE deterministic, cite-or-omit checks (each independent + fail-safe):
 *
 *   (a) DECISION VIOLATION — is the target file/module governed by a REGISTERED
 *       decision the edit appears to contradict? Decisions are memory entries
 *       ({@see AtlasHybridMemoryRetrievalService::recall()}, provider-safe redacted
 *       projection, memory_type=decision) AND AURG decision nodes whose cross-layer
 *       path touches the file's module. "Appears to contradict" is conservative: a
 *       decision is flagged only when its text states a constraint AND the diff
 *       contains a token that NEGATES that constraint (e.g. decision "must use the
 *       local embedding engine" + diff adds "new OpenAiEmbeddingClient"). It is a
 *       HEADS-UP by default; an EXACT contradiction is the only memory signal that
 *       can ever hard-block (and only with the flag ON).
 *
 *   (b) DUPLICATION — does the edit look like it RE-CREATES a service/symbol the
 *       code-graph already has? For a Write of a NEW file (or a diff that declares a
 *       new class/function), the proposed symbol stem is matched against the
 *       workspace symbol index ({@see EngineeringCodeIntelligenceService::symbols()},
 *       W-1 workspace-scoped); a strong name/path overlap with an EXISTING symbol in
 *       a DIFFERENT file is a "you may be rebuilding Z" warning (advisory only —
 *       never blocks; a near-name collision is too weak a signal to brick a session).
 *
 *   (c) SENSITIVE-CLASS — is the path a sensitive / secret / cyber area (provider /
 *       privacy class)? Classified by the SAME vocabulary the workspace privacy
 *       classifier uses (cyber|security → cyber; secret|vault|keys → secret;
 *       finance|health|payment|personal → sensitive) PLUS the obvious credential
 *       file shapes (.env, *.pem, *.key, credentials, secrets). A sensitive-class
 *       touch is the highest-confidence violation: it is the one path-only signal
 *       that can hard-block (flag ON), because editing a sovereign file by accident
 *       is exactly what the operator wants the sentinel to catch.
 *
 * DECISION POLICY (safety-first, non-negotiable):
 *   - DEFAULT decision = `warn` (advisory): emit reasons + evidence, NEVER block.
 *   - `block` is returned ONLY when atlas.aobg.guard.block_enabled is TRUE AND the
 *     violation is highest-confidence: a sensitive-class path touch OR an EXACT
 *     registered-decision contradiction. Duplication can NEVER block (advisory).
 *   - `allow` when no check fired (a clean edit) OR on ANY fault (fail-OPEN).
 *
 * FAIL-OPEN (non-negotiable): a false positive that BLOCKS bricks the operator's
 * session, so any error/timeout/brain-outage → `allow` (never block by accident).
 * This service NEVER throws.
 *
 * PROVIDER-SAFETY: every byte the guard returns can be injected into the engine's
 * context (the PreToolUse reason), so it is provider-bound end to end — AURG is
 * queried provider_bound=true (sensitive domains excluded by construction), memory
 * is the recall's redacted projection, symbols are name/path strings only. The
 * SENSITIVE-CLASS check NAMES the class of the path but NEVER echoes file content.
 *
 * PERF + COST (the create-path-perf memory): this runs on the operator's
 * interactive PreToolUse path. Read-only / local DB only (ZERO provider spend),
 * every query HARD-CAPPED, fail-open — a slow/broken brain degrades the guard to
 * `allow`, never stalls or blocks the session.
 */
class AtlasOpenBrainGuardService
{
    public const SCHEMA = 'atlas.aobg.guard.v1';

    public const DECISION_ALLOW = 'allow';

    public const DECISION_WARN = 'warn';

    public const DECISION_BLOCK = 'block';

    public const HONESTY_LABEL = 'curated top-K (not exhaustive); advisory-by-default, fail-open';

    /**
     * AREA words → class, matched ONLY against whole path DIRECTORY segments (so an
     * area folder like `app/Finance/...` or `secrets/vault/...` is caught, but a
     * class named `PaymentLedgerWriter` in a normal folder is NOT — a name that
     * merely CONTAINS an area word must never be mis-classified sovereign and brick a
     * session). Mirrors {@see \App\Services\Engineering\CodeGraph\CodeGraphWorkspacePrivacy}
     * so the sentinel and the graph agree on what "sovereign" means. PRIORITY order:
     * first matching class wins (cyber > secret > sensitive).
     *
     * @var list<array{class:string, segments:list<string>}>
     */
    private const SENSITIVE_AREA_RULES = [
        ['class' => 'cyber', 'segments' => ['cyber', 'security']],
        ['class' => 'secret', 'segments' => ['secret', 'secrets', 'vault', 'keys', 'credentials']],
        ['class' => 'sensitive', 'segments' => ['finance', 'health', 'payment', 'payments', 'personal']],
    ];

    /**
     * CREDENTIAL FILE shapes → class, matched against the BASENAME (a dotfile/secret
     * extension is the file itself, not a directory). Touching one of these by
     * accident is exactly the kill-case the sentinel exists for, so they fold into
     * the 'secret' tier. Matched as a suffix or exact basename, never a substring of
     * a word (so `prevent.php` does not match `.env`).
     *
     * @var list<string>
     */
    private const SECRET_FILE_NEEDLES = ['.env', '.pem', '.key', '.p12', '.keystore', '.pfx', 'id_rsa', 'credentials', '.htpasswd'];

    public function __construct(
        private readonly EngineeringCodeIntelligenceService $code,
        private readonly AtlasRealityGraphQueryService $realityGraph,
        private readonly AtlasHybridMemoryRetrievalService $memory,
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
        private readonly AtlasAobgBlackboardService $blackboard,
    ) {}

    /**
     * Evaluate a proposed edit against the brain BEFORE it lands.
     *
     * @param  string  $path  the file the edit targets (absolute or workspace-relative).
     * @param  array<string,mixed>  $opts  optional:
     *   - diff: the proposed diff / new content (drives duplication + contradiction).
     *   - workspace: explicit workspace path OR id (wins over cwd / default).
     *   - cwd: caller's working directory, resolved to a workspace id.
     *   - budget: total char ceiling for the assembled warning.
     *   - block_enabled: override the config flag (tests / explicit opt-in).
     * @return array<string,mixed> {schema, decision, reasons, evidence, ...} — see SCHEMA.
     */
    public function evaluate(string $path, array $opts = []): array
    {
        $startedAt = microtime(true);
        $rawPath = trim($path);
        $diff = $this->stringOpt($opts, 'diff') ?? '';

        // The whole evaluation is wrapped: ANY fault → allow (fail-open, never throw).
        try {
            $workspaceId = $this->resolveWorkspaceId($opts);
            $relPath = $this->relativePath($rawPath, $opts);
            $basename = $this->basename($relPath);
            $stem = $this->stem($basename);

            $blockEnabled = $this->resolveBlockEnabled($opts);
            $maxDecisions = max(1, (int) config('atlas.aobg.guard.max_decisions', 8));
            $maxDuplicates = max(1, (int) config('atlas.aobg.guard.max_duplicates', 8));
            $maxReasons = max(1, (int) config('atlas.aobg.guard.max_reasons', 12));
            $budget = $this->intOpt($opts, 'budget', (int) config('atlas.aobg.guard.budget_chars', 2500));

            // (c) SENSITIVE-CLASS — path-only, never touches content. Highest-confidence.
            $sensitive = $this->sensitiveClassCheck($relPath);
            // (a) DECISION VIOLATION — governed decisions the diff appears to contradict.
            $decisions = $this->decisionViolationCheck($relPath, $stem, $diff, $workspaceId, $maxDecisions);
            // (b) DUPLICATION — a new symbol the code-graph already has elsewhere.
            $duplication = $this->duplicationCheck($relPath, $stem, $diff, $workspaceId, $maxDuplicates);
            // (d) N2.F4 BLACKBOARD — another engine already holds this target (advisory).
            $claimConflict = $this->blackboardConflictCheck($relPath, $workspaceId, $opts);

            return $this->assemble(
                $relPath,
                $workspaceId,
                $blockEnabled,
                $sensitive,
                $decisions,
                $duplication,
                $claimConflict,
                $maxReasons,
                $budget,
                $startedAt,
            );
        } catch (Throwable) {
            // FAIL-OPEN — the sentinel can never block (or stall) a session by accident.
            return $this->failOpen($rawPath, $startedAt);
        }
    }

    // ------------------------------------------------------------------
    // Checks — each independent + fail-safe (degrade to "no finding").
    // ------------------------------------------------------------------

    /**
     * (c) SENSITIVE-CLASS — classify the PATH only (never the content). Two modes,
     * both conservative to avoid a session-bricking false positive:
     *   - AREA: a whole DIRECTORY segment is an area word (finance/secrets/cyber/...);
     *   - CREDENTIAL FILE: the BASENAME is/ends-with a secret-file shape (.env/.pem/...).
     * Returns the matched class + the needle that matched (auditable evidence), or
     * class='' when neither mode fires.
     *
     * @return array{present:bool, class:string, needle:string}
     */
    private function sensitiveClassCheck(string $relPath): array
    {
        $empty = ['present' => false, 'class' => '', 'needle' => ''];
        if ($relPath === '') {
            return $empty;
        }

        $segments = array_map('mb_strtolower', array_filter(explode('/', $relPath), static fn (string $s): bool => trim($s) !== ''));
        $basename = mb_strtolower($this->basename($relPath));

        // AREA directory match (priority order; first class wins).
        foreach (self::SENSITIVE_AREA_RULES as $rule) {
            foreach ($rule['segments'] as $seg) {
                if (in_array($seg, $segments, true)) {
                    return ['present' => true, 'class' => $rule['class'], 'needle' => $seg];
                }
            }
        }

        // CREDENTIAL FILE basename match (always 'secret' tier).
        foreach (self::SECRET_FILE_NEEDLES as $needle) {
            // suffix (.env, .pem) OR exact basename (credentials, id_rsa).
            if (str_ends_with($basename, $needle) || $basename === $needle) {
                return ['present' => true, 'class' => 'secret', 'needle' => $needle];
            }
        }

        return $empty;
    }

    /**
     * (a) DECISION VIOLATION — registered decisions governing this file's module,
     * flagged when the diff appears to CONTRADICT them. Decisions come from two
     * provider-safe brains:
     *   - semantic memory recall (memory_type=decision, redacted projection), kept
     *     only when it literally references the file's stem/path (the N2.F1 floor);
     *   - AURG decision nodes whose provider-bound cross-layer path touches the
     *     file's module.
     * Each is classified `exact` (the diff contains a token that NEGATES a stated
     * constraint — the only memory signal that can hard-block) or `advisory`
     * (governed, worth a heads-up). When there is NO diff to compare, a governed
     * decision is always advisory (we cannot prove a contradiction).
     *
     * @return array{present:bool, items:list<array{kind:string, severity:string, title:string, detail:string, source:string}>, chars:int}
     */
    private function decisionViolationCheck(string $relPath, string $stem, string $diff, string $workspaceId, int $cap): array
    {
        $empty = ['present' => false, 'items' => [], 'chars' => 0];
        if ($relPath === '') {
            return $empty;
        }

        $diffLower = mb_strtolower($diff);
        $needles = $this->relevanceNeedles($stem, $relPath);
        $items = [];
        $chars = 0;

        // --- memory decisions (semantic recall, provider-safe redacted projection) ---
        try {
            $query = trim($stem !== '' ? $stem : $relPath);
            if ($query !== '') {
                $recall = $this->memory->recall(
                    $query,
                    ['workspace' => $workspaceId],
                    [],
                    ['limit' => $cap, 'requester' => 'atlas_guard'],
                );
                foreach ((array) ($recall['recall'] ?? []) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $type = mb_strtolower((string) ($row['type'] ?? ''));
                    if ($type !== 'decision') {
                        continue; // only DECISIONS govern; principles/learnings are not gates
                    }
                    $title = (string) ($row['title'] ?? '');
                    $summary = (string) ($row['summary'] ?? '');
                    $body = (string) ($row['body'] ?? ($row['snippet'] ?? ($row['excerpt'] ?? '')));
                    $text = trim($title.' '.$summary.' '.$body);
                    if (mb_strlen($text) < 8) {
                        continue; // contentless stub — never inject "t — t" noise
                    }
                    // Cite-or-omit: the decision must literally reference THIS file.
                    if ($needles !== [] && ! $this->mentionsAny(mb_strtolower($text), $needles)) {
                        continue;
                    }
                    $severity = $this->contradictionSeverity($text, $diffLower);
                    $items[] = [
                        'kind' => 'registered_decision',
                        'severity' => $severity,
                        'title' => $title !== '' ? $title : '(untitled decision)',
                        'detail' => mb_substr(trim($summary !== '' ? $summary : $body), 0, 180),
                        'source' => 'memory',
                    ];
                    $chars += strlen($title.$summary);
                    if (count($items) >= $cap) {
                        break;
                    }
                }
            }
        } catch (Throwable) {
            // best-effort recall, never a gate
        }

        // --- AURG decision nodes whose provider-bound path touches the module ---
        if (count($items) < $cap && (bool) config('atlas.aurg.enabled', true)) {
            try {
                $dir = trim((string) (str_contains($relPath, '/') ? dirname($relPath) : ''));
                $query = trim($dir.' '.$stem);
                if ($query === '') {
                    $query = $relPath;
                }
                $result = $this->realityGraph->query($query, ['provider_bound' => true]);
                foreach ((array) ($result['nodes'] ?? []) as $node) {
                    if (! is_array($node)) {
                        continue;
                    }
                    // A decision node: memory-sourced and tagged decision in meta.
                    $metaType = mb_strtolower((string) data_get($node, 'meta.type', ''));
                    $sourceKind = mb_strtolower((string) ($node['source_kind'] ?? ''));
                    if ($metaType !== 'decision' || $sourceKind !== 'memory') {
                        continue;
                    }
                    $label = (string) ($node['label'] ?? '');
                    if (trim($label) === '') {
                        continue;
                    }
                    if ($needles !== [] && ! $this->mentionsAny(mb_strtolower($label), $needles)) {
                        continue;
                    }
                    $severity = $this->contradictionSeverity($label, $diffLower);
                    $items[] = [
                        'kind' => 'registered_decision',
                        'severity' => $severity,
                        'title' => mb_substr($label, 0, 120),
                        'detail' => 'AURG decision node governs this module',
                        'source' => 'reality_graph',
                    ];
                    $chars += strlen($label);
                    if (count($items) >= $cap) {
                        break;
                    }
                }
            } catch (Throwable) {
                // best-effort, never a gate
            }
        }

        return ['present' => $items !== [], 'items' => $items, 'chars' => $chars];
    }

    /**
     * Classify whether $diff CONTRADICTS a decision $text. Conservative cite-or-omit:
     * `exact` ONLY when the decision states a constraint ("must use X / never use Y /
     * only X") AND the diff contains a token that NEGATES it (introduces the forbidden
     * thing, or removes the mandated thing). Otherwise `advisory` (governed, but we
     * cannot prove a contradiction — and an empty diff can never be `exact`).
     */
    private function contradictionSeverity(string $decisionText, string $diffLower): string
    {
        if (trim($diffLower) === '') {
            return 'advisory'; // no diff to compare → cannot prove a contradiction
        }
        $text = mb_strtolower($decisionText);

        // Constraint pattern: "<must|never|only|forbidden|do not> ... <subject>".
        // We extract the SUBJECT after a constraint keyword and check the diff for a
        // direct negation signal around the same subject token.
        $constraintKeywords = ['must not', 'never', 'forbidden', 'do not', 'don\'t', 'must use', 'only use', 'always use', 'required to use'];
        $hasConstraint = false;
        foreach ($constraintKeywords as $kw) {
            if (str_contains($text, $kw)) {
                $hasConstraint = true;
                break;
            }
        }
        if (! $hasConstraint) {
            return 'advisory';
        }

        // EXACT only when the diff and the decision share a meaningful subject token
        // (>= 4 chars, not a stopword) AND the diff carries a forbid/introduce signal.
        // This keeps "exact" rare and high-confidence — the only memory hard-block.
        $subjects = $this->meaningfulTokens($text);
        $introduceSignals = ['new ', 'class ', 'function ', 'use ', 'import ', 'require ', 'extends ', 'implements '];
        $diffIntroduces = false;
        foreach ($introduceSignals as $sig) {
            if (str_contains($diffLower, $sig)) {
                $diffIntroduces = true;
                break;
            }
        }
        if (! $diffIntroduces) {
            return 'advisory';
        }
        foreach ($subjects as $token) {
            if (str_contains($diffLower, $token)) {
                // The diff introduces something AND names a constrained subject → exact.
                return 'exact';
            }
        }

        return 'advisory';
    }

    /**
     * (b) DUPLICATION — does the edit look like it RE-CREATES a service/symbol the
     * code-graph already has? Resolve the proposed new symbol(s): a Write of a new
     * file's stem, plus any `class X` / `function X` declared in the diff. For each,
     * search the workspace symbol index; a strong match in a DIFFERENT file is a
     * "you may be rebuilding Z" warning. ADVISORY-ONLY — a name collision is never
     * strong enough to hard-block.
     *
     * @return array{present:bool, items:list<array{name:string, existing_file:string, kind:string}>, chars:int}
     */
    private function duplicationCheck(string $relPath, string $stem, string $diff, string $workspaceId, int $cap): array
    {
        $empty = ['present' => false, 'items' => [], 'chars' => 0];
        if ($relPath === '') {
            return $empty;
        }

        $candidates = $this->proposedSymbolNames($stem, $diff);
        if ($candidates === []) {
            return $empty;
        }

        $items = [];
        $seen = [];
        $chars = 0;
        foreach ($candidates as $name) {
            if (mb_strlen($name) < 4) {
                continue; // a 1-3 char name is too generic to flag
            }
            try {
                $raw = $this->code->symbols(['q' => $name, 'workspace_id' => $workspaceId], $cap);
            } catch (Throwable) {
                continue; // best-effort, never a gate
            }
            foreach ((array) ($raw['symbols'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $existingFile = (string) ($row['file_path'] ?? '');
                if ($existingFile === '' || $existingFile === $relPath) {
                    continue; // a symbol already in THIS file is not a duplicate-creation
                }
                $symbolName = (string) ($row['symbol_name'] ?? '');
                // Require an EXACT stem match (case-insensitive) on the symbol's short
                // name — a substring/SQL wildcard hit is too weak (cite-or-omit).
                if (! $this->namesMatch($symbolName, $name)) {
                    continue;
                }
                $key = mb_strtolower($name.'|'.$existingFile);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $items[] = [
                    'name' => $name,
                    'existing_file' => $existingFile,
                    'kind' => (string) ($row['symbol_type'] ?? 'symbol'),
                ];
                $chars += strlen($name.$existingFile);
                if (count($items) >= $cap) {
                    break 2;
                }
            }
        }

        return ['present' => $items !== [], 'items' => $items, 'chars' => $chars];
    }

    /**
     * (d) N2.F4 BLACKBOARD — does ANOTHER engine already hold an active claim on the
     * file being edited? The blackboard ({@see AtlasAobgBlackboardService}) is the
     * shared coordination surface where Claude Code / Codex / Cursor stamp "I'm editing
     * fileX". When the asking engine is about to touch a target another engine holds,
     * the guard surfaces "codex is editing this file" — coordination DURING flight.
     *
     * ADVISORY-ONLY by construction: a cross-engine claim is a heads-up, NEVER a block
     * (two engines wanting the same file is a coordination signal, not a sovereign
     * violation; blocking on it would brick a legitimate hand-off). The asking engine
     * is read from opts.engine and EXCLUDED, so an engine never warns about its own
     * claim. Fail-safe: any fault → no finding (the blackboard is best-effort).
     *
     * @param  array<string,mixed>  $opts
     * @return array{present:bool, items:list<array{engine:string, kind:string, claimed_at:?string}>, chars:int}
     */
    private function blackboardConflictCheck(string $relPath, string $workspaceId, array $opts): array
    {
        $empty = ['present' => false, 'items' => [], 'chars' => 0];
        if ($relPath === '') {
            return $empty;
        }

        try {
            $askingEngine = $this->stringOpt($opts, 'engine') ?? '';
            $conflicts = $this->blackboard->conflictsFor($relPath, [
                'workspace' => $workspaceId,
                'except_engine' => $askingEngine,
            ]);
        } catch (Throwable) {
            return $empty; // best-effort, never a gate
        }

        $items = [];
        $chars = 0;
        foreach ((array) ($conflicts['claims'] ?? []) as $claim) {
            if (! is_array($claim)) {
                continue;
            }
            $engine = trim((string) ($claim['engine'] ?? ''));
            if ($engine === '') {
                continue;
            }
            $items[] = [
                'engine' => $engine,
                'kind' => (string) ($claim['kind'] ?? 'file'),
                'claimed_at' => isset($claim['claimed_at']) ? (string) $claim['claimed_at'] : null,
            ];
            $chars += strlen($engine);
        }

        return ['present' => $items !== [], 'items' => $items, 'chars' => $chars];
    }

    // ------------------------------------------------------------------
    // Assembly + decision policy
    // ------------------------------------------------------------------

    /**
     * Fuse the three checks into the final {decision, reasons, evidence}. SAFETY-
     * FIRST: default warn; block ONLY when block_enabled AND a highest-confidence
     * violation fired (sensitive-class touch OR an EXACT decision contradiction);
     * allow when nothing fired.
     *
     * @param  array{present:bool, class:string, needle:string}  $sensitive
     * @param  array{present:bool, items:list<array<string,mixed>>, chars:int}  $decisions
     * @param  array{present:bool, items:list<array<string,mixed>>, chars:int}  $duplication
     * @param  array{present:bool, items:list<array<string,mixed>>, chars:int}  $claimConflict
     * @return array<string,mixed>
     */
    private function assemble(
        string $relPath,
        string $workspaceId,
        bool $blockEnabled,
        array $sensitive,
        array $decisions,
        array $duplication,
        array $claimConflict,
        int $maxReasons,
        int $budget,
        float $startedAt,
    ): array {
        $reasons = [];
        $evidence = [];

        $hasExactDecision = false;
        foreach ($decisions['items'] as $d) {
            if (($d['severity'] ?? '') === 'exact') {
                $hasExactDecision = true;
            }
        }

        // SENSITIVE-CLASS reason (highest-confidence; path-only, no content).
        if ($sensitive['present']) {
            $reasons[] = sprintf(
                'SENSITIVE-CLASS: this path is a %s-class (sovereign) area — editing it is high-stakes. Confirm this is intended before writing.',
                $sensitive['class'],
            );
            $evidence[] = [
                'check' => 'sensitive_class',
                'class' => $sensitive['class'],
                'matched' => $sensitive['needle'],
                'confidence' => 'high',
            ];
        }

        // DECISION reasons (exact first so they lead the warning).
        usort($decisions['items'], static fn (array $a, array $b): int => ($b['severity'] === 'exact' ? 1 : 0) <=> ($a['severity'] === 'exact' ? 1 : 0));
        foreach ($decisions['items'] as $d) {
            $reasons[] = sprintf(
                '%s: this file/module is governed by a registered decision — "%s"%s',
                ($d['severity'] ?? '') === 'exact' ? 'DECISION CONTRADICTION' : 'GOVERNED-BY-DECISION',
                (string) ($d['title'] ?? ''),
                ($d['detail'] ?? '') !== '' ? ' ('.(string) $d['detail'].')' : '',
            );
            $evidence[] = [
                'check' => 'decision_violation',
                'kind' => (string) ($d['kind'] ?? 'registered_decision'),
                'severity' => (string) ($d['severity'] ?? 'advisory'),
                'title' => (string) ($d['title'] ?? ''),
                'source' => (string) ($d['source'] ?? ''),
                'confidence' => ($d['severity'] ?? '') === 'exact' ? 'high' : 'medium',
            ];
        }

        // DUPLICATION reasons (always advisory).
        foreach ($duplication['items'] as $dup) {
            $reasons[] = sprintf(
                'POSSIBLE-DUPLICATION: "%s" already exists in %s — you may be rebuilding an existing %s rather than reusing it.',
                (string) ($dup['name'] ?? ''),
                (string) ($dup['existing_file'] ?? ''),
                (string) ($dup['kind'] ?? 'symbol'),
            );
            $evidence[] = [
                'check' => 'duplication',
                'name' => (string) ($dup['name'] ?? ''),
                'existing_file' => (string) ($dup['existing_file'] ?? ''),
                'kind' => (string) ($dup['kind'] ?? 'symbol'),
                'confidence' => 'medium',
            ];
        }

        // N2.F4 BLACKBOARD claim-conflict reasons (always advisory — coordination, not
        // a gate). Surfaces "<engine> is already editing this file" so the engines step
        // around each other instead of stomping the same target.
        foreach ($claimConflict['items'] as $clash) {
            $engine = (string) ($clash['engine'] ?? '');
            $reasons[] = sprintf(
                'CROSS-ENGINE-CLAIM: %s is already editing this %s (blackboard). Coordinate before writing — you may be about to overwrite another engine\'s in-flight work.',
                $engine !== '' ? $engine : 'another engine',
                (string) ($clash['kind'] ?? 'file'),
            );
            $evidence[] = [
                'check' => 'blackboard_claim',
                'engine' => $engine,
                'kind' => (string) ($clash['kind'] ?? 'file'),
                'claimed_at' => $clash['claimed_at'] ?? null,
                'confidence' => 'medium',
            ];
        }

        $hasFinding = $reasons !== [];

        // ---- DECISION POLICY (safety-first) ----
        $decision = self::DECISION_ALLOW;
        if ($hasFinding) {
            $decision = self::DECISION_WARN; // default for ANY finding
            // Hard block ONLY: flag ON AND a highest-confidence violation.
            if ($blockEnabled && ($sensitive['present'] || $hasExactDecision)) {
                $decision = self::DECISION_BLOCK;
            }
        }

        // Trim reasons to the cap (keep the leading, highest-priority ones).
        $reasons = array_slice($reasons, 0, $maxReasons);
        // Budget the human-readable warning text (the block the hook injects).
        $reasonText = $this->budgetReasonText($reasons, $budget);

        $out = [
            'schema' => self::SCHEMA,
            'path' => $relPath,
            'workspace' => $workspaceId,
            'provider_bound' => true,
            'honesty' => self::HONESTY_LABEL,
            'decision' => $decision,
            'block_enabled' => $blockEnabled,
            'has_finding' => $hasFinding,
            'reasons' => $reasons,
            'evidence' => $evidence,
            'checks' => [
                'sensitive_class' => $sensitive['present'],
                'decision_violation' => $decisions['present'],
                'duplication' => $duplication['present'],
                'blackboard_claim' => $claimConflict['present'],
                'exact_decision_contradiction' => $hasExactDecision,
            ],
            'counts' => [
                'reasons' => count($reasons),
                'decisions' => count($decisions['items']),
                'duplicates' => count($duplication['items']),
                'claim_conflicts' => count($claimConflict['items']),
            ],
            'warning' => $reasonText,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'generated_at' => now()->toJSON(),
        ];

        return $out;
    }

    /**
     * The honest fail-open answer — `allow`, no findings, never throws.
     *
     * @return array<string,mixed>
     */
    private function failOpen(string $rawPath, float $startedAt): array
    {
        return [
            'schema' => self::SCHEMA,
            'path' => $rawPath,
            'workspace' => '',
            'provider_bound' => true,
            'honesty' => self::HONESTY_LABEL,
            'decision' => self::DECISION_ALLOW,
            'block_enabled' => false,
            'has_finding' => false,
            'reasons' => [],
            'evidence' => [],
            'checks' => [
                'sensitive_class' => false,
                'decision_violation' => false,
                'duplication' => false,
                'blackboard_claim' => false,
                'exact_decision_contradiction' => false,
            ],
            'counts' => ['reasons' => 0, 'decisions' => 0, 'duplicates' => 0, 'claim_conflicts' => 0],
            'warning' => '',
            'fail_open' => true,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'generated_at' => now()->toJSON(),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers (fail-safe — never throw)
    // ------------------------------------------------------------------

    /**
     * The proposed NEW symbol names introduced by this edit: the file's stem (a
     * Write usually names the class after the file) + any `class X` / `function X` /
     * `interface X` / `trait X` / `enum X` declared in the diff. Deduped.
     *
     * @return list<string>
     */
    private function proposedSymbolNames(string $stem, string $diff): array
    {
        $names = [];
        $push = function (string $name) use (&$names): void {
            $name = trim($name);
            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        };

        if ($stem !== '') {
            $push($stem);
        }

        if (trim($diff) !== '') {
            // Match declarations on ADDED diff lines (a leading '+') OR raw content.
            if (preg_match_all('/^\+?\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum|function)\s+([A-Za-z_][A-Za-z0-9_]*)/mi', $diff, $m) && isset($m[1])) {
                foreach ($m[1] as $name) {
                    $push((string) $name);
                }
            }
        }

        return $names;
    }

    /** Exact short-name match (case-insensitive) between an indexed symbol and a candidate. */
    private function namesMatch(string $indexedName, string $candidate): bool
    {
        $candidate = mb_strtolower(trim($candidate));
        if ($candidate === '') {
            return false;
        }
        // The indexed name may be FQN or method-qualified — compare the short tail.
        $short = $indexedName;
        foreach (['\\', '::'] as $sep) {
            $pos = mb_strrpos($short, $sep);
            if ($pos !== false) {
                $short = mb_substr($short, $pos + mb_strlen($sep));
            }
        }

        return mb_strtolower(trim($short)) === $candidate;
    }

    /**
     * Meaningful subject tokens for the contradiction check: alpha tokens >= 4 chars
     * that are not generic stopwords. Bounded.
     *
     * @return list<string>
     */
    private function meaningfulTokens(string $text): array
    {
        $stop = [
            'must', 'never', 'only', 'always', 'should', 'this', 'that', 'with', 'from', 'into',
            'have', 'will', 'must not', 'forbidden', 'required', 'using', 'used', 'when', 'them',
            'they', 'their', 'there', 'here', 'file', 'module', 'code', 'edit', 'change',
        ];
        $tokens = [];
        if (preg_match_all('/[a-z][a-z0-9_]{3,}/', mb_strtolower($text), $m) && isset($m[0])) {
            foreach ($m[0] as $tok) {
                $tok = (string) $tok;
                if (! in_array($tok, $stop, true) && ! in_array($tok, $tokens, true)) {
                    $tokens[] = $tok;
                }
                if (count($tokens) >= 24) {
                    break;
                }
            }
        }

        return $tokens;
    }

    /**
     * Budget the assembled warning text to the char ceiling (keep leading reasons).
     *
     * @param  list<string>  $reasons
     */
    private function budgetReasonText(array $reasons, int $budget): string
    {
        if ($reasons === []) {
            return '';
        }
        $lines = [];
        $used = 0;
        foreach ($reasons as $reason) {
            $line = '- '.$reason;
            $len = strlen($line) + 1;
            if ($budget > 0 && $lines !== [] && $used + $len > $budget) {
                break; // keep at least the first reason; stop at the ceiling
            }
            $lines[] = $line;
            $used += $len;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolveBlockEnabled(array $opts): bool
    {
        if (array_key_exists('block_enabled', $opts)) {
            return (bool) $opts['block_enabled'];
        }

        return (bool) config('atlas.aobg.guard.block_enabled', false);
    }

    /**
     * Resolve the workspace id: explicit `workspace` (path or id) wins, else `cwd`,
     * else the primary default. A single-method failure gracefully falls back to
     * `default()`, BUT a TOTAL workspace-identity outage (even `default()` throws) is
     * a genuine brain-down — it is RE-THROWN so the outer net fails the whole
     * evaluation OPEN to `allow` (the safety contract: brain down → allow, never a
     * default-scoped block on a phantom workspace).
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolveWorkspaceId(array $opts): string
    {
        try {
            $explicit = $this->stringOpt($opts, 'workspace');
            if ($explicit !== null) {
                return $this->workspaceIdentity->resolveWorkspaceOrId($explicit);
            }
            $cwd = $this->stringOpt($opts, 'cwd');
            if ($cwd !== null) {
                return $this->workspaceIdentity->resolve($cwd);
            }

            return $this->workspaceIdentity->default();
        } catch (Throwable) {
            // One specific resolver threw — try the default once more; if THAT also
            // throws, let it propagate to the outer fail-open net (brain down → allow).
            return $this->workspaceIdentity->default();
        }
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function relativePath(string $path, array $opts): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        $path = preg_replace('#^\./#', '', $path) ?? $path;

        $roots = [];
        foreach (['workspace', 'cwd'] as $key) {
            $candidate = $this->stringOpt($opts, $key);
            if ($candidate !== null) {
                $roots[] = str_replace('\\', '/', $candidate);
            }
        }
        try {
            $roots[] = str_replace('\\', '/', base_path());
        } catch (Throwable) {
            // base_path unavailable — relative-path normalisation still applies.
        }

        foreach ($roots as $root) {
            $root = rtrim($root, '/');
            if ($root !== '' && str_starts_with($path, $root.'/')) {
                return ltrim(substr($path, strlen($root) + 1), '/');
            }
        }

        return ltrim($path, '/');
    }

    /**
     * @return list<string>
     */
    private function relevanceNeedles(string $stem, string $relPath): array
    {
        $needles = [];
        foreach ([$stem, $this->basename($relPath), $relPath] as $candidate) {
            $candidate = mb_strtolower(trim((string) $candidate));
            if ($candidate !== '' && mb_strlen($candidate) >= 3 && ! in_array($candidate, $needles, true)) {
                $needles[] = $candidate;
            }
        }

        return $needles;
    }

    /**
     * @param  list<string>  $needles
     */
    private function mentionsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function basename(string $relPath): string
    {
        if ($relPath === '') {
            return '';
        }
        $parts = explode('/', $relPath);

        return (string) end($parts);
    }

    private function stem(string $basename): string
    {
        $basename = trim($basename);
        if ($basename === '') {
            return '';
        }
        $dot = strrpos($basename, '.');
        if ($dot === false || $dot === 0) {
            return $basename;
        }

        return substr($basename, 0, $dot);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function intOpt(array $opts, string $key, int $default): int
    {
        $raw = $opts[$key] ?? null;
        if (is_int($raw)) {
            return max(0, $raw);
        }
        if (is_string($raw) && is_numeric(trim($raw))) {
            return (int) max(0, (int) floor((float) trim($raw)));
        }

        return max(0, $default);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function stringOpt(array $opts, string $key): ?string
    {
        $raw = $opts[$key] ?? null;
        if (! is_scalar($raw)) {
            return null;
        }
        $raw = trim((string) $raw);

        return $raw !== '' ? $raw : null;
    }
}
