<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLoopCampaign;
use Illuminate\Console\Command;

/**
 * ARBOR-GRAFT S1 — live operator steering (Arbor's [user note] / SYSTEM CONTROL redirect, without killing
 * the run). Writes an ADVISORY operator note onto the campaign; the next discovery cycle folds it into the
 * constraints-block (CB1) tagged provenance=operator_note — distinct from a machine-certified finding.
 *
 * FLOOR-SAFE: the note is operator-authored ADVISORY text that reaches ONLY the generation prompt (via the
 * flag-gated W1b/CB1 path). It NEVER touches the frozen judge, the certifier or a trust ladder, never kills
 * the run, and never changes the budget. Setting/clearing a note is idempotent and reversible.
 */
final class AtlasLoopSteerCommand extends Command
{
    protected $signature = 'atlas:loop:steer {campaign : campaign id} {note? : advisory note (omit/empty to clear)}';

    protected $description = 'ARBOR-GRAFT S1 — set an advisory operator steering note on a running loop campaign (never kills, never gates).';

    public function handle(): int
    {
        $campaign = AtlasLoopCampaign::query()->find((string) $this->argument('campaign'));
        if ($campaign === null) {
            $this->error('campaign not found');

            return self::FAILURE;
        }

        $note = trim((string) ($this->argument('note') ?? ''));
        $config = is_array($campaign->config) ? $campaign->config : [];

        if ($note === '') {
            unset($config['steering_note']);
            $campaign->config = $config;
            $campaign->save();
            $this->info('steering note cleared');

            return self::SUCCESS;
        }

        // Bound + sanitize to pure text so an operator note can never smuggle a directive into the packet.
        $note = preg_replace('/\s+/', ' ', $note) ?? $note;
        if (strlen($note) > 500) {
            $note = substr($note, 0, 500);
        }
        $config['steering_note'] = $note;
        $campaign->config = $config;
        $campaign->save();

        $this->info('steering note set (advisory; folded into the next discovery cycle as operator_note)');

        return self::SUCCESS;
    }
}
