<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Symfony\Component\Process\Process;

/** E5 · read-only weekly card sourced from Git and the evidence ledger. */
final class AtlasCodeWeekService
{
    public const SCHEMA_VERSION = 'atlas.code.week.v1';

    public function __construct(private readonly AtlasEvidenceLedger $ledger) {}

    /** @return array<string,mixed> */
    public function capture(string $repo = 'atlas-server'): array
    {
        $profile = (new AtlasCodeWorkspaceProfileService())->findByReference($repo);
        if (! is_array($profile)) {
            throw new \InvalidArgumentException('repository_profile_not_found');
        }
        $path = trim((string) ($profile['repo_root'] ?? $profile['workspace_path'] ?? ''));
        $until = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $since = $until->sub(new \DateInterval('P7D'));
        $lines = $this->run($path, ['git', 'log', '--all', '--since='.$since->format(DATE_ATOM), '--until='.$until->format(DATE_ATOM), '--format=%ae%x1f%H']);
        $commits = 0;
        $byAgent = ['fable' => 0, 'codex' => 0, 'voce' => 0, 'autonomo:desconhecido' => 0];
        $provenance = new AtlasCodeProvenanceService();
        foreach (preg_split('/\r?\n/', trim($lines)) ?: [] as $line) {
            $parts = explode("\x1f", trim($line), 2);
            if (count($parts) !== 2 || trim($parts[0]) === '') {
                continue;
            }
            $commits++;
            $agent = $provenance->agentForAuthor($parts[0]);
            $byAgent[$agent] = ($byAgent[$agent] ?? 0) + 1;
        }

        $heals = [];
        $prevented = 0;
        if (DatabaseTableAvailability::has('atlas_ledger_events')) {
            foreach (AtlasLedgerEvent::query()
                ->whereIn('event_type', [LedgerEventType::OperationCompleted->value, LedgerEventType::OperationBlocked->value])
                ->whereBetween('occurred_at', [$since->format('Y-m-d H:i:s'), $until->format('Y-m-d H:i:s')])
                ->orderBy('occurred_at')
                ->limit(5000)
                ->get() as $event) {
                $payload = (array) ($event->payload ?? []);
                if (($payload['schema_version'] ?? null) === AtlasCodeHealService::RECEIPT_SCHEMA_VERSION
                    && ($payload['status'] ?? null) === 'completed'
                    && isset($payload['heal_id'])) {
                    $heals[(string) $payload['heal_id']] = true;
                }
                if (($payload['schema_version'] ?? null) === AtlasCodePreflightService::SCHEMA_VERSION
                    && ($payload['allowed'] ?? true) === false) {
                    $prevented++;
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => (string) ($profile['slug'] ?? $repo),
            'window' => $since->format('Y-m-d').'..'.$until->format('Y-m-d'),
            'commits' => $commits,
            'heals' => count($heals),
            'prevented' => $prevented,
            'waiting_for_you' => 0,
            'by_agent' => $byAgent,
            'notifications' => ['enabled' => false, 'reason' => 'operator_opt_in'],
        ];
    }

    /** @param array<int,string> $command */
    private function run(string $cwd, array $command): string
    {
        $process = new Process($command, $cwd, null, null, 20);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \InvalidArgumentException('git_command_failed');
        }

        return $process->getOutput();
    }
}
