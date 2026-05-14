<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Improvement Proposal Gate CLI.
 *
 * Builds a Proposal Packet from --proposal=<json|@path> and runs it through the
 * canonical Power Gate. Read-model only; never promotes Forge.
 *
 * Doc: docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
 */
final class AtlasSelfImprovementProposalGateCommand extends Command
{
    protected $signature = 'atlas:self-improvement:proposal-gate
        {--proposal= : Inline JSON or @path/to.json with the proposal payload}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero unless gate outcome is approved}';

    protected $description = 'Atlas Self-Improvement Proposal Power Gate (read-model). Never calls provider; never promotes Forge.';

    public function handle(
        AtlasSelfImprovementProposalPacketService $packetService,
        AtlasSelfImprovementProposalPowerGateService $gateService,
    ): int {
        $payload = $this->resolvePayload();
        if ($payload === null) {
            $this->emit([
                'schema_version' => AtlasSelfImprovementProposalPowerGateService::SCHEMA_VERSION,
                'outcome' => 'rejected',
                'reason' => 'no_proposal_payload_provided',
                'next_action' => 'pass --proposal=<json|@path>',
            ]);

            return self::FAILURE;
        }

        $packet = $packetService->build($payload);
        $gate = $gateService->evaluate($packet);

        $this->emit([
            'schema_version' => 'atlas.self_improvement.proposal_gate_report.v1',
            'proposal_packet' => $packet,
            'power_gate' => $gate,
            'external_provider_call' => false,
            'separated_from' => 'external_rivals_certification',
        ]);

        $strict = (bool) $this->option('strict');
        if (! $strict) {
            return self::SUCCESS;
        }

        return $gate['outcome'] === AtlasSelfImprovementProposalPowerGateService::OUTCOME_APPROVED
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolvePayload(): ?array
    {
        $raw = $this->option('proposal');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        if (str_starts_with($raw, '@')) {
            $path = substr($raw, 1);
            if (! is_file($path)) {
                $this->components->error('proposal file not found: '.$path);

                return null;
            }
            $raw = (string) file_get_contents($path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->components->error('proposal payload is not valid JSON: '.$e->getMessage());

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }
        $this->components->twoColumnDetail('schema_version', (string) ($payload['schema_version'] ?? '—'));
        $outcome = $payload['power_gate']['outcome'] ?? ($payload['outcome'] ?? '—');
        $this->components->twoColumnDetail('outcome', (string) $outcome);
        $hardFails = $payload['power_gate']['hard_fails'] ?? [];
        if (is_array($hardFails) && $hardFails !== []) {
            $this->components->bulletList($hardFails);
        }
    }
}
