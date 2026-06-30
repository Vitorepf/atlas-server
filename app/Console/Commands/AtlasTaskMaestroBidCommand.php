<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\AtlasMaestroProviderBidArbiter;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\AtlasMaestroProviderBidProposer;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\AtlasMaestroProviderBidReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\BidArbitrationVerdict;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\BidSet;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\InvalidProviderException;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\LedgerImmutableViolation;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\NoEligibleProviderVerdict;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\ProviderProfile;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\TaskEnvelope;
use Illuminate\Console\Command;

/**
 * Operator surface for Maestro provider-bid negotiation.
 *   atlas:task:maestro:bid propose    --task=<json-path-or-stdin> [--json]
 *   atlas:task:maestro:bid arbitrate  --task=<json-path-or-stdin> [--json]
 *   atlas:task:maestro:bid history    --provider=<id> [--since=<iso>] [--json]
 *
 * Anti-Goodhart: refuses to fabricate bids (arbitrate must read a real envelope), and refuses to
 * re-record an existing ledger entry (LedgerImmutableViolation surfaces as a non-zero exit).
 *
 * Note: signature uses colon-only form ("atlas:task:maestro:bid") to avoid Symfony's
 * space-as-command-separator collision with the `atlas:task` worker entrypoint.
 */
final class AtlasTaskMaestroBidCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_REFUSED = 2;

    public const EXIT_IMMUTABLE = 3;

    public const EXIT_NO_ELIGIBLE = 4;

    protected $signature = 'atlas:task:maestro:bid {action : propose|arbitrate|history}
        {--task= : path to TaskEnvelope JSON (or "-" for stdin)}
        {--provider= : provider id (history)}
        {--since= : ISO-8601 lower bound (history)}
        {--json}';

    protected $description = 'Maestro provider-bid CLI: propose | arbitrate | history.';

    public function handle(
        AtlasMaestroProviderBidProposer $proposer,
        AtlasMaestroProviderBidArbiter $arbiter,
        AtlasMaestroProviderBidReceiptLedger $ledger,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'propose' => $this->propose($proposer),
            'arbitrate' => $this->arbitrate($proposer, $arbiter, $ledger),
            'history' => $this->history($ledger),
            default => $this->refuse('unknown_action:'.$action),
        };
    }

    private function propose(AtlasMaestroProviderBidProposer $proposer): int
    {
        $envelope = $this->loadEnvelope();
        if ($envelope === null) {
            return $this->refuse('missing_task_envelope');
        }
        $profiles = $this->loadProfiles();
        try {
            $bidSet = $proposer->propose($envelope, $profiles);
        } catch (InvalidProviderException $e) {
            return $this->refuse('invalid_provider:'.$e->getMessage());
        }
        $this->emit($bidSet->toArray());

        return self::EXIT_OK;
    }

    private function arbitrate(
        AtlasMaestroProviderBidProposer $proposer,
        AtlasMaestroProviderBidArbiter $arbiter,
        AtlasMaestroProviderBidReceiptLedger $ledger,
    ): int {
        $envelope = $this->loadEnvelope();
        if ($envelope === null) {
            // Anti-Goodhart: never fabricate. Refuse without a real envelope on disk/stdin.
            return $this->refuse('arbitrate_requires_envelope_or_bid_set');
        }
        $profiles = $this->loadProfiles();
        try {
            $bidSet = $proposer->propose($envelope, $profiles);
        } catch (InvalidProviderException $e) {
            return $this->refuse('invalid_provider:'.$e->getMessage());
        }

        $verdict = $arbiter->arbitrate($bidSet, $envelope->taskId);
        if ($verdict instanceof NoEligibleProviderVerdict) {
            $this->emit($verdict->toArray());

            return self::EXIT_NO_ELIGIBLE;
        }

        try {
            $ledger->append(
                taskId: $envelope->taskId,
                envelopeHash: hash('sha256', (string) json_encode($envelope->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                bidHashes: array_map(static fn ($b): string => $b->bidHash, $bidSet->bids),
                winnerProviderId: $verdict->winnerProviderId,
                decisiveCriterion: $verdict->decisiveCriterion,
                criteriaTrace: $verdict->criteriaTrace,
                recordedAtIso: gmdate('Y-m-d\TH:i:s\Z'),
            );
        } catch (LedgerImmutableViolation $e) {
            $this->getOutput()->writeln('ledger_immutable_violation:'.$e->getMessage());

            return self::EXIT_IMMUTABLE;
        }

        $this->emit($verdict->toArray());

        return self::EXIT_OK;
    }

    private function history(AtlasMaestroProviderBidReceiptLedger $ledger): int
    {
        $provider = (string) ($this->option('provider') ?? '');
        if ($provider === '') {
            return $this->refuse('missing_provider');
        }
        $since = $this->option('since');
        $entries = $ledger->history($provider, is_string($since) && $since !== '' ? $since : null);
        usort($entries, static fn ($a, $b): int => strcmp($a->recordedAtIso, $b->recordedAtIso));
        $rows = array_map(static fn ($e): array => $e->toArray(), $entries);
        $this->emit($rows);

        return self::EXIT_OK;
    }

    private function loadEnvelope(): ?TaskEnvelope
    {
        $path = (string) ($this->option('task') ?? '');
        if ($path === '') {
            return null;
        }
        if ($path === '-') {
            $raw = (string) file_get_contents('php://stdin');
        } elseif (is_file($path)) {
            $raw = (string) file_get_contents($path);
        } else {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return new TaskEnvelope(
            taskId: (string) ($decoded['task_id'] ?? $decoded['taskId'] ?? ''),
            kind: (string) ($decoded['kind'] ?? 'maestro'),
            requiredCapabilities: array_values(array_filter((array) ($decoded['required_capabilities'] ?? []), 'is_string')),
            deadline: (string) ($decoded['deadline'] ?? ''),
            localOnly: (bool) ($decoded['local_only_bool'] ?? $decoded['localOnly'] ?? false),
            sensitivityClass: (string) ($decoded['sensitivity_class'] ?? 'unclassified'),
            providerIds: array_values(array_filter((array) ($decoded['provider_ids'] ?? []), 'is_string')),
        );
    }

    /**
     * @return list<ProviderProfile>
     */
    private function loadProfiles(): array
    {
        $raw = (array) (function_exists('config') ? config('atlas.maestro.provider_negotiation.profiles', []) : []);
        $profiles = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $profiles[] = new ProviderProfile(
                providerId: (string) ($row['provider_id'] ?? ''),
                declaredCapabilities: array_values(array_filter((array) ($row['declared_capabilities'] ?? []), 'is_string')),
                observedCostPerTokenIn: (float) ($row['observed_cost_per_token_in'] ?? 0),
                observedCostPerTokenOut: (float) ($row['observed_cost_per_token_out'] ?? 0),
                observedP50LatencyMs: (int) ($row['observed_p50_latency_ms'] ?? 0),
                currentLoadPct: (int) ($row['current_load_pct'] ?? 0),
                locality: (string) ($row['locality'] ?? 'cloud'),
                sensitivityAllowed: array_values(array_filter((array) ($row['sensitivity_allowed'] ?? ['unclassified']), 'is_string')),
                extras: (array) ($row['extras'] ?? []),
            );
        }

        return $profiles;
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->getOutput()->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->getOutput()->writeln($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }

    private function refuse(string $reason): int
    {
        if ($this->option('json')) {
            $this->getOutput()->writeln((string) json_encode(['status' => 'refused', 'reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->getOutput()->writeln($reason);
        }

        return self::EXIT_REFUSED;
    }
}
