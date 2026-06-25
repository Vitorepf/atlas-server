<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopAttributionInheritanceChannel;
use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationConsensusObserver;
use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationFactSyncProtocol;
use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationIsolationGuard;
use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopFederationPeerRegistry;
use Illuminate\Console\Command;

/**
 * Operator surface for the federation primitives.
 *
 *   atlas:loop:federation peer-register --peer-id --scope --endpoint --axes=
 *   atlas:loop:federation peer-forget   --peer-id
 *   atlas:loop:federation sync          --peer-id --fact=<json|path> [--malformed-envelope=<json|path>]
 *   atlas:loop:federation consensus     --reports=<json|path>
 *   atlas:loop:federation status        [--json]
 */
final class AtlasLoopFederationCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:federation {action : peer-register|peer-forget|sync|consensus|status|inheritance}
        {--peer-id= : peer id (register/forget/sync)}
        {--scope= : peer scope (register)}
        {--endpoint= : peer endpoint URI (register)}
        {--axes= : comma-separated capability axes (register)}
        {--fact= : JSON path/literal for the FACT to publish (sync)}
        {--malformed-envelope= : JSON path/literal for an envelope to send through the guard (sync)}
        {--reports= : JSON path/literal of peer reports (consensus)}
        {--per-cycle-priors= : JSON path/literal of per-cycle attribution priors (inheritance)}
        {--json : machine-readable JSON output}';

    protected $description = 'Federation operator CLI: peer-register|peer-forget|sync|consensus|status.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'peer-register' => $this->peerRegister(),
            'peer-forget' => $this->peerForget(),
            'sync' => $this->sync(),
            'consensus' => $this->consensus(),
            'status' => $this->status(),
            'inheritance' => $this->inheritance(),
            default => $this->failWith('unknown_action:'.$action),
        };
    }

    private function peerRegister(): int
    {
        $registry = $this->registry();
        if ($registry === null) {
            return $this->failWith('peer_registry_not_available');
        }
        $peerId = (string) ($this->option('peer-id') ?? '');
        if ($peerId === '') {
            return $this->failWith('peer_id_option_missing');
        }
        $axes = array_values(array_filter(array_map('trim', explode(',', (string) ($this->option('axes') ?? '')))));

        try {
            $row = $registry->register([
                'peer_id' => $peerId,
                'scope' => (string) ($this->option('scope') ?? ''),
                'endpoint' => (string) ($this->option('endpoint') ?? ''),
                'capability_axes' => $axes,
            ]);
        } catch (\Throwable $e) {
            return $this->failWith('register_failed:'.$e->getMessage());
        }
        $this->emit(['registered' => $row]);

        return self::EXIT_OK;
    }

    private function peerForget(): int
    {
        $registry = $this->registry();
        if ($registry === null) {
            return $this->failWith('peer_registry_not_available');
        }
        $peerId = (string) ($this->option('peer-id') ?? '');
        if ($peerId === '') {
            return $this->failWith('peer_id_option_missing');
        }
        $forgotten = $registry->forget($peerId);
        $this->emit(['forgotten' => $forgotten, 'peer_id' => $peerId]);

        return self::EXIT_OK;
    }

    private function sync(): int
    {
        $protocol = $this->protocol();
        $guard = $this->guard();
        if ($protocol === null || $guard === null) {
            return $this->failWith('protocol_or_guard_not_available');
        }
        $peerId = (string) ($this->option('peer-id') ?? '');
        if ($peerId === '') {
            return $this->failWith('peer_id_option_missing');
        }
        $factPayload = $this->loadJsonOption('fact');
        $nowIso = gmdate('Y-m-d\TH:i:s\Z');
        $good = null;
        if (is_array($factPayload)) {
            try {
                $envelope = $protocol->publish($peerId, $factPayload);
                $good = $guard->ingest($envelope, $nowIso);
            } catch (\Throwable $e) {
                $good = ['accepted' => false, 'error' => $e->getMessage()];
            }
        }
        $bad = null;
        $badEnv = $this->loadJsonOption('malformed-envelope');
        if (is_array($badEnv)) {
            $bad = $guard->ingest($badEnv, $nowIso);
        }

        $this->emit([
            'sync_peer_id' => $peerId,
            'good_round_trip' => $good,
            'malformed_round_trip' => $bad,
        ]);

        return self::EXIT_OK;
    }

    private function consensus(): int
    {
        $observer = $this->observer();
        if ($observer === null) {
            return $this->failWith('consensus_observer_not_available');
        }
        $reports = $this->loadJsonOption('reports');
        if (! is_array($reports)) {
            $reports = [];
        }
        $emitted = $observer->observe($reports, gmdate('Y-m-d\TH:i:s\Z'));
        $this->emit(['consensus_agreed_facts' => $emitted]);

        return self::EXIT_OK;
    }

    private function status(): int
    {
        $registry = $this->registry();
        $guard = $this->guard();
        $snapshot = [
            'peers' => $registry !== null ? $registry->list() : [],
            'quarantine' => $guard !== null ? $guard->quarantine() : [],
            'peer_tiers' => $guard !== null ? $guard->peerTiers() : [],
        ];
        $this->emit($snapshot);

        return self::EXIT_OK;
    }

    private function registry(): ?AtlasLoopFederationPeerRegistry
    {
        return app()->bound(AtlasLoopFederationPeerRegistry::class)
            ? app(AtlasLoopFederationPeerRegistry::class)
            : null;
    }

    private function protocol(): ?AtlasLoopFederationFactSyncProtocol
    {
        return app()->bound(AtlasLoopFederationFactSyncProtocol::class)
            ? app(AtlasLoopFederationFactSyncProtocol::class)
            : null;
    }

    private function guard(): ?AtlasLoopFederationIsolationGuard
    {
        return app()->bound(AtlasLoopFederationIsolationGuard::class)
            ? app(AtlasLoopFederationIsolationGuard::class)
            : null;
    }

    private function observer(): ?AtlasLoopFederationConsensusObserver
    {
        return app()->bound(AtlasLoopFederationConsensusObserver::class)
            ? app(AtlasLoopFederationConsensusObserver::class)
            : new AtlasLoopFederationConsensusObserver();
    }

    /**
     * `inheritance` merges per-cycle attribution priors into a deterministic global prior via
     * AtlasLoopAttributionInheritanceChannel::mergePriors(). Wired here so the channel is no
     * longer an orphan.
     */
    private function inheritance(): int
    {
        $channel = $this->inheritanceChannel();
        $perCyclePriors = $this->loadJsonOption('per-cycle-priors');
        if (! is_array($perCyclePriors)) {
            $perCyclePriors = [];
        }
        try {
            $result = $channel->mergePriors(array_values($perCyclePriors));
        } catch (\Throwable $e) {
            return $this->failWith('inheritance_failed:'.$e->getMessage());
        }
        $this->emit($result);

        return self::EXIT_OK;
    }

    private function inheritanceChannel(): AtlasLoopAttributionInheritanceChannel
    {
        return app()->bound(AtlasLoopAttributionInheritanceChannel::class)
            ? app(AtlasLoopAttributionInheritanceChannel::class)
            : new AtlasLoopAttributionInheritanceChannel();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadJsonOption(string $option): ?array
    {
        $raw = (string) ($this->option($option) ?? '');
        if ($raw === '') {
            return null;
        }
        if (is_file($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($payload as $k => $v) {
                $this->line($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
            }
        }
    }

    private function failWith(string $reason): int
    {
        $this->line($reason);

        return self::EXIT_USAGE;
    }
}
