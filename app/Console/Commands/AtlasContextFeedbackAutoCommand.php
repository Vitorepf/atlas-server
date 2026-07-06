<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasRetrievalFeedbackLoopService;
use Illuminate\Console\Command;
use Throwable;

/**
 * ARFL->ACRS closed loop, capture half (Obra #13 item 4).
 *
 * Distils a finished session transcript into ONE aggregated persisted
 * retrieval-feedback event: it extracts the `context_pack_hash=<16 hex>`
 * markers the UserPromptSubmit hook injected and records them through
 * AtlasRetrievalFeedbackLoopService::capture(record=true). Delivered/used
 * refs are unknown from a transcript, so they are omitted by contract.
 *
 * Fail-open TOTAL: any fault (missing/unreadable transcript, no hashes,
 * missing table, capture exception) exits 0 with a note — this runs from the
 * session-end hook and must never stall or fail session end.
 */
final class AtlasContextFeedbackAutoCommand extends Command
{
    protected $signature = 'atlas:context:feedback-auto
        {--transcript= : Path to the session transcript file}
        {--outcome=unknown : Session outcome status (passed|partial|failed|unknown)}
        {--json : Emit JSON}';

    protected $description = 'Close the ARFL->ACRS loop: record one aggregated retrieval-feedback event from the context_pack_hash markers in a session transcript (fail-open).';

    public function handle(): int
    {
        $result = [
            'status' => 'skipped',
            'note' => null,
            'context_pack_hashes' => [],
            'persisted' => false,
        ];

        try {
            $transcript = trim((string) $this->option('transcript'));
            if ($transcript === '' || ! is_file($transcript) || ! is_readable($transcript)) {
                $result['note'] = 'transcript_missing_or_unreadable';

                return $this->emit($result);
            }

            preg_match_all(
                '/context_pack_hash=([0-9a-f]{16})/',
                (string) file_get_contents($transcript),
                $matches,
            );
            $hashes = array_values(array_unique($matches[1] ?? []));
            $result['context_pack_hashes'] = $hashes;
            if ($hashes === []) {
                $result['note'] = 'no_context_pack_hash_in_transcript';

                return $this->emit($result);
            }

            $capture = app(AtlasRetrievalFeedbackLoopService::class)->capture([
                'record' => true,
                'outcome_status' => (string) ($this->option('outcome') ?: 'unknown'),
                'flow_id' => 'claude.session.auto',
                // One event per session: the receipt id is derived from the hash set.
                'retrieval_receipt_id' => 'session-auto-'.sha1(implode(',', $hashes)),
                'context_pack_hashes' => $hashes,
            ]);

            $result['status'] = 'captured';
            $result['persisted'] = (bool) data_get($capture, 'persistence.persisted', false);
            $result['rag_feedback_id'] = data_get($capture, 'persistence.rag_feedback_id');
        } catch (Throwable $exception) {
            $result['note'] = 'fail_open: '.$exception->getMessage();
        }

        return $this->emit($result);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function emit(array $result): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line((string) ($result['note'] ?? $result['status']));
        }

        return self::SUCCESS;
    }
}
