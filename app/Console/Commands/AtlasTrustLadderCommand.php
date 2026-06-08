<?php

namespace App\Console\Commands;

use App\Services\Ai\Governance\AtlasChangeClassTrustLadder;
use Illuminate\Console\Command;

/**
 * Inspect or accrue the Self-Construction trust ladder for a change class: record a
 * re-checkable evidence (frozen-judge pass / clean promotion / revert) and snapshot
 * the autonomy the class has earned. Admission re-binds this under the risk cap;
 * nothing here can exceed it.
 */
class AtlasTrustLadderCommand extends Command
{
    protected $signature = 'atlas:ai:trust-ladder
        {change_class : The change class to inspect/accrue}
        {--record= : Record an evidence: frozen_judge_pass | clean_promotion | revert}
        {--ref= : Optional evidence reference (e.g. a promotion receipt hash)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect or accrue the Self-Construction trust ladder for a change class (governed, bounded by the risk cap).';

    public function handle(AtlasChangeClassTrustLadder $ladder): int
    {
        $class = (string) $this->argument('change_class');
        $record = (string) ($this->option('record') ?? '');

        if ($record !== '') {
            $valid = [
                AtlasChangeClassTrustLadder::EVIDENCE_FROZEN_JUDGE_PASS,
                AtlasChangeClassTrustLadder::EVIDENCE_CLEAN_PROMOTION,
                AtlasChangeClassTrustLadder::EVIDENCE_REVERT,
            ];
            if (! in_array($record, $valid, true)) {
                $this->error('Unknown evidence kind: '.$record.' (allowed: '.implode(' | ', $valid).')');

                return self::FAILURE;
            }
            $ladder->recordEvidence($class, $record, ($this->option('ref') ?: null));
        }

        $snapshot = $ladder->snapshot($class);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Trust ladder — change class "'.$class.'":');
        $this->table(['field', 'value'], [
            ['clean_streak', (string) $snapshot['clean_streak']],
            ['earned_autonomy', (string) $snapshot['earned_autonomy']],
        ]);
        $this->line('Earned autonomy is re-bound under the risk-canon cap by admission. Default (no operator thresholds) = suggest (max friction).');

        return self::SUCCESS;
    }
}
