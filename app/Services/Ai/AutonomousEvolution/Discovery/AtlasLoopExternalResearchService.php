<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * LOOP-OS · FASE 5 · SLICE 12 — self-research intake (read-only, zero-out, fail-closed).
 *
 * The loop may pull external knowledge by TOPIC (a `string $topic`, NEVER a diff/payload) to inform the
 * projection designer — but sovereignty is non-negotiable: nothing about the repo may leak out. So every
 * realized topic passes a BEHAVIORAL EGRESS FILTER at the search boundary that REJECTS the request if it
 * carries a repo path fragment, a secret pattern, a diff/patch payload, or a long code-like fragment. The
 * tool is read-only (output is advisory research notes that NEVER gate a cert), and FAIL-CLOSED: when no
 * search tool is wired (the default here), no research happens — never a silent fallback that emits the
 * topic anyway. Bounded cadence by design; never a constant crawl.
 *
 * THE REAL PULL IS A PLUGGABLE BACKEND (sovereignty-honest): the note comes from an injected `$backend`
 * callable `fn(string $topic): ?string` — the live wiring passes a Hermes-web-backed pull; tests inject a
 * fake. There is NO hardcoded stub note: arming the flag WITHOUT a wired backend FAIL-CLOSES (a flag is not
 * research). This forbids the old `'research:'.$topic` placeholder from laundering a fake note as evidence.
 * The egress filter ({@see egressCheck}) is unchanged and still runs BEFORE the backend, so a topic that
 * would leak the repo never reaches the pull. The backend's returned note is advisory ingress only — it
 * NEVER gates a cert (the out-of-process FrozenJudge remains the sole authority).
 */
final class AtlasLoopExternalResearchService
{
    public const SCHEMA_VERSION = 'atlas.loop.external_research.v1';

    /** Secret-shaped patterns that must never egress. */
    private const SECRET_PATTERNS = [
        '/\bsk-[A-Za-z0-9]{16,}/',         // OpenAI-style keys
        '/\bAKIA[0-9A-Z]{12,}/',           // AWS access key id
        '/\bgh[pousr]_[A-Za-z0-9]{20,}/',  // GitHub tokens
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/\b(api[_-]?key|secret|token|password|passwd|bearer)\b\s*[:=]/i',
    ];

    /** @var (callable(string):?string)|null the real pull: clean topic -> advisory note (null/'' => nothing). */
    private $backend;

    /**
     * @param  bool  $searchToolAvailable  whether the research tool is armed (the operator's gate)
     * @param  (callable(string):?string)|null  $backend  the real pull. When null, the service has NO wired
     *         backend and FAIL-CLOSES even with $searchToolAvailable=true — a flag without a backend is not
     *         research (no stub launder). Injected as a fake in tests; live wiring passes a Hermes-web pull.
     */
    public function __construct(
        private readonly bool $searchToolAvailable = false,
        ?callable $backend = null,
    ) {
        $this->backend = $backend;
    }

    /**
     * Attempt to research a topic. Returns the egress verdict + (when allowed AND a backend is wired) a note.
     *
     * @return array{schema_version:string, researched:bool, blocked:bool, reason:string, topic:string, note:?string}
     */
    public function research(string $topic, string $repoRoot): array
    {
        $topic = trim($topic);
        $base = ['schema_version' => self::SCHEMA_VERSION, 'researched' => false, 'blocked' => false, 'reason' => '', 'topic' => $topic, 'note' => null];

        if ($topic === '') {
            return array_merge($base, ['reason' => 'empty_topic']);
        }

        $egress = $this->egressCheck($topic, $repoRoot);
        if (! $egress['allowed']) {
            // REJECT — never research a topic that would leak the repo. Sovereignty over capability.
            return array_merge($base, ['blocked' => true, 'reason' => 'egress_blocked:'.$egress['reason']]);
        }

        if (! $this->searchToolAvailable) {
            // FAIL-CLOSED: tool not armed ⇒ no research, never a silent fallback that emits the topic.
            return array_merge($base, ['reason' => 'no_search_tool (fail-closed)']);
        }

        if ($this->backend === null) {
            // FAIL-CLOSED: armed but no real backend wired ⇒ no research. A flag is not a research tool;
            // never launder a placeholder note as if the web were actually consulted.
            return array_merge($base, ['reason' => 'no_research_backend (fail-closed)']);
        }

        // Topic clean + armed + backend wired: do the REAL pull. The note is advisory ingress and NEVER
        // gates a cert (consumed only as authoring guidance). Any backend failure fail-closes (no fake note).
        try {
            $note = ($this->backend)($topic);
        } catch (\Throwable) {
            return array_merge($base, ['reason' => 'backend_error (fail-closed)']);
        }
        $note = is_string($note) ? trim($note) : '';
        if ($note === '') {
            return array_merge($base, ['reason' => 'backend_empty (fail-closed)']);
        }

        return array_merge($base, ['researched' => true, 'reason' => 'topic_clean', 'note' => $note]);
    }

    /**
     * The behavioral egress filter — deterministic. Blocks repo-path fragments (a .php that EXISTS in the
     * repo), diff/patch payloads, and secret-shaped strings.
     *
     * @return array{allowed:bool, reason:string}
     */
    public function egressCheck(string $topic, string $repoRoot): array
    {
        // 1. A diff/patch payload (never a $diff as a "topic").
        if (preg_match('/^(diff --git |--- |\+\+\+ |@@ )/m', $topic) === 1) {
            return ['allowed' => false, 'reason' => 'diff_payload'];
        }

        // 2. A repo path fragment that actually resolves (a real file path leaking out).
        if (preg_match_all('#[A-Za-z0-9_./\\\\-]+\.php\b#', $topic, $m) > 0) {
            foreach ($m[0] as $candidate) {
                $rel = ltrim(str_replace('\\', '/', $candidate), '/');
                if (is_file(rtrim($repoRoot, '/').'/'.$rel)) {
                    return ['allowed' => false, 'reason' => 'repo_path_fragment:'.$rel];
                }
            }
        }

        // 3. A secret-shaped string.
        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $topic) === 1) {
                return ['allowed' => false, 'reason' => 'secret_pattern'];
            }
        }

        return ['allowed' => true, 'reason' => 'clean'];
    }
}
