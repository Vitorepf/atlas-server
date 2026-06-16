<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationOutcomeRecorder;
use Illuminate\Console\Command;

/**
 * ACDE O2 — the operator's ACCEPT/REJECT on an O1 origination proposal (the only ground truth for origination
 * quality). Records the decision keyed by the proposal's shape token so the O1 producer backs off shapes the
 * operator keeps rejecting. Human-only; no autopilot path feeds this.
 */
class AtlasLoopOriginationReviewCommand extends Command
{
    protected $signature = 'atlas:loop:origination-review
        {proposal : the origination proposal id to decide on}
        {--accept : record the operator ACCEPTING this origination shape}
        {--reject : record the operator REJECTING this origination shape}
        {--json : canonical JSON output}';

    protected $description = 'Record the operator accept/reject on an O1 origination proposal (sharpens the next origination).';

    public function handle(AtlasLoopOriginationOutcomeRecorder $recorder): int
    {
        $accept = (bool) $this->option('accept');
        $reject = (bool) $this->option('reject');
        if ($accept === $reject) {
            $this->error('exactly one of --accept or --reject is required.');

            return self::FAILURE;
        }

        $proposal = AtlasLoopProposal::query()->find((string) $this->argument('proposal'));
        if ($proposal === null) {
            $this->error('origination proposal not found: '.$this->argument('proposal'));

            return self::FAILURE;
        }

        $quality = is_array($proposal->quality) ? $proposal->quality : [];
        $criteriaCount = (int) (data_get($quality, '_origination.criteria_count') ?? 0);
        $token = $recorder->shapeToken((string) $proposal->target_path, $criteriaCount);
        $recorder->record($token, $accept, (string) $proposal->id, (string) $proposal->target_path);

        $payload = ['recorded' => true, 'accepted' => $accept, 'shape_token' => $token, 'proposal_id' => (string) $proposal->id];
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info(($accept ? 'ACCEPTED' : 'REJECTED').' origination '.$proposal->id.' (shape '.substr($token, 0, 10).'…) — the producer will '.($accept ? 'keep' : 'back off').' this shape.');

        return self::SUCCESS;
    }
}
