<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGapClosureSnapshot;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Read-only operator-facing gap-closure snapshot. Exposes
 * {@see AtlasExternalBrainGapClosureSnapshot} as one JSON payload so an operator or another
 * brain can see whether the closed-loop learning cycle and autonomous spine are actually wired
 * before creating more disconnected organs.
 *
 * This is a REPORTER, not an enforcement gate — it always exits SUCCESS so it can run in any
 * pipeline stage without blocking; readiness is communicated via the `ready` field and
 * `recommended_next_action` in the JSON payload, never via exit code.
 *
 * Input: optional --input=PATH JSON file with
 *   {cycles?, spine_sections?, queue_repair_summary?, outcome_feedback_summary?}
 * Missing/absent --input runs the snapshot against empty facts (fail-closed: not ready).
 */
final class AtlasExternalBrainGapClosureCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:external-brain:gap-closure
        {--input= : Path to a JSON file with cycles, spine_sections, queue_repair_summary, outcome_feedback_summary}
        {--json : Emit machine-readable JSON (default output format)}';

    /** @var string */
    protected $description = 'Read-only External Brain gap-closure snapshot (closed-loop learning + autonomous spine + queue repair + outcome feedback).';

    public function handle(AtlasExternalBrainGapClosureSnapshot $snapshotService): int
    {
        $inputPath = trim((string) $this->option('input'));
        $facts = [];

        if ($inputPath !== '') {
            if (! is_file($inputPath)) {
                $this->error('--input path does not exist');

                return self::FAILURE;
            }

            $decoded = json_decode((string) file_get_contents($inputPath), true);
            if (! is_array($decoded)) {
                $this->error('invalid input JSON');

                return self::FAILURE;
            }

            $facts = $decoded;
        }

        $snapshot = $snapshotService->snapshot($facts);

        $payload = [
            'status'                   => 'ok',
            'ready'                    => $snapshot['ready'],
            'missing_links'            => $snapshot['missing_links'],
            'spine_sections'           => $snapshot['spine_sections'],
            'queue_repair_summary'     => $snapshot['queue_repair_summary'],
            'outcome_feedback_summary' => $snapshot['outcome_feedback_summary'],
            'recommended_next_action'  => $snapshot['recommended_next_action'],
        ];

        $this->line($this->encode($payload));

        return self::SUCCESS;
    }
}
