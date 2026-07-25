<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Cognition\AtlasSurpriseGateService;
use App\Services\Ai\Obra\AtlasObraStateService;
use App\Services\Ai\OpenBrainContextInjection\TextNormalizeSupport;
use App\Services\Ai\PersistentContext\AtlasPersistentContextRuntimeService;
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

    /**
     * WO-17-T2 — the DELTA-DE-SURPRESA marker: "what the pack did NOT have and the
     * session discovered". An explicitly-marked surprise is a HIGH-priority G0
     * candidate (the pack failed to carry it — the most valuable thing to memorise).
     */
    private const SURPRISE_MARKERS = ['ATLAS-SURPRISE:', 'ATLAS SURPRISE:', 'SURPRISE:'];

    /**
     * T4-S2 — the pre-session BET markers: the context the brain surfaced during the
     * session (the pack the session started with + per-file brain notes, injected via
     * the hooks' additionalContext). A learning whose salient tokens are already
     * covered by this text is "predicted" — the pack knew it — and the surprise gate
     * suppresses it as a G0 candidate. Marker-based so it matches the real transcript.
     */
    private const PREDICTION_MARKERS = ['Atlas Open Brain Context Pack', 'Context Pack (AOBG)', 'Atlas brain —', '(AOBG)'];

    /** A cited evidence ref is a file path with a :line or a known scheme (foo://bar). */
    private const EVIDENCE_REF_PATTERN = '#(?:[A-Za-z0-9_./\\\\-]+\.[A-Za-z0-9]+(?::\d+)?|[a-z][a-z0-9_+.-]*://[^\s]+)#';

    public function __construct(
        private readonly AtlasOpenBrainWriteBackService $writeBack,
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
        private readonly AtlasSurpriseGateService $surpriseGate,
        private readonly AtlasOpenBrainContextInjectionBoundaryClassifier $injectionBoundaryClassifier,
    ) {}

    /**
     * Distil a session and feed the brain through the governed write-back.
     *
     * @param  array<string,mixed>  $opts  optional:
     *                                     - transcript: absolute path to the session transcript JSONL (Claude Code shape).
     *                                     - transcript_lines: list<array<string,mixed>>|list<string> already-parsed lines
     *                                     (test seam / non-file callers) — wins over `transcript`.
     *                                     - session_id: explicit session id (the mission node identity); else derived.
     *                                     - request: a label for what the session was asked to do; else derived.
     *                                     - workspace / cwd: scope (resolved + recorded for audit, never leaked).
     *                                     - provider: provider/agent label (e.g. claude-code).
     *                                     - max_learnings: cap on proposed learnings fed from one session.
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
            if ($distilled['files'] === [] && $distilled['result'] === null && $distilled['learnings'] === [] && ($distilled['surprises'] ?? []) === []) {
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
                    'evidence_refs' => array_map(
                        static fn (string $path): string => 'file:'.$path,
                        $distilled['files'],
                    ),
                    'branch' => $this->string($opts['branch'] ?? null) ?? '',
                    'provider' => $provider,
                    'delivered' => $distilled['result']['delivered'] ?? false,
                    'result' => $distilled['result'] ?? [],
                    'workspace' => $workspaceId,
                ]);
            }

            // 2) LEARNINGS — each explicit, file-cited learning → a `proposed` learning
            //    via the same governed pipeline (capture quality gate + never-apply).
            //    T4-S2 — the SURPRISE GATE runs first: a learning already covered by the
            //    pre-session bet (the pack the session started with) is "predicted" and is
            //    SUPPRESSED — "o previsto quase não grava". Only surprising learnings (new
            //    information the pack lacked) become G0 candidates. Fail-open: with no bet
            //    carried (gated=false) every learning passes, byte-identical to before.
            $prediction = (string) ($distilled['prediction'] ?? '');
            $learnings = [];
            $suppressed = [];
            $gatedAny = false;
            $candidatesConsidered = 0;
            foreach (array_slice($distilled['learnings'], 0, $maxLearnings) as $learning) {
                $candidatesConsidered++;
                $verdict = $this->surpriseGate->evaluate(
                    trim(($learning['claim'] ?? '').' '.$learning['summary']),
                    $prediction,
                );
                $gatedAny = $gatedAny || $verdict['gated'];
                if (! $verdict['record']) {
                    // Predicted — audited (reversible, in the receipt below), not fed to G0.
                    $suppressed[] = [
                        'surprise' => $verdict['surprise'],
                        'summary' => mb_substr($learning['summary'], 0, 120),
                    ];

                    continue;
                }
                $learnings[] = $this->writeBack->proposeLearning([
                    'kind' => 'memory',
                    'summary' => $learning['summary'],
                    'evidence_refs' => $learning['evidence_refs'],
                    'scope' => 'global',
                    'provider' => $provider,
                    'workspace' => $workspaceId,
                    // D2 — the STRUCTURED learning rides in the payload so the brain
                    // stores claim + porquê + arquivos + evidence, not a flat blob.
                    'payload' => [
                        'source' => 'aobg_session_capture',
                        'session_id' => $sessionId,
                        // T4-S2 — the measured surprise the candidate was admitted with.
                        'surprise' => $verdict['surprise'],
                        'surprise_priority' => $verdict['priority'],
                        'structured' => [
                            'claim' => $learning['claim'],
                            'why' => $learning['why'],
                            'files' => $learning['files'],
                            'evidence' => $learning['evidence_refs'],
                        ],
                    ],
                ]);
            }

            $outcomeFed = (bool) ($outcome['ok'] ?? false);
            $learningsFed = 0;
            foreach ($learnings as $l) {
                if (($l['ok'] ?? false) === true) {
                    $learningsFed++;
                }
            }

            // D2 — fill the APCR post_execution_update from the session's structured
            // learnings when this session carries a persistent context pack.
            $apcr = $this->updateApcr($opts, $workspaceId, $sessionId, $distilled['learnings']);

            // WO-17-T1 — feed the ACTIVE obra's state with this session's footprint, so
            // the next session can resume ("você tocou X, provou Y"). No-op unless an
            // obra is explicitly active; fail-open so it never breaks capture.
            $obraState = $this->recordObraState($sessionId, $distilled);

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
                'apcr' => $apcr,
                'obra_state' => $obraState,
                // WO-17-T2 — delta de surpresa: structured G0 candidates (what the pack lacked).
                'surprise_delta' => array_values((array) ($distilled['surprises'] ?? [])),
                'injection_boundary' => $this->injectionBoundarySummary((array) ($distilled['injection_boundary'] ?? [])),
                // T4-S2 — surprise gate audit: how many predicted candidates were
                // suppressed. The -≥40% is MEASURED here over real sessions, never tuned.
                'surprise_gate' => [
                    'gated' => $gatedAny,
                    'threshold' => (float) config('atlas.aobg.surprise_gate.threshold', AtlasSurpriseGateService::DEFAULT_THRESHOLD),
                    'candidates_before' => $candidatesConsidered,
                    'candidates_fed' => count($learnings),
                    'suppressed_predicted' => count($suppressed),
                    'suppressed' => $suppressed,
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

    /**
     * WO-17-T1 — record this session's footprint into the ACTIVE obra's state file so
     * the next session resumes. No-op unless an obra is explicitly active (never
     * inferred). Own try/catch: an obra-state fault must not lose the capture result.
     *
     * @param  array<string,mixed>  $distilled
     * @return array<string,mixed>
     */
    private function recordObraState(string $sessionId, array $distilled): array
    {
        try {
            $svc = app(AtlasObraStateService::class);
            $id = $svc->currentId();
            if ($id === null) {
                return ['updated' => false, 'reason' => 'no_active_obra'];
            }
            $svc->recordSession($id, [
                'session_id' => $sessionId,
                'files' => $distilled['files'] ?? [],
                'result' => $distilled['result'] ?? null,
                'request' => $distilled['request'] ?? '',
            ]);

            return ['updated' => true, 'obra_id' => $id];
        } catch (Throwable) {
            return ['updated' => false, 'reason' => 'fault'];
        }
    }

    /**
     * D2 — fill the APCR post_execution_update from a captured session's structured
     * learnings. Opt-in: only when `persistent_context_pack_id` is supplied. Delegates
     * to the existing governed {@see AtlasPersistentContextRuntimeService::recordOutcome()},
     * which creates a PENDING memory delta (requires_confirmation, never auto-promoted)
     * — the same floor as the rest of capture. Fail-open.
     *
     * @param  array<string,mixed>  $opts
     * @param  list<array{summary:string,evidence_refs:list<string>,claim:string,why:string,files:list<string>}>  $learnings
     * @return array<string,mixed>
     */
    private function updateApcr(array $opts, string $workspaceId, string $sessionId, array $learnings): array
    {
        $packId = $this->string($opts['persistent_context_pack_id'] ?? null);
        if ($packId === null || $learnings === []) {
            return ['fed' => false, 'reason' => $packId === null ? 'no_apcr_pack' : 'no_learnings'];
        }

        try {
            $refs = [];
            foreach ($learnings as $learning) {
                foreach ($learning['evidence_refs'] as $ref) {
                    if (! in_array($ref, $refs, true)) {
                        $refs[] = $ref;
                    }
                }
            }
            $primary = $learnings[0];
            $receipt = app(AtlasPersistentContextRuntimeService::class)->recordOutcome(
                [
                    'persistent_context_pack_id' => $packId,
                    'scope' => ['workspace' => $workspaceId, 'scope_type' => 'workspace', 'scope_id' => $workspaceId],
                    'evidence_refs' => $refs,
                ],
                [
                    'claim' => $primary['claim'] !== '' ? $primary['claim'] : $primary['summary'],
                    'summary' => $primary['summary'],
                    'evidence_refs' => $refs,
                    'session_id' => $sessionId,
                    'memory_type' => 'technical_context',
                ],
            );

            return [
                'fed' => (string) ($receipt['status'] ?? '') === 'recorded',
                'status' => (string) ($receipt['status'] ?? ''),
                'post_execution_update_hash' => $receipt['post_execution_update_hash'] ?? null,
            ];
        } catch (Throwable) {
            return ['fed' => false, 'reason' => 'apcr_unavailable'];
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
     * @return array{files:list<string>, result:array{status?:string,ok?:bool,delivered?:bool}|null, learnings:list<array{summary:string,evidence_refs:list<string>,claim:string,why:string,files:list<string>}>, surprises:list<string>, prediction:string, injection_boundary:list<array<string,mixed>>, request:string, derived_session_id:?string}
     */
    private function distil(array $lines, array $opts): array
    {
        $maxFiles = max(1, (int) config('atlas.aobg.session_capture.max_files', 50));
        $maxLearningChars = max(1, (int) config('atlas.aobg.session_capture.max_learning_chars', 600));
        $maxPredictionChars = max(0, (int) config('atlas.aobg.surprise_gate.max_prediction_chars', 20000));

        $files = [];
        $learnings = [];
        $surprises = [];
        $result = null;
        $firstUserPrompt = null;
        $derivedSessionId = null;
        $prediction = ''; // T4-S2 — the pre-session bet accreted from AOBG-marked text.
        $injectionBoundary = [];

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
            foreach ($this->textSegmentsFromLine($line) as $segment) {
                $text = (string) $segment['text'];
                if (count($injectionBoundary) < 64) {
                    $injectionBoundary[] = $this->classifyInjectionBoundarySegment($text, (string) $segment['source']);
                }
                $learning = $this->explicitLearning($text, $maxLearningChars);
                if ($learning !== null && count($learnings) < 64) {
                    $learnings[] = $learning;
                }
                $resultFromText = $this->resultFromText($text);
                if ($resultFromText !== null) {
                    $result = $resultFromText; // last marker wins (the final state)
                }
                // WO-17-T2 — delta de surpresa: what the pack didn't have (G0 candidate).
                $surprise = $this->markedText($text, self::SURPRISE_MARKERS, $maxLearningChars);
                if ($surprise !== null && count($surprises) < 16 && ! in_array($surprise, $surprises, true)) {
                    $surprises[] = $surprise;
                }
                // T4-S2 — accrete the pre-session bet: any AOBG-marked context the brain
                // surfaced this session is the prediction each learning is judged against.
                if ($maxPredictionChars > 0 && mb_strlen($prediction) < $maxPredictionChars && $this->looksLikePrediction($text)) {
                    $prediction .= "\n".mb_substr($text, 0, $maxPredictionChars - mb_strlen($prediction));
                }
            }
        }

        // Dedup learnings by summary (a marker repeated across turns is one learning).
        $learnings = $this->dedupLearnings($learnings);

        $request = $this->string($opts['request'] ?? null)
            ?? ($firstUserPrompt !== null ? mb_substr($firstUserPrompt, 0, 280) : 'session capture');

        return [
            'files' => $files,
            'result' => $result,
            'learnings' => $learnings,
            'surprises' => $surprises,
            'prediction' => trim($prediction),
            'injection_boundary' => $injectionBoundary,
            'request' => $request,
            'derived_session_id' => $derivedSessionId,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $segments
     * @return array<string,mixed>
     */
    private function injectionBoundarySummary(array $segments): array
    {
        $byClassification = [];
        $nonDirective = 0;
        foreach ($segments as $segment) {
            $classification = (string) ($segment['classification'] ?? 'unknown');
            $byClassification[$classification] = ($byClassification[$classification] ?? 0) + 1;
            if (($segment['allow_as_worker_directive'] ?? true) !== true) {
                $nonDirective++;
            }
        }
        ksort($byClassification);

        return [
            'schema' => AtlasOpenBrainContextInjectionBoundaryClassifier::SCHEMA,
            'segments_classified' => count($segments),
            'non_directive_segments' => $nonDirective,
            'by_classification' => $byClassification,
            'segments' => $segments,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function classifyInjectionBoundarySegment(string $text, string $source): array
    {
        $verdict = $this->injectionBoundaryClassifier->classify([
            'text' => $text,
            'source' => $source,
        ]);

        return [
            'text_hash' => hash('sha256', $text),
            'source' => $source,
            'classification' => (string) $verdict['classification'],
            'allow_as_worker_directive' => (bool) $verdict['allow_as_worker_directive'],
            'reason' => (string) $verdict['reason'],
            'confidence' => (float) $verdict['confidence'],
        ];
    }

    /**
     * T4-S2 — does this transcript text carry AOBG context (the pre-session bet)?
     * A text block that surfaced brain context is prediction, not a learning.
     */
    private function looksLikePrediction(string $text): bool
    {
        foreach (self::PREDICTION_MARKERS as $marker) {
            if (stripos($text, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * WO-17-T2 — extract the text after any of $markers (marker-only, no file-cite:
     * a surprise is "the pack lacked this", not a code claim). Returns null when absent.
     *
     * @param  list<string>  $markers
     */
    private function markedText(string $text, array $markers, int $maxChars): ?string
    {
        foreach (preg_split('/\R/', $text) ?: [] as $rawLine) {
            $line = trim($rawLine);
            foreach ($markers as $marker) {
                $pos = stripos($line, $marker);
                if ($pos !== false) {
                    $body = trim(mb_substr($line, $pos + strlen($marker)));
                    if ($body !== '') {
                        return mb_substr($body, 0, $maxChars);
                    }
                }
            }
        }

        return null;
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
        $messageContent = $this->string(data_get($line, 'message.content'));
        if ($messageContent !== null) {
            $texts[] = $messageContent;
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
     * @param  array<string,mixed>  $line
     * @return list<array{text:string,source:string}>
     */
    private function textSegmentsFromLine(array $line): array
    {
        $segments = [];
        foreach ($this->textsFromLine($line) as $text) {
            $segments[] = [
                'text' => $text,
                'source' => $this->injectionBoundarySource($line, $text),
            ];
        }

        return $segments;
    }

    /**
     * @param  array<string,mixed>  $line
     */
    private function injectionBoundarySource(array $line, string $text): string
    {
        $explicit = $this->string($line['context_source'] ?? ($line['segment_source'] ?? null));
        if ($explicit !== null && in_array($explicit, ['current_turn', 'task_contract', 'memory', 'excerpt', 'quoted_memory', 'example', 'summary', 'unknown'], true)) {
            return $explicit;
        }

        if ($this->looksLikePrediction($text)) {
            return 'excerpt';
        }

        $role = (string) ($line['role'] ?? ($line['type'] ?? ''));
        $messageRole = (string) data_get($line, 'message.role', '');
        if ($role === 'user' || $messageRole === 'user') {
            return 'current_turn';
        }

        return 'summary';
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
     * @return array{summary:string, evidence_refs:list<string>, claim:string, why:string, files:list<string>}|null
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

            // D2 — STRUCTURED learning: split the body into claim / porquê / arquivos
            // so the brain stores a reasoned learning, not a flat blob. `summary` and
            // `evidence_refs` are UNCHANGED (byte-compat for the governed write-back +
            // its quality gate); claim/why/files are additive.
            [$claim, $why, $files] = $this->splitStructuredLearning($body);
            if ($files === []) {
                $files = array_values(array_filter(
                    $refs,
                    static fn (string $r): bool => str_contains($r, '/') || (bool) preg_match('/:\d+$/', $r),
                ));
            }

            return [
                'summary' => mb_substr($body, 0, $maxChars),
                'evidence_refs' => $refs,
                'claim' => mb_substr($claim, 0, $maxChars),
                'why' => mb_substr($why, 0, $maxChars),
                'files' => $files,
            ];
        }

        return null;
    }

    /**
     * D2 — split a learning body into [claim, porquê, arquivos]. Optional inline
     * markers: `<claim> WHY:/PORQUE: <why> FILES:/ARQUIVOS: a.php, b.php`. Absent
     * markers ⇒ claim = whole body, why = '', files = []. Deterministic, no LLM.
     *
     * @return array{0:string,1:string,2:list<string>}
     */
    private function splitStructuredLearning(string $body): array
    {
        $filesRaw = '';
        // Peel a trailing FILES:/ARQUIVOS: segment off the end first.
        if (preg_match('/(.*?)\b(?:FILES|ARQUIVOS)\s*:\s*(.*)$/isu', $body, $m)) {
            $body = trim($m[1]);
            $filesRaw = $m[2];
        }

        $claim = trim($body);
        $why = '';
        if (preg_match('/(.*?)\b(?:WHY|PORQU[EÊ])\s*:\s*(.*)$/isu', $body, $m)) {
            $claim = trim($m[1]);
            $why = trim($m[2]);
        }

        return [$claim !== '' ? $claim : trim($body), $why, $this->splitFiles($filesRaw)];
    }

    /**
     * @return list<string>
     */
    private function splitFiles(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $token) {
            $token = trim((string) $token);
            if ($token !== '' && (str_contains($token, '/') || str_contains($token, '.')) && ! in_array($token, $out, true)) {
                $out[] = $token;
            }
            if (count($out) >= 20) {
                break;
            }
        }

        return $out;
    }

    /**
     * Extract evidence refs (file:line / scheme://) from a learning body. Bounded.
     *
     * @return list<string>
     */
    private function evidenceRefs(string $body): array
    {
        $refs = [];
        if (preg_match_all(self::EVIDENCE_REF_PATTERN, $body, $m)) {
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
     * @param  list<array{summary:string, evidence_refs:list<string>, claim:string, why:string, files:list<string>}>  $learnings
     * @return list<array{summary:string, evidence_refs:list<string>, claim:string, why:string, files:list<string>}>
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
                    // T4-S2 — counts only (never content) so the -≥40% G0-candidate drop
                    // is auditable from this ledger over real 30-day usage.
                    'surprise_gate' => array_intersect_key(
                        (array) ($entry['surprise_gate'] ?? []),
                        array_flip(['gated', 'threshold', 'candidates_before', 'candidates_fed', 'suppressed_predicted']),
                    ),
                ]),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
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
        return TextNormalizeSupport::nullableString($value);
    }
}
