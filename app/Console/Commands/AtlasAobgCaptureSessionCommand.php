<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainSessionCaptureService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AOBG N2.F3 — `atlas:aobg:capture-session`: STRUCTURAL capture.
 *
 * The session feeds the brain AUTOMATICALLY (not voluntarily): given a session
 * TRANSCRIPT, it DETERMINISTICALLY distils what the session did (the files it
 * touched + the test/measure result + any EXPLICIT, file-cited learnings) and feeds
 * them INTO the brain THROUGH the governed write-back — a provider-safe mission/
 * evidence node (never a merge) + `proposed` learnings awaiting human review (the
 * capture quality gate rejects noise; nothing auto-applies). Built on
 * {@see AtlasOpenBrainSessionCaptureService}.
 *
 *     atlas:aobg:capture-session --transcript=/path/to/session.jsonl --json
 *     atlas:aobg:capture-session --transcript="$T" --workspace="$(pwd)" --provider=claude-code
 *
 * NO provider / LLM call by default (pure transcript parsing) — ZERO provider spend,
 * local DB only. ALWAYS exits 0 — a nothing-to-capture / store outage / fail-open is a
 * normal, audited answer (a Stop/SessionEnd hook can never be broken by a non-zero
 * exit and must never stall session end).
 */
class AtlasAobgCaptureSessionCommand extends Command
{
    use EmitsCanonicalJson;

    public const SCHEMA = 'atlas.aobg.capture_session_command.v1';

    protected $signature = 'atlas:aobg:capture-session
        {--transcript= : Absolute path to the session transcript JSONL (Claude Code shape)}
        {--session-id= : Explicit session id (the brain node identity); else derived from the transcript}
        {--request= : Label for what the session was asked to do; else derived from the first user prompt}
        {--branch= : Branch ref (never a merge)}
        {--provider= : Provider/agent label (e.g. claude-code)}
        {--workspace= : Workspace path or id to scope the capture (defaults to the primary atlas-server)}
        {--json : Output the capture envelope as JSON instead of a rendered summary}';

    protected $description = 'AOBG N2.F3: STRUCTURAL capture — distil a session transcript (touched files + outcome + explicit learnings) and feed it into the brain via the governed write-back (provider-safe node, never a merge; learnings land pending_review, never auto-applied). Deterministic, cost-free, fail-OPEN (exit 0).';

    public function handle(AtlasOpenBrainSessionCaptureService $service): int
    {
        $opts = [];
        foreach ([
            'transcript' => 'transcript',
            'session-id' => 'session_id',
            'request' => 'request',
            'branch' => 'branch',
            'provider' => 'provider',
            'workspace' => 'workspace',
        ] as $optKey => $argKey) {
            $value = $this->option($optKey);
            if (is_string($value) && trim($value) !== '') {
                $opts[$argKey] = trim($value);
            }
        }

        $result = $service->captureSession($opts);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return self::SUCCESS;
        }

        $counts = (array) ($result['counts'] ?? []);
        if (($result['fed'] ?? false) === true) {
            $this->info(sprintf(
                'captured  session=%s  workspace=%s  outcome=%s  learnings_fed=%d/%d  touched=%d  merged=no  %dms',
                (string) ($result['session_id'] ?? ''),
                (string) ($result['workspace'] ?? ''),
                ($result['outcome']['fed'] ?? false) ? 'recorded' : 'skipped',
                (int) ($counts['learnings_fed'] ?? 0),
                (int) ($counts['learnings_found'] ?? 0),
                (int) ($counts['touched_files'] ?? 0),
                (int) ($result['elapsed_ms'] ?? 0),
            ));
        } else {
            $this->warn(sprintf(
                'nothing fed  session=%s  reason=%s  touched=%d  learnings_found=%d',
                (string) ($result['session_id'] ?? ''),
                (string) ($result['reason'] ?? data_get($result, 'outcome.reason', 'n/a')),
                (int) ($counts['touched_files'] ?? 0),
                (int) ($counts['learnings_found'] ?? 0),
            ));
        }

        return self::SUCCESS;
    }
}
