<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\AtlasTeosReadinessCertificationService;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * Atlas TEOS readiness CLI.
 *
 * Aggregates the canonical TEOS-I1 readiness signals into a deterministic
 * JSON payload (`atlas.teos.readiness_certification.v1`) and prints either
 * pretty JSON (`--json`) or a human-readable summary.
 *
 * **Hard invariants** (enforced by the service, surfaced here):
 *   - Never invokes a provider (Claude / Codex / Gemini drivers banned).
 *   - Never declares TEOS complete against external rivals.
 *   - Never mutates Atlas Decide / Provider Topology contracts.
 *
 * `--strict` exits non-zero unless the readiness is `ready`. Use in CI gates
 * that must refuse merge while TEOS-I1 has open canon gaps.
 */
final class AtlasTeosReadinessCommand extends Command
{
    protected $signature = 'atlas:teos:readiness
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless status is ready}';

    protected $description = 'Atlas TEOS readiness/certification audit. NEVER calls a provider; NEVER mutates Atlas Decide topology.';

    public function handle(AtlasTeosReadinessCertificationService $service): int
    {
        $payload = $service->certify();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->renderHuman($payload);
        }

        $status = (string) ($payload['status'] ?? AtlasTeosReadinessCertificationService::STATUS_BLOCKED);
        if ((bool) $this->option('strict')
            && $status !== AtlasTeosReadinessCertificationService::STATUS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->line(sprintf(
            '<info>Atlas TEOS Readiness</info> (schema %s)',
            $payload['schema_version'] ?? 'unknown',
        ));
        $this->line('Status: <comment>'.($payload['status'] ?? 'unknown').'</comment>');
        $this->line('External claim: <comment>'.($payload['external_claim_status'] ?? 'unknown').'</comment>');
        $this->line('Provider calls made: <comment>'
            .((bool) YesNo::trueFalse($payload['provider_calls_made'] ?? true)).'</comment>');
        $this->line('Atlas Decide topology modified: <comment>'
            .((bool) YesNo::trueFalse($payload['atlas_decide_topology_modified'] ?? true)).'</comment>');
        $this->line('Summary: '.json_encode($payload['summary'] ?? [], JSON_UNESCAPED_SLASHES));
        $this->newLine();
        foreach ((array) ($payload['checks'] ?? []) as $check) {
            $status = (string) ($check['status'] ?? '');
            $tag = match ($status) {
                AtlasTeosReadinessCertificationService::CHECK_STATUS_PASS => '<info>PASS</info>',
                AtlasTeosReadinessCertificationService::CHECK_STATUS_WARN => '<comment>WARN</comment>',
                default => '<error>FAIL</error>',
            };
            $this->line(sprintf(
                '%s [%s] %s — %s',
                $tag,
                $check['severity'] ?? '?',
                $check['check_id'] ?? '?',
                $check['reason'] ?? '',
            ));
        }
        if (! empty($payload['blockers'])) {
            $this->newLine();
            $this->warn('Blockers:');
            foreach ($payload['blockers'] as $blocker) {
                $this->line(sprintf(
                    '  - %s (%s) — %s',
                    $blocker['check_id'] ?? '?',
                    $blocker['severity'] ?? '?',
                    $blocker['reason'] ?? '',
                ));
            }
        }
    }
}
