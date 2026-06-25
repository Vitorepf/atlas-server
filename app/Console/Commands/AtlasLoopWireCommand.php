<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\WireFormat\AtlasLoopInterPrimitiveMessageReceiptLedger;
use App\Services\Ai\AutonomousEvolution\WireFormat\AtlasLoopInterPrimitiveMessageSchemaRegistry;
use App\Services\Ai\AutonomousEvolution\WireFormat\AtlasLoopInterPrimitiveMessageValidator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-facing surface for the Loop ⇄ Cortex ⇄ Maestro wire format.
 *
 *   atlas:loop:wire schemas   — list every schemaId, version and required fields
 *   atlas:loop:wire validate  — validate a payload against a schema (--payload=<path> or stdin)
 *   atlas:loop:wire history   — print most-recent cross-primitive receipt history
 *
 * Provider-safe: NO provider calls. Fail-closed on unknown action.
 */
final class AtlasLoopWireCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:wire {action : schemas|validate|history}
        {--schema= : schema id (validate / history)}
        {--payload= : path to a JSON payload file (validate); reads STDIN when omitted}
        {--source= : filter receipts by source primitive (history)}
        {--limit=20 : maximum receipts to return (history)}
        {--json : emit machine-readable JSON}';

    protected $description = 'Wire-format observability for Loop ⇄ Cortex ⇄ Maestro traffic.';

    public function handle(
        AtlasLoopInterPrimitiveMessageSchemaRegistry $registry,
        AtlasLoopInterPrimitiveMessageValidator $validator,
        AtlasLoopInterPrimitiveMessageReceiptLedger $ledger,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'schemas' => $this->schemas($registry),
            'validate' => $this->validatePayload($registry, $validator),
            'history' => $this->history($ledger),
            default => $this->failWith('unknown_action:'.$action.' (expected one of schemas|validate|history)'),
        };
    }

    private function schemas(AtlasLoopInterPrimitiveMessageSchemaRegistry $registry): int
    {
        $rows = [];
        foreach ($registry->all() as $id) {
            $schema = $registry->get($id);
            $rows[] = [
                'id' => (string) $schema['id'],
                'family' => (string) $schema['family'],
                'version' => (int) $schema['version'],
                'required_fields' => array_keys((array) $schema['required_fields']),
            ];
        }
        $this->emit(['schemas' => $rows]);
        // Human-readable fallback so feature tests can grep without --json.
        if (! $this->option('json')) {
            foreach ($rows as $r) {
                $this->line(sprintf('%s (v%d) required: %s', $r['id'], $r['version'], implode(',', $r['required_fields'])));
            }
        }

        return self::EXIT_OK;
    }

    private function validatePayload(
        AtlasLoopInterPrimitiveMessageSchemaRegistry $registry,
        AtlasLoopInterPrimitiveMessageValidator $validator,
    ): int {
        $schemaId = (string) ($this->option('schema') ?? '');
        if ($schemaId === '') {
            return $this->failWith('schema_option_missing');
        }
        try {
            $registry->get($schemaId);
        } catch (Throwable) {
            return $this->failWith('unknown_schema:'.$schemaId);
        }

        $payloadPath = (string) ($this->option('payload') ?? '');
        $raw = $payloadPath !== '' && is_file($payloadPath)
            ? (string) file_get_contents($payloadPath)
            : (string) @file_get_contents('php://stdin');

        if ($raw === '') {
            return $this->failWith('payload_missing');
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $this->failWith('payload_not_valid_json');
        }

        $result = $validator->validate($schemaId, $decoded);
        $ok = $result->ok();
        $errors = $result->errors();

        $payload = [
            'schema_id' => $schemaId,
            'validation_ok' => $ok,
            'errors' => $errors,
        ];
        $this->emit($payload);
        if (! $this->option('json')) {
            foreach ($errors as $err) {
                $this->line('error: '.(is_array($err) ? json_encode($err) : (string) $err));
            }
        }

        return $ok ? self::EXIT_OK : self::EXIT_USAGE;
    }

    private function history(AtlasLoopInterPrimitiveMessageReceiptLedger $ledger): int
    {
        $filter = [];
        if (($source = (string) ($this->option('source') ?? '')) !== '') {
            $filter['source'] = $source;
        }
        if (($schema = (string) ($this->option('schema') ?? '')) !== '') {
            $filter['schema_id'] = $schema;
        }
        $limit = max(1, (int) $this->option('limit'));
        $rows = $ledger->history($filter, $limit);

        $this->emit(['receipts' => $rows]);
        if (! $this->option('json')) {
            foreach ($rows as $r) {
                $this->line(sprintf(
                    '%s → %s [%s] payload_hash=%s validation_ok=%s',
                    (string) ($r['source'] ?? '?'),
                    (string) ($r['target'] ?? '?'),
                    (string) ($r['schema_id'] ?? '?'),
                    (string) ($r['payload_hash'] ?? '?'),
                    ((bool) ($r['validation_ok'] ?? false)) ? 'true' : 'false',
                ));
            }
        }

        return self::EXIT_OK;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
    }

    private function failWith(string $reason): int
    {
        $this->line($reason);

        return self::EXIT_USAGE;
    }
}
