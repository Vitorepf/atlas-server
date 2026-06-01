<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasPacketCompletionGateContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-Construction Packet Completion Gate CLI.
 *
 *   php artisan atlas:aaeos:packet-completion-gate-contract
 *     [--packet=AIP-SPLIT-20260601-0001]
 *     [--report-status=clean|blocked]     // upstream evidence report status
 *     [--report-hash=sha256:...]
 *     [--scope-status=pass|fail|blocked]
 *     [--gates=focused_tests:pass,docs_health:pass]
 *     [--evidence=focused_tests:present,architecture_validate:missing]
 *     [--external-blockers=runtimes/python/voice_realtime/x.py]
 *     [--blocking-reasons=...]
 *     [--human-review]                    // mark human review present
 *     [--json]
 *
 * Read-only, deterministic. Converts an evidence report into a completion
 * decision: blocked, human_review_required or completion_candidate. It NEVER
 * persists durable completion, writes the Evidence Ledger, overrides the
 * evidence report or authorizes execution.
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md
 */
class AtlasPacketCompletionGateContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:packet-completion-gate-contract
        {--packet= : selected packet id}
        {--report-status= : upstream evidence report status (clean|blocked)}
        {--report-hash= : evidence report hash}
        {--scope-status= : scope validator status (pass|fail|blocked)}
        {--gates= : comma-separated gate id:status pairs (status pass|fail|missing)}
        {--evidence= : comma-separated evidence id:status pairs (status present|missing)}
        {--external-blockers= : comma-separated hot external paths owned by another front}
        {--blocking-reasons= : comma-separated upstream blocking reasons to preserve}
        {--human-review : mark that a human review exists}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · packet completion gate deciding blocked vs human_review_required vs completion_candidate from the evidence report.';

    public function handle(AtlasPacketCompletionGateContractService $service): int
    {
        try {
            $packetOpt = $this->option('packet');
            $hasPacket = is_string($packetOpt) && trim($packetOpt) !== '';
            $reportStatus = $this->option('report-status');
            $reportHash = $this->option('report-hash');
            $scopeStatus = $this->option('scope-status');

            $input = [
                'selected_packet_id' => $hasPacket ? trim((string) $packetOpt) : 'AIP-SPLIT-LOCAL-0001',
                'evidence_report_status' => is_string($reportStatus) && trim($reportStatus) !== ''
                    ? trim($reportStatus)
                    : 'clean',
                'evidence_report_hash' => is_string($reportHash) && trim($reportHash) !== ''
                    ? trim($reportHash)
                    : 'sha256:local',
                'scope_validator_status' => is_string($scopeStatus) && trim($scopeStatus) !== ''
                    ? trim($scopeStatus)
                    : 'pass',
                'required_gate_statuses' => $this->pairs('gates', 'id', 'status'),
                'required_evidence_statuses' => $this->pairs('evidence', 'id', 'status'),
                'external_blockers' => array_map(
                    static fn (string $path): array => ['path' => $path],
                    $this->list('external-blockers'),
                ),
                'blocking_reasons' => $this->list('blocking-reasons'),
                'human_review_present' => (bool) $this->option('human-review'),
            ];

            $result = $service->decide($input);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // A completion candidate is the only non-blocking terminal state;
            // human_review_required and blocked both signal "not yet" via FAILURE.
            return $result['status'] === AtlasPacketCompletionGateContractService::STATUS_COMPLETION_CANDIDATE
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'packet_completion_gate_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn ($v) => $v !== '',
        ));
    }

    /**
     * Parse comma-separated `left:right` pairs into list of maps.
     *
     * @return list<array<string, string>>
     */
    private function pairs(string $option, string $leftKey, string $rightKey): array
    {
        $out = [];
        foreach ($this->list($option) as $token) {
            $parts = explode(':', $token, 2);
            $left = trim($parts[0]);
            if ($left === '') {
                continue;
            }
            $right = isset($parts[1]) ? trim($parts[1]) : '';
            $out[] = [$leftKey => $left, $rightKey => $right];
        }

        return $out;
    }
}
