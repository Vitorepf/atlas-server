<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaMigrator;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaVersioning;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\SchemaDowngradeRefusedException;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\UnknownSchemaVersionException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Operator + loop surface for the Maestro packet schema triad (versioning + migrator + deprecation).
 *
 *   atlas:task maestro:schema versions          — list versions with status/introduced_at/successor
 *   atlas:task maestro:schema migrate           — migrate a packet JSON from --input to --to
 *   atlas:task maestro:schema deprecate         — mark --from as deprecated (requires --token)
 *
 * Persisted mutable state lives at storage/app/maestro/packet_schema_state.json (overrideable
 * via config('atlas.maestro.packet_schema.state_path')).
 */
final class AtlasTaskMaestroSchemaCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    public const DEFAULT_OPERATOR_TOKEN = 'operator-confirm';

    protected $signature = 'atlas:task:maestro:schema {action : versions|migrate|deprecate}
        {--from= : source version id (deprecate)}
        {--to= : target version id (migrate)}
        {--input= : packet payload file path or `-` for stdin (migrate)}
        {--reason= : reason text (deprecate)}
        {--token= : operator confirmation token (deprecate)}
        {--json : emit machine-readable JSON}';

    protected $description = 'Maestro packet schema CLI: versions | migrate | deprecate.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'versions' => $this->versionsAction(),
            'migrate' => $this->migrateAction(),
            'deprecate' => $this->deprecateAction(),
            default => $this->failWith('unknown_action:'.$action.' (expected one of versions|migrate|deprecate)'),
        };
    }

    private function versionsAction(): int
    {
        $versioning = $this->buildVersioning();
        $rows = array_map(static fn (array $row): array => [
            'id' => (string) ($row['id'] ?? ''),
            'version' => (int) ($row['version'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'introduced_at' => (string) ($row['introduced_at'] ?? ''),
            'successor' => $row['successor'] ?? null,
        ], $versioning->versions());

        $this->emit(['versions' => $rows]);

        return self::EXIT_OK;
    }

    private function migrateAction(): int
    {
        $target = (string) ($this->option('to') ?? '');
        if ($target === '') {
            return $this->failWith('to_option_missing');
        }
        $input = (string) ($this->option('input') ?? '');
        if ($input === '') {
            return $this->failWith('input_option_missing');
        }
        $raw = $input === '-' ? (string) @file_get_contents('php://stdin') : (is_file($input) ? (string) file_get_contents($input) : '');
        if ($raw === '') {
            return $this->failWith('input_payload_empty');
        }
        $packet = json_decode($raw, true);
        if (! is_array($packet)) {
            return $this->failWith('input_not_valid_json');
        }

        $migrator = $this->buildMigrator();

        try {
            $out = $migrator->migrateTo($packet, $target);
        } catch (UnknownSchemaVersionException $e) {
            return $this->failWith('UnknownSchemaVersionException: '.$e->getMessage());
        } catch (SchemaDowngradeRefusedException $e) {
            return $this->failWith('SchemaDowngradeRefusedException: '.$e->getMessage());
        } catch (Throwable $e) {
            return $this->failWith('migration_failed: '.$e->getMessage());
        }

        $this->line((string) json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::EXIT_OK;
    }

    private function deprecateAction(): int
    {
        $from = (string) ($this->option('from') ?? '');
        $reason = (string) ($this->option('reason') ?? '');
        $token = (string) ($this->option('token') ?? '');
        if ($from === '') {
            return $this->failWith('from_option_missing');
        }
        if ($reason === '') {
            return $this->failWith('reason_option_missing');
        }
        $expectedToken = (string) (config('atlas.maestro.packet_schema.deprecate_token') ?? self::DEFAULT_OPERATOR_TOKEN);
        if ($token === '' || ! hash_equals($expectedToken, $token)) {
            return $this->failWith('deprecate_refused: operator-confirm token required and must match');
        }

        $versioning = $this->buildVersioning();
        if (! $versioning->supports($from)) {
            return $this->failWith('UnknownSchemaVersionException: '.$from);
        }

        $statePath = $this->statePath();
        $state = $this->loadState($statePath);
        $state['overrides'][$from] = [
            'status' => AtlasMaestroPacketSchemaVersioning::STATUS_DEPRECATED,
            'reason' => $reason,
            'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $this->writeState($statePath, $state);

        $this->emit([
            'deprecated' => $from,
            'reason' => $reason,
            'state_path' => $statePath,
        ]);

        return self::EXIT_OK;
    }

    private function buildVersioning(): AtlasMaestroPacketSchemaVersioning
    {
        // Test seam — bound instance wins.
        if (app()->bound(AtlasMaestroPacketSchemaVersioning::class)) {
            return app(AtlasMaestroPacketSchemaVersioning::class);
        }
        $statePath = $this->statePath();
        $state = $this->loadState($statePath);

        // Start with a default-seeded Versioning, then layer overrides from state.
        $base = new AtlasMaestroPacketSchemaVersioning();
        $extra = [];
        foreach ($base->versions() as $row) {
            $id = (string) $row['id'];
            if (isset($state['overrides'][$id]) && is_array($state['overrides'][$id])) {
                $row['status'] = (string) ($state['overrides'][$id]['status'] ?? $row['status']);
            }
            $extra[] = $row;
        }

        return new AtlasMaestroPacketSchemaVersioning($extra);
    }

    private function buildMigrator(): AtlasMaestroPacketSchemaMigrator
    {
        if (app()->bound(AtlasMaestroPacketSchemaMigrator::class)) {
            return app(AtlasMaestroPacketSchemaMigrator::class);
        }

        return new AtlasMaestroPacketSchemaMigrator($this->buildVersioning());
    }

    private function statePath(): string
    {
        return (string) (config('atlas.maestro.packet_schema.state_path') ?? storage_path('app/maestro/packet_schema_state.json'));
    }

    /**
     * @return array<string,mixed>
     */
    private function loadState(string $path): array
    {
        if (! is_file($path)) {
            return ['overrides' => []];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return ['overrides' => []];
        }
        if (! isset($decoded['overrides']) || ! is_array($decoded['overrides'])) {
            $decoded['overrides'] = [];
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $state
     */
    private function writeState(string $path, array $state): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        file_put_contents($path, (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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
