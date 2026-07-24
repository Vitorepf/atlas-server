<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\Maestro\Semantic\AtlasMaestroSemanticAuditPanel;
use App\Services\Ai\SelfConstruction\Maestro\Semantic\AtlasMaestroSemanticRejectionReceipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Operator/loop-callable front door for the Maestro SEMANTIC v+3 gate. The structural quality inspector stays
 * where it is; this CLI loads ONE task packet (from a JSON file or by id from the serving queue) and runs it
 * through {@see AtlasMaestroSemanticAuditPanel} — the 3-voter quorum that catches fabricated-symbol / wrong-role
 * packets the structural pass cannot. On rejection it composes the {@see AtlasMaestroSemanticRejectionReceipt},
 * persists it, and prints it.
 *
 * Exit codes (the contract the loop branches on): 0 = passed, 1 = semantically rejected, 2 = packet missing/invalid.
 *
 * NOTE ON NAME: the intent is `atlas:task maestro:semantic:audit`. A literal signature starting `atlas:task `
 * would register the command name `atlas:task` and override {@see AtlasTaskCommand} (the live worker contract),
 * so it uses the sibling colon convention (`atlas:task:maestro-semantic-audit`).
 */
final class AtlasTaskMaestroSemanticAuditCommand extends Command
{
    use EmitsCanonicalJson;

    private const RECEIPT_DIR = 'atlas/maestro/semantic-rejections';

    protected $signature = 'atlas:task:maestro-semantic-audit {--packet-file= : Path to a packet JSON file} {--packet-id= : task_packet_id to load from the serving queue} {--json : Emit machine-readable JSON instead of human text}';

    protected $description = 'Run the Maestro semantic v+3 gate over one task packet (exit 0=pass, 1=rejected, 2=missing/invalid).';

    /** Reason text for a missing/invalid packet — set by loadPacket() before returning null. */
    private string $missingReason = 'no_packet_specified';

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        $packet = $this->loadPacket();
        if ($packet === null) {
            if ($json) {
                $this->line((string) json_encode(['status' => 'missing_packet', 'reason' => $this->missingReason], JSON_UNESCAPED_SLASHES));
            }

            return 2;
        }

        $result = (new AtlasMaestroSemanticAuditPanel)->audit($packet);
        $votes = array_map(static fn (mixed $v): int => $v ? 1 : 0, array_values((array) ($result['votes'] ?? [])));

        if (($result['pass'] ?? false) === true) {
            if ($json) {
                $this->line((string) json_encode(['status' => 'passed', 'panel_votes' => $votes], JSON_UNESCAPED_SLASHES));
            } else {
                $this->line('PASS panel_votes=['.implode(',', $votes).']');
            }

            return 0;
        }

        $rawId = trim((string) ($packet['task_packet_id'] ?? ''));
        $packetId = $rawId !== '' ? $rawId : ((string) $this->option('packet-id') ?: 'unknown');
        $receipt = (new AtlasMaestroSemanticRejectionReceipt)->compose($packetId, $result);
        $this->persistReceipt($packetId, $receipt);

        if ($json) {
            $decoded = (array) json_decode($receipt, true, 512);
            $decoded['status'] = 'rejected';
            $this->line($this->encode($decoded));
        } else {
            $this->line($receipt);
        }

        return 1;
    }

    /**
     * @return array<string,mixed>|null  null ⇒ sets $this->missingReason and (in non-json mode) emits a text line
     */
    private function loadPacket(): ?array
    {
        $file = trim((string) $this->option('packet-file'));
        $id = trim((string) $this->option('packet-id'));
        $json = (bool) $this->option('json');

        if ($file !== '') {
            if (! is_file($file)) {
                $this->missingReason = 'file_missing';
                if (! $json) {
                    $this->line('packet_not_found reason=file_missing path='.$file);
                }

                return null;
            }
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (! is_array($decoded)) {
                $this->missingReason = 'invalid_json';
                if (! $json) {
                    $this->line('packet_not_found reason=invalid_json path='.$file);
                }

                return null;
            }

            return $decoded;
        }

        if ($id !== '') {
            try {
                $record = AtlasTaskServingStack::queueRepo()->get($id);
            } catch (Throwable) {
                $record = null;
            }
            $packet = is_array($record) ? (array) ($record['task_packet'] ?? []) : [];
            if ($packet === []) {
                $this->missingReason = 'id_not_in_queue';
                if (! $json) {
                    $this->line('packet_not_found reason=id_not_in_queue id='.$id);
                }

                return null;
            }

            return $packet;
        }

        $this->missingReason = 'no_packet_specified';
        if (! $json) {
            $this->line('packet_not_found reason=no_packet_specified');
        }

        return null;
    }

    private function persistReceipt(string $packetId, string $receipt): void
    {
        try {
            $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $packetId) ?: 'unknown';
            Storage::disk('local')->put(self::RECEIPT_DIR.'/'.$safe.'.json', $receipt);
        } catch (Throwable) {
            // best-effort: failing to persist the receipt must not change the rejection exit code
        }
    }
}
