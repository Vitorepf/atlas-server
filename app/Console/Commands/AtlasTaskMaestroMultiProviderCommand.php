<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroAssignmentReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroPacketClassifier;
use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroProviderAssignmentPolicy;
use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroProviderClassRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator surface over the Maestro MultiProvider primitives.
 *
 *   providers [--json]               Print the registry: provider id + axes + cost band.
 *   classify --packet=<file> [--json] Load a JSON packet, classify it, emit class + rule_id.
 *   assign   --packet=<file> [--json] Classify → assign → record a ledger receipt; emit receipt_hash.
 *
 * Observability/control surface ONLY — no business logic lives here. Uses a SAFE command name
 * (`atlas:task:maestro-multiprovider`) so it does NOT collide with `atlas:task` (next/report).
 */
final class AtlasTaskMaestroMultiProviderCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:task:maestro-multiprovider {action : providers|classify|assign} {--packet= : path to a JSON packet fixture} {--json}';

    protected $description = 'Operator surface for Maestro MultiProvider: providers | classify | assign.';

    public function handle(
        AtlasMaestroProviderClassRegistry $registry,
        AtlasMaestroPacketClassifier $classifier,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'providers' => $this->providers($registry),
            'classify' => $this->classify($classifier),
            // ponytail: policy + ledger resolved here so providers/classify never trigger their constructors
            'assign' => $this->assign(
                $classifier,
                $this->laravel->make(AtlasMaestroProviderAssignmentPolicy::class),
                $this->laravel->make(AtlasMaestroAssignmentReceiptLedger::class),
            ),
            default => $this->usage('unknown action: '.$action),
        };
    }

    private function providers(AtlasMaestroProviderClassRegistry $registry): int
    {
        $providers = $registry->providers();
        $rows = [];
        foreach ($providers as $providerId => $entry) {
            $providerId = (string) $providerId;
            $rows[] = [
                'provider_id' => $providerId,
                'axes' => $registry->axesFor($providerId),
                'cost_band' => $registry->costBandFor($providerId),
            ];
        }
        $this->emit(['providers' => $rows]);

        return self::EXIT_OK;
    }

    private function classify(AtlasMaestroPacketClassifier $classifier): int
    {
        $packet = $this->loadPacket();
        if ($packet === null) {
            return self::EXIT_USAGE;
        }
        $this->emit([
            'class' => $classifier->classify($packet),
            'rule_id' => $classifier->reasonFor($packet),
        ]);

        return self::EXIT_OK;
    }

    private function assign(
        AtlasMaestroPacketClassifier $classifier,
        AtlasMaestroProviderAssignmentPolicy $policy,
        AtlasMaestroAssignmentReceiptLedger $ledger,
    ): int {
        $packet = $this->loadPacket();
        if ($packet === null) {
            return self::EXIT_USAGE;
        }
        $class = $classifier->classify($packet);
        $rule = $classifier->reasonFor($packet);
        $assignment = $policy->assignmentFor($class);
        $receiptHash = $ledger->record([
            'task_packet_id' => (string) ($packet['task_packet_id'] ?? $packet['id'] ?? ''),
            'classified_class' => $class,
            'classifier_rule_id' => $rule,
            'primary_provider' => $assignment['primary'],
            'fallback_chain' => $assignment['fallback'],
        ]);
        $this->emit([
            'class' => $class,
            'rule_id' => $rule,
            'assignment' => $assignment,
            'receipt_hash' => $receiptHash,
        ]);

        return self::EXIT_OK;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadPacket(): ?array
    {
        $path = (string) $this->option('packet');
        if ($path === '' || ! is_file($path)) {
            $this->error('--packet=<file> is required and must point to an existing JSON file');

            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->error('packet not valid JSON: '.mb_substr($e->getMessage(), 0, 160));

            return null;
        }
        if (! is_array($decoded)) {
            $this->error('packet root must be a JSON object');

            return null;
        }

        return $decoded;
    }

    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->line($k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }

    private function usage(string $message): int
    {
        $this->error($message);

        return self::EXIT_USAGE;
    }
}
