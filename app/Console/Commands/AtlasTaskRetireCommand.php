<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;

/**
 * Retires repeated-give-back quarantined task-serving packets.
 *
 * The orchestrator's repairScopeBlockedTasks bails these as UNREPAIRABLE
 * (reason 'repeated_give_back_quarantine_requires_respec') — they stay blocked
 * forever, draining the serving queue. This command transitions blocked→cancelled
 * for DOOMED packets only (give_back_count>=7 AND reason/defficiency matches the
 * orchestrator's isRepeatedGiveBackQuarantine detection). It never touches any
 * other status. --dry-run plans without mutating.
 */
class AtlasTaskRetireCommand extends Command
{
    protected $signature = 'atlas:task:retire
        {--dry-run : Inspect and report only; do not mutate}
        {--limit=0 : Maximum doomed packets to retire; 0 means no cap}
        {--actor=task_retire : actor label recorded in queue metadata/receipts}
        {--json : Print machine-readable JSON}';

    protected $description = 'Retire repeated-give-back quarantined blocked packets (blocked→cancelled).';

    public function handle(): int
    {
        $queue = AtlasTaskServingStack::queueRepo();
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $actor = (string) $this->option('actor');

        $blocked = $queue->list(['status' => 'blocked']);

        $doomed = [];
        $skipped = [];
        foreach ($blocked as $record) {
            $taskPacketId = (string) ($record['task_packet_id'] ?? '');
            if ($taskPacketId === '') {
                continue;
            }

            if ($this->isRepeatedGiveBackQuarantine($record)) {
                $doomed[] = ['record' => $record, 'doom_kind' => 'repeated_give_back'];
            } elseif ($this->isDormantCliArmProxy($record)) {
                $doomed[] = ['record' => $record, 'doom_kind' => 'dormant_cli_arm_proxy'];
            } else {
                $skipped[] = $taskPacketId;
            }

            if ($limit > 0 && count($doomed) >= $limit) {
                break;
            }
        }

        $retired = [];
        $failed = [];
        foreach ($doomed as $doomedEntry) {
            $record = $doomedEntry['record'];
            $doomKind = (string) $doomedEntry['doom_kind'];
            $taskPacketId = (string) ($record['task_packet_id'] ?? '');

            $isDormantProxy = $doomKind === 'dormant_cli_arm_proxy';
            $retireReason = $isDormantProxy ? 'retired_dormant_cli_arm_proxy_doomed' : 'retired_repeated_give_back_doomed';
            $receiptKind = $isDormantProxy ? 'blocked_packet_retired_dormant_cli_arm_proxy' : 'blocked_packet_retired_repeated_give_back';

            if ($dryRun) {
                $retired[] = ['task_packet_id' => $taskPacketId, 'action' => 'retire_plan', 'doom_kind' => $doomKind];

                continue;
            }

            $t = $queue->updateStatus($taskPacketId, 'cancelled', [
                'reason' => $retireReason,
                'agent_id' => $actor,
            ]);

            if ((string) ($t['status'] ?? '') === 'ok') {
                $queue->appendReceipt($taskPacketId, [
                    'receipt_kind' => $receiptKind,
                    'agent_id' => $actor,
                    'reason' => $retireReason,
                ]);
                $retired[] = ['task_packet_id' => $taskPacketId, 'action' => 'retired', 'doom_kind' => $doomKind];
            } else {
                $failed[] = ['task_packet_id' => $taskPacketId, 'status' => (string) ($t['status'] ?? 'unknown'), 'reason' => (string) ($t['reason'] ?? '')];
            }
        }

        $result = [
            'dry_run' => $dryRun,
            'inspected_blocked' => count($blocked),
            'doomed_count' => count($doomed),
            'retired_count' => count($retired),
            'failed_count' => count($failed),
            'skipped_count' => count($skipped),
            'retired' => $retired,
            'failed' => $failed,
            'skipped' => $skipped,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  <fg=cyan>TASK-SERVING RETIRE</>  dry_run='.($dryRun ? 'yes' : 'no'));
        $this->line('  inspected='.$result['inspected_blocked'].'  doomed='.$result['doomed_count'].'  retired='.$result['retired_count'].'  failed='.$result['failed_count'].'  skipped='.$result['skipped_count']);
        foreach (array_slice($retired, 0, 30) as $item) {
            $this->line('    - '.($item['action'] ?? '?').'  '.($item['task_packet_id'] ?? '?'));
        }
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Replicates AgentControlPlaneTaskQueueOrchestrator::isRepeatedGiveBackQuarantine
     * (read-only detection — does NOT edit the orchestrator).
     *
     * @param  array<string, mixed>  $record
     */
    private function isRepeatedGiveBackQuarantine(array $record): bool
    {
        $giveBackCount = (int) data_get($record, 'metadata.give_back_count', data_get($record, 'give_back_count', 0));
        $deficiencies = array_map('strval', (array) data_get($record, 'metadata.blocking_deficiencies', []));
        $reason = (string) data_get($record, 'metadata.reason', '');

        return $giveBackCount >= 7
            && ($reason === 'packet_not_self_sufficient' || in_array('repeated_give_back_8', $deficiencies, true));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function isDormantCliArmProxy(array $record): bool
    {
        $deficiencies = array_map('strval', (array) data_get($record, 'metadata.blocking_deficiencies', []));

        return in_array('dormant_cli_arm_proxy', $deficiencies, true);
    }
}
