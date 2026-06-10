<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Throwable;

/**
 * AOBG N2.F3 — STRUCTURAL capture: the session feeds the brain AUTOMATICALLY.
 *
 * N1 gave the brain a front door (the pack + the voluntary write-back tools
 * {@see AtlasOpenBrainWriteBackService::recordOutcome()} / ::proposeLearning()).
 * N2.F1/F2 made the brain INTERVENE during the session (PostToolUse delta +
 * PreToolUse sentinel). F3 closes the loop in the OTHER direction WITHOUT asking:
 * when a session ENDS, the brain is fed what the session actually DID — not because
 * the agent volunteered to call a tool, but because a Stop/SessionEnd hook fires the
 * `atlas:aobg:capture-session` distiller on the transcript. STRUCTURAL, not
 * voluntary.
 *
 * It builds NO new write engine. It DISTILLS a session transcript DETERMINISTICALLY
 * (no provider / LLM call by default — pure parsing of the transcript JSONL) into the
 * exact shape the proven governed write-back already accepts, then DELEGATES every
 * write to it so the same hostile-input floor + capture quality gate + never-auto-
 * promote apply unchanged:
 *
 *   1. OUTCOME  — the files the session TOUCHED (Edit/Write/MultiEdit tool calls) +
 *      the test/measure result it left behind → ONE provider-safe mission/evidence
 *      node via {@see AtlasOpenBrainWriteBackService::recordOutcome()} (ids/hashes/
 *      labels + a BRANCH ref only, NEVER a merge, idempotent on the session id).
 *   2. LEARNINGS — any EXPLICIT, evidence-cited learning the operator/agent stated in
 *      the transcript (a "LEARNING:" line that cites a file:line) → a `proposed`
 *      learning awaiting human review via
 *      {@see AtlasOpenBrainWriteBackService::proposeLearning()} → the capture quality
 *      gate rejects noise; nothing ever auto-applies, canonical memory is untouched.
 *
 * The transcript is the UNTRUSTED input. The distiller extracts ONLY structural
 * facts (tool-call file paths, a result status, an explicitly-marked learning line)
 * and hands them to the write-back, which is the security boundary — this service
 * adds NO new path to canonical memory and NO new auto-promotion.
 *
 * SAFETY / ANTI-NOISE (the "100% noise" lesson, capture-quality-gate-noise-finding):
 *   - a contentless session (no touched files, no result, no explicit learning) is a
 *     no-op — nothing is fed (an empty transcript must not mint a stub mission node);
 *   - learnings are EXPLICIT-ONLY (a marked + file-cited line), never inferred from
 *     prose, so the brain never learns chatter — and the capture quality gate is the
 *     final arbiter on top of that.
 *
 * NEVER throws. A malformed transcript / brain outage degrades to a fed:false reason
 * (fail-open) — capture can never break session end. Cost: local DB only, ZERO
 * provider spend (no LLM distill in the default path).
 */
class AtlasOpenBrainSessionCaptureService
{
    public const SCHEMA = 'atlas.aobg.session_capture.v1';

    /**
     * The EXPLICIT-learning marker. A transcript line is treated as a proposed
     * learning ONLY when it is marked AND cites a file:line (cite-or-omit). Inferred-
     * from-prose learning is deliberately NOT supported here — the brain must never
     * learn chatter (the noise lesson).
     */
    private const LEARNING_MARKERS = ['ATLAS-LEARNING:', 'ATLAS LEARNING:', 'LEARNING:'];

    /** A cited evidence ref is a file path with a :line or a known scheme (foo://bar). */
    private const EVIDENCE_REF_PATTERN = '#(?:[A-Za-z0-9_./\\\\-]+\.[A-Za-z0-9]+(?::\d+)?|[a-z][a-z0-9_+.-]*://[^\s]+)#';

    public function __construct(
        private readonly AtlasOpenBrainWriteBackService $writeBack,
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
    ) {}

    /**
     * Distil a session and feed the brain through the governed write-back.
     *
     * @param  array<string,mixed>  $opts  optional:
     *   - transcript: absolute path to the session transcript JSONL (Claude Code shape).
     *   - transcript_lines: list<array<string,mixed>>|list<string> already-parsed lines
     *       (test seam / non-file callers) — wins over `transcript`.
     *   - session_id: explicit session id (the mission node identity); else derived.
     *   - request: a label for what the session was asked to do; else derived.
     *   - workspace / cwd: scope (resolved + recorded for audit, never leaked).
     *   - provider: provider/agent label (e.g. claude-code).
     *   - max_learnings: cap on proposed learnings fed from one session.
     * @return array<string,mixed> {schema, fed(bool), outcome, learnings, counts, ...}
     */
    public function captureSession(array $opts = []): array
    {
        $startedAt = microtime(true);

        try {
            $lines = $this->loadLines($opts);
            $distilled = $this->distil($lines, $opts);

            $workspaceId = $this->resolveWorkspaceId($opts);
            $sessionId = $this->sessionId($opts, $distilled);
            $provider = $this->string($opts['provider'] ?? null) ?? 'session-capture';
            $maxLearnings = max(0, (int) ($opts['max_learnings']
                ?? config('atlas.aobg.session_capture.max_learnings', 10)));

            // ANTI-NOISE: a session with nothing structural to record is a NO-OP. An
            // empty/garbage transcript must never mint a contentless mission node.
            if ($distilled['files'] === [] && $distilled['result'] === null && $distilled['learnings'] === []) {
                return $this->emptyResult($workspaceId, $sessionId, $startedAt, 'nothing_to_capture');
            }

            // 1) OUTCOME — one mission/evidence node for the whole session, via the
            //    governed recorder (provider-safe, idempotent on session id, never a
            //    merge). Recorded only when there is a structural footprint to record
            //    (touched files OR a result); a learnings-only session skips the node.
            $outcome = ['fed' => false, 'reason' => 'no_outcome_footprint'];
            if ($distilled['files'] !== [] || $distilled['result'] !== null) {
                $outcome = $this->writeBack->recordOutcome([
                    'id' => $sessionId,
                    'request' => $distilled['request'],
                    'files' => $distilled['files'],
                    'branch' => $this->string($opts['branch'] ?? null) ?? '',
                    'provider' => $provider,
                    'delivered' => $distilled['result']['delivered'] ?? false,
                    'result' => $distilled['result'] ?? [],
                    'workspace' => $workspaceId,
                ]);
            }

            // 2) LEARNINGS — each explicit, file-cited learning → a `proposed` learning
            //    via the same governed pipeline (capture quality gate + never-apply).
            $learnings = [];
            foreach (array_slice($distilled['learnings'], 0, $maxLearnings) as $learning) {
                $learnings[] = $this->writeBack->proposeLearning([
                    'kind' => 'memory',
                    'summary' => $learning['summary'],
                    'evidence_refs' => $learning['evidence_refs'],
                    'scope' => 'global',
                    'provider' => $provider,
                    'workspace' => $workspaceId,
                    'payload' => ['source' => 'aobg_session_capture', 'session_id' => $sessionId],
                ]);
            }

            $outcomeFed = (bool) ($outcome['ok'] ?? false);
            $learningsFed = 0;
            foreach ($learnings as $l) {
                if (($l['ok'] ?? false) === true) {
                    $learningsFed++;
                }
            }

            $result = [
                'schema' => self::SCHEMA,
                'fed' => $outcomeFed || $learningsFed > 0,
                'session_id' => $sessionId,
                'workspace' => $workspaceId,
                'provider' => $provider,
                'provider_bound' => true,
                // The two hard guarantees, surfaced here too.
                'merged' => false,
                'auto_promoted' => false,
                'outcome' => [
                    'fed' => $outcomeFed,
                    'mission_node' => $outcome['mission_node'] ?? null,
                    'evidence_node' => $outcome['evidence_node'] ?? null,
                    'reason' => (string) ($outcome['reason'] ?? ($outcomeFed ? 'recorded' : 'not_recorded')),
                ],
                'learnings' => array_map(static fn (array $l): array => [
                    'ok' => (bool) ($l['ok'] ?? false),
                    'status' => (string) ($l['status'] ?? ''),
                    'proposal_id' => $l['proposal_id'] ?? null,
                    'reason' => (string) ($l['reason'] ?? ''),
                ], $learnings),
                'counts' => [
                    'touched_files' => count($distilled['files']),
                    'learnings_found' => count($distilled['learnings']),
                    'learnings_fed' => $learningsFed,
                ],
                'distill' => 'deterministic', // NO provider/LLM call in the default path
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'generated_at' => now()->toJSON(),
            ];

            $this->writeReceipt($result);

            return $result;
        } catch (Throwable $e) {
            // FAIL-OPEN — capture can never break session end.
            return $this->failOpen($startedAt, $e);
        }
    }

    // ------------------------------------------------------------------
    // deterministic distillation (NO provider call)
    // ------------------------------------------------------------------

    /**
     * Deterministically extract the structural footprint of a session from its
     * transcript lines: the files touched by mutating tool calls, a final test/measure
     * result, and any EXPLICIT (marked + file-cited) learnings. Pure parsing — no LLM.
     *
     * @param  list<array<string,mixed>>  $lines
     * @param  array<string,mixed>  $opts
     * @return array{files:list<string>, result:array{status?:string,ok?:bool,delivered?:bool}|null, learnings:list<array{summary:string,evidence_refs:list<string>}>, request:string, derived_session_id:?string}
     */
    private function distil(array $lines, array $opts): array
    {
        $maxFiles = max(1, (int) config('atlas.aobg.session_capture.max_files', 50));
        $maxLearningChars = max(1, (int) config('atlas.aobg.session_capture.max_learning_chars', 600));

        $files = [];
        $learnings = [];
        $result = null;
        $firstUserPrompt = null;
        $derivedSessionId = null;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            // Session id from the transcript (Claude Code stamps it on each line).
            if ($derivedSessionId === null) {
                $sid = $this->string($line['sessionId'] ?? ($line['session_id'] ?? null));
                if ($sid !== null) {
                    $derivedSessionId = $sid;
                }
            }

            // Touched files — from MUTATING tool-use entries in the transcript.
            foreach ($this->touchedFilesFromLine($line) as $path) {
                $path = $this->normalizePath($path, $opts);
                if ($path !== '' && ! in_array($path, $files, true) && count($files) < $maxFiles) {
                    $files[] = $path;
                }
            }

            // The first user prompt seeds the mission REQUEST label when none is given.
            if ($firstUserPrompt === null) {
                $firstUserPrompt = $this->userPromptFromLine($line);
            }

            // Explicit, file-cited learnings + a result marker, mined from text content.
            foreach ($this->textsFromLine($line) as $text) {
                $learning = $this->explicitLearning($text, $maxLearningChars);
                if ($learning !== null && count($learnings) < 64) {
                    $learnings[] = $learning;
                }
                $resultFromText = $this->resultFromText($text);
                if ($resultFromText !== null) {
                    $result = $resultFromText; // last marker wins (the final state)
                }
            }
        }

        // Dedup learnings by summary (a marker repeated across turns is one learning).
        $learnings = $this->dedupLearnings($learnings);

        $request = $this->string($opts['request'] ?? null)
            ?? ($firstUserPrompt !== null ? mb_substr($firstUserPrompt, 0, 280) : 'session capture');

        return [
            'files' => array_values($files),
            'result' => $result,
            'learnings' => $learnings,
            'request' => $request,
            'derived_session_id' => $derivedSessionId,
        ];
    }

    /**
     * Extract file paths a transcript line MUTATED (Edit/Write/MultiEdit/NotebookEdit
     * tool calls). Supports the Claude Code assistant-message tool_use shape and a
     * flat {tool_name, tool_input} shape (test seam). Read/Bash/etc. are ignored — a
     * read is not a footprint.
     *
     * @param  array<string,mixed>  $line
     * @return list<string>
     */
    private function touchedFilesFromLine(array $line): array
    {
        $mutating = ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'];
        $paths = [];

        $collect = function (string $toolName, mixed $input) use (&$paths, $mutating): void {
            if (! in_array($toolName, $mutating, true) || ! is_array($input)) {
                return;
            }
            foreach (['file_path', 'path', 'notebook_path'] as $key) {
                $p = $this->string($input[$key] ?? null);
                if ($p !== null) {
                    $paths[] = $p;
                }
            }
        };

        // Flat shape: {tool_name, tool_input}.
        $flatTool = $this->string($line['tool_name'] ?? null);
        if ($flatTool !== null) {
            $collect($flatTool, $line['tool_input'] ?? null);
        }

        // Claude Code shape: {type:"assistant", message:{content:[{type:"tool_use",name,input}]}}.
        $content = $this->messageContent($line);
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'tool_use') {
                $collect((string) ($block['name'] ?? ''), $block['input'] ?? null);
            }
        }

        return $paths;
    }

    /**
     * All free-text content carried by a transcript line (user prompt, assistant text
     * blocks, top-level text/content strings) — the haystack for explicit-learning +
     * result markers. NEVER tool inputs (those are not learnings).
     *
     * @param  array<string,mixed>  $line
     * @return list<string>
     */
    private function textsFromLine(array $line): array
    {
        $texts = [];

        foreach (['text', 'content', 'prompt'] as $key) {
            $s = $this->string($line[$key] ?? null);
            if ($s !== null) {
                $texts[] = $s;
            }
        }

        foreach ($this->messageContent($line) as $block) {
            if (is_string($block)) {
                $texts[] = $block;
            } elseif (is_array($block) && ($block['type'] ?? '') === 'text') {
                $s = $this->string($block['text'] ?? null);
                if ($s !== null) {
                    $texts[] = $s;
                }
            }
        }

        return $texts;
    }

    /**
     * The user's prompt text on a line, if it carries one (for the request label).
     *
     * @param  array<string,mixed>  $line
     */
    private function userPromptFromLine(array $line): ?string
    {
        $role = (string) ($line['role'] ?? ($line['type'] ?? ''));
        $messageRole = (string) data_get($line, 'message.role', '');
        if ($role !== 'user' && $messageRole !== 'user') {
            return null;
        }

        foreach ($this->textsFromLine($line) as $text) {
            $text = trim($text);
            // Ignore tool-result echoes / system reminders — keep a real human prompt.
            if ($text !== '' && ! str_starts_with($text, '<') && mb_strlen($text) >= 3) {
                return $text;
            }
        }

        return null;
    }

    /**
     * Parse ONE line of free text into an explicit learning, or null. Cite-or-omit:
     * the line must start with a LEARNING marker AND contain at least one evidence ref
     * (file:line or scheme://). No inference from prose.
     *
     * @return array{summary:string, evidence_refs:list<string>}|null
     */
    private function explicitLearning(string $text, int $maxChars): ?array
    {
        foreach (preg_split('/\R/', $text) ?: [$text] as $rawLine) {
            $rawLine = trim((string) $rawLine);
            if ($rawLine === '') {
                continue;
            }
            $marker = null;
            $upper = mb_strtoupper($rawLine);
            foreach (self::LEARNING_MARKERS as $candidate) {
                if (str_starts_with($upper, $candidate)) {
                    $marker = $candidate;
                    break;
                }
            }
            if ($marker === null) {
                continue;
            }
            $body = trim(mb_substr($rawLine, mb_strlen($marker)));
            if ($body === '') {
                continue;
            }
            $refs = $this->evidenceRefs($body);
            if ($refs === []) {
                continue; // cite-or-omit: a marked line with no citation is dropped
            }

            return [
                'summary' => mb_substr($body, 0, $maxChars),
                'evidence_refs' => $refs,
            ];
        }

        return null;
    }

    /**
     * Extract evidence refs (file:line / scheme://) from a learning body. Bounded.
     *
     * @return list<string>
     */
    private function evidenceRefs(string $body): array
    {
        $refs = [];
        if (preg_match_all(self::EVIDENCE_REF_PATTERN, $body, $m) && isset($m[0])) {
            foreach ($m[0] as $ref) {
                $ref = trim((string) $ref);
                // A bare sentence word like "engine." can have a dot — require a path
                // separator, a :line, or a scheme to count as a real citation.
                $isCitation = str_contains($ref, '/')
                    || (bool) preg_match('/:\d+$/', $ref)
                    || str_contains($ref, '://');
                if ($isCitation && ! in_array($ref, $refs, true)) {
                    $refs[] = $ref;
                }
                if (count($refs) >= 10) {
                    break;
                }
            }
        }

        return $refs;
    }

    /**
     * Parse a RESULT marker from free text into a measure: an explicit
     * "ATLAS-RESULT: passed|failed|delivered|blocked" line. Returns null when none.
     *
     * @return array{status?:string, ok?:bool, delivered?:bool}|null
     */
    private function resultFromText(string $text): ?array
    {
        if (! preg_match('/ATLAS[ -]?RESULT:\s*([a-z_]+)/i', $text, $m)) {
            return null;
        }
        $status = strtolower(trim((string) $m[1]));
        $out = ['status' => mb_substr($status, 0, 60)];
        if (in_array($status, ['passed', 'pass', 'green', 'ok', 'success'], true)) {
            $out['ok'] = true;
            $out['delivered'] = true;
        } elseif (in_array($status, ['failed', 'fail', 'red', 'error'], true)) {
            $out['ok'] = false;
        } elseif ($status === 'delivered') {
            $out['delivered'] = true;
        }

        return $out;
    }

    /**
     * @param  list<array{summary:string, evidence_refs:list<string>}>  $learnings
     * @return list<array{summary:string, evidence_refs:list<string>}>
     */
    private function dedupLearnings(array $learnings): array
    {
        $seen = [];
        $out = [];
        foreach ($learnings as $learning) {
            $key = mb_strtolower(trim($learning['summary']));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $learning;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // transcript loading
    // ------------------------------------------------------------------

    /**
     * Load the transcript as a list of decoded JSON line-objects. Prefers an explicit
     * `transcript_lines` (test seam / non-file callers); else reads the JSONL file at
     * `transcript`. A missing/unreadable file is an EMPTY list (fail-safe), never an
     * error — capture degrades to a no-op, never breaks session end.
     *
     * @param  array<string,mixed>  $opts
     * @return list<array<string,mixed>>
     */
    private function loadLines(array $opts): array
    {
        // 1) explicit pre-parsed lines (wins).
        if (isset($opts['transcript_lines']) && is_array($opts['transcript_lines'])) {
            $out = [];
            foreach ($opts['transcript_lines'] as $line) {
                if (is_array($line)) {
                    $out[] = $line;
                } elseif (is_string($line) && trim($line) !== '') {
                    $decoded = json_decode($line, true);
                    if (is_array($decoded)) {
                        $out[] = $decoded;
                    }
                }
            }

            return $out;
        }

        // 2) JSONL transcript file.
        $path = $this->string($opts['transcript'] ?? null);
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        // Cap the bytes read so a pathological transcript can never blow the hot path
        // budget. Read up to the cap, then split on newlines.
        $maxBytes = max(1, (int) config('atlas.aobg.session_capture.max_transcript_bytes', 8_000_000));
        $raw = @file_get_contents($path, false, null, 0, $maxBytes);
        if ($raw === false || $raw === '') {
            return [];
        }

        $out = [];
        $maxLines = max(1, (int) config('atlas.aobg.session_capture.max_transcript_lines', 20_000));
        foreach (preg_split('/\R/', $raw) ?: [] as $rawLine) {
            if (count($out) >= $maxLines) {
                break;
            }
            $rawLine = trim((string) $rawLine);
            if ($rawLine === '' || $rawLine[0] !== '{') {
                continue;
            }
            $decoded = json_decode($rawLine, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // envelopes + receipts + helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function emptyResult(string $workspaceId, string $sessionId, float $startedAt, string $reason): array
    {
        $result = [
            'schema' => self::SCHEMA,
            'fed' => false,
            'session_id' => $sessionId,
            'workspace' => $workspaceId,
            'provider_bound' => true,
            'merged' => false,
            'auto_promoted' => false,
            'outcome' => ['fed' => false, 'mission_node' => null, 'evidence_node' => null, 'reason' => $reason],
            'learnings' => [],
            'counts' => ['touched_files' => 0, 'learnings_found' => 0, 'learnings_fed' => 0],
            'distill' => 'deterministic',
            'reason' => $reason,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'generated_at' => now()->toJSON(),
        ];

        $this->writeReceipt($result);

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function failOpen(float $startedAt, Throwable $e): array
    {
        $result = [
            'schema' => self::SCHEMA,
            'fed' => false,
            'session_id' => '',
            'workspace' => '',
            'provider_bound' => true,
            'merged' => false,
            'auto_promoted' => false,
            'outcome' => ['fed' => false, 'mission_node' => null, 'evidence_node' => null, 'reason' => 'fail_open'],
            'learnings' => [],
            'counts' => ['touched_files' => 0, 'learnings_found' => 0, 'learnings_fed' => 0],
            'distill' => 'deterministic',
            'fail_open' => true,
            'reason' => 'capture_unavailable',
            'exception' => class_basename($e),
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'generated_at' => now()->toJSON(),
        ];

        $this->writeReceipt($result);

        return $result;
    }

    /**
     * Append-only audit receipt for every capture attempt. Best-effort — never affects
     * the decision (mirrors the write-back receipt).
     *
     * @param  array<string,mixed>  $entry
     */
    private function writeReceipt(array $entry): void
    {
        try {
            $base = function_exists('storage_path')
                ? storage_path('atlas/governance')
                : sys_get_temp_dir().'/atlas/governance';
            AppendOnlyJsonlStore::appendUsingFilePutContents(
                $base.DIRECTORY_SEPARATOR.'aobg_session_capture.jsonl',
                array_merge(['schema' => self::SCHEMA, 'recorded_at' => now()->toJSON()], [
                    // identity-only audit (never the raw transcript content).
                    'session_id' => (string) ($entry['session_id'] ?? ''),
                    'workspace' => (string) ($entry['workspace'] ?? ''),
                    'fed' => (bool) ($entry['fed'] ?? false),
                    'counts' => (array) ($entry['counts'] ?? []),
                    'outcome_reason' => (string) data_get($entry, 'outcome.reason', ''),
                ]),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                FILE_APPEND,
            );
        } catch (Throwable) {
            // audit logging is best-effort; the capture decision never depends on it.
        }
    }

    /**
     * The session id (mission node identity): explicit `session_id` wins; else the id
     * the transcript stamped; else a deterministic id derived from the transcript path
     * (so re-capturing the SAME transcript is idempotent on the same node).
     *
     * @param  array<string,mixed>  $opts
     * @param  array{derived_session_id:?string}  $distilled
     */
    private function sessionId(array $opts, array $distilled): string
    {
        $explicit = $this->string($opts['session_id'] ?? null);
        if ($explicit !== null) {
            return mb_substr($explicit, 0, 200);
        }
        if ($distilled['derived_session_id'] !== null) {
            return 'session:'.mb_substr($distilled['derived_session_id'], 0, 192);
        }
        $path = $this->string($opts['transcript'] ?? null);
        if ($path !== null) {
            return 'session:'.substr(hash('sha256', $path), 0, 32);
        }

        return 'session:'.substr(hash('sha256', (string) microtime(true)), 0, 32);
    }

    /**
     * Resolve the workspace id: explicit `workspace` (path or id) wins, else `cwd`,
     * else the primary default. Fail-safe — never throws (mirrors the F1/F2 resolver).
     *
     * @param  array<string,mixed>  $opts
     */
    private function resolveWorkspaceId(array $opts): string
    {
        try {
            $explicit = $this->string($opts['workspace'] ?? null);
            if ($explicit !== null) {
                return $this->workspaceIdentity->resolveWorkspaceOrId($explicit);
            }
            $cwd = $this->string($opts['cwd'] ?? null);
            if ($cwd !== null) {
                return $this->workspaceIdentity->resolve($cwd);
            }

            return $this->workspaceIdentity->default();
        } catch (Throwable) {
            try {
                return $this->workspaceIdentity->default();
            } catch (Throwable) {
                return 'atlas-server';
            }
        }
    }

    /**
     * The content blocks of a transcript line, whichever shape carries them
     * (top-level `content`, or Claude Code's nested `message.content`).
     *
     * @param  array<string,mixed>  $line
     * @return array<int,mixed>
     */
    private function messageContent(array $line): array
    {
        $content = $line['message']['content'] ?? ($line['content'] ?? null);

        return is_array($content) ? array_values($content) : [];
    }

    /**
     * Reduce an absolute path to workspace-relative when under a known root (so the
     * touched-file labels match the brain's relative module keys). Best-effort.
     *
     * @param  array<string,mixed>  $opts
     */
    private function normalizePath(string $path, array $opts): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        $path = preg_replace('#^\./#', '', $path) ?? $path;

        $roots = [];
        foreach (['workspace', 'cwd'] as $key) {
            $candidate = $this->string($opts[$key] ?? null);
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

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
