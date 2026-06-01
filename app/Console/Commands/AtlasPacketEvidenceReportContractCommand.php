<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasPacketEvidenceReportContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-Construction Packet Evidence Report CLI.
 *
 *   php artisan atlas:aaeos:packet-evidence-report-contract
 *     [--packet=AIP-SPLIT-20260601-0001]
 *     [--scope-status=pass|fail|blocked]
 *     [--runbook]                 // mark runbook present
 *     [--assignment]              // mark assignment preview present
 *     [--gates=focused_tests:pass,docs_health:pass,architecture_validate:pass,git_diff_check:pass]
 *     [--required-evidence=focused_tests,architecture_validate]
 *     [--present-evidence=focused_tests]
 *     [--owned=app/Services/Foo.php:allowed]
 *     [--external-blockers=runtimes/python/voice_realtime/x.py]
 *     [--residual-risk=low|medium|high]
 *     [--risk-accepted]
 *     [--json]
 *
 * Read-only, deterministic. Decides whether a selected packet has enough
 * evidence to be completion-ready or must stay blocked. It NEVER writes the
 * Evidence Ledger, marks completion, overrides failed gates or ignores hot
 * external files.
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md
 */
class AtlasPacketEvidenceReportContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:packet-evidence-report-contract
        {--packet= : selected packet id}
        {--scope-status= : scope validator status (pass|fail|blocked)}
        {--runbook : mark runbook as present}
        {--assignment : mark assignment preview as present}
        {--gates= : comma-separated gate id:status pairs (status pass|fail|missing)}
        {--required-evidence= : comma-separated required evidence ids}
        {--present-evidence= : comma-separated evidence ids actually present}
        {--owned= : comma-separated packet-owned path:classification pairs}
        {--external-blockers= : comma-separated hot external paths owned by another front}
        {--residual-risk= : residual risk level (low|medium|high)}
        {--risk-accepted : residual risk explicitly accepted by review}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · packet evidence report deciding completion_ready vs blocked across gates, scope, evidence and external blockers.';

    public function handle(AtlasPacketEvidenceReportContractService $service): int
    {
        try {
            $packetOpt = $this->option('packet');
            $hasPacket = is_string($packetOpt) && trim($packetOpt) !== '';
            $scopeStatus = $this->option('scope-status');
            $residualRisk = $this->option('residual-risk');

            $input = [
                'selected_packet_id' => $hasPacket ? trim((string) $packetOpt) : 'AIP-SPLIT-LOCAL-0001',
                'runbook_exists' => (bool) $this->option('runbook'),
                'assignment_preview_exists' => (bool) $this->option('assignment'),
                'scope_validator_status' => is_string($scopeStatus) && trim($scopeStatus) !== ''
                    ? trim($scopeStatus)
                    : 'pass',
                'required_gates' => $this->pairs('gates', 'id', 'status'),
                'required_evidence' => $this->list('required-evidence'),
                'present_evidence' => $this->list('present-evidence'),
                'packet_owned_files' => $this->pairs('owned', 'path', 'classification'),
                'external_blockers' => array_map(
                    static fn (string $path): array => ['path' => $path],
                    $this->list('external-blockers'),
                ),
                'residual_risk' => is_string($residualRisk) && trim($residualRisk) !== ''
                    ? trim($residualRisk)
                    : 'low',
                'residual_risk_accepted_by_review' => (bool) $this->option('risk-accepted'),
            ];

            $result = $service->report($input);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasPacketEvidenceReportContractService::STATUS_COMPLETION_READY
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'packet_evidence_report_failed',
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
