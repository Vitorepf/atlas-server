<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;

/**
 * B2a (fechamento ACOS) — the PRODUCER that was missing: enqueue a Kit-conformant
 * work-order (the Ordem that `atlas:obra:work-order` / K2 emits) into the LIVE task
 * queue, carrying its kit fields (glossary, frozen_callers, acceptance_test_ref,
 * stop_and_return, baseline_artifact) THROUGH the builder — which now preserves them
 * instead of stripping them — so K3 (source linter) and K4 (conformance gate) evaluate a
 * real Ordem instead of the fail-safe-pass empty. Without this door, K2/K3/K4 were
 * dormant: nothing fed a kit-shaped order into the live queue.
 */
final class AtlasObraEnqueueWorkOrderCommand extends Command
{
    protected $signature = 'atlas:obra:enqueue-work-order
        {wo : path to a Kit work-order JSON (output of atlas:obra:work-order)}
        {--operator-id=operator-intake : operator id stamped on the packet}
        {--json : machine-readable output}';

    protected $description = 'B2a · enfileira uma Ordem (work-order Kit) na fila viva → K2/K3/K4 avaliam de verdade';

    public function handle(): int
    {
        $path = (string) $this->argument('wo');
        if (! is_file($path)) {
            $this->error("work-order não encontrado: {$path}");

            return self::FAILURE;
        }

        $wo = json_decode((string) file_get_contents($path), true);
        if (! is_array($wo)) {
            $this->error('work-order JSON inválido');

            return self::FAILURE;
        }

        $allowed = array_values(array_map('strval', (array) ($wo['allowed_files'] ?? [])));

        $packetInput = [
            'objective' => (string) ($wo['objective'] ?? ''),
            'operator_id' => (string) $this->option('operator-id'),
            'source' => 'obra_work_order',
            'allowed_files' => $allowed,
            'forbidden_files' => array_values(array_map('strval', (array) ($wo['forbidden_files'] ?? []))),
            'scope_in' => $allowed,
            'acceptance_criteria' => array_values(array_map('strval', (array) ($wo['acceptance_criteria'] ?? []))),
            'required_evidence' => ['task_packet_created'],
            // Kit-order (Ordem) envelope — the builder now carries these to K3/K4.
            'glossary' => (array) ($wo['glossary'] ?? []),
            'frozen_callers' => (array) ($wo['frozen_callers'] ?? []),
            'acceptance_test_ref' => (array) ($wo['acceptance_test_ref'] ?? []),
            'stop_and_return' => (array) ($wo['stop_and_return'] ?? []),
            'baseline_artifact' => is_array($wo['baseline_artifact'] ?? null) ? $wo['baseline_artifact'] : [],
        ];

        $env = AtlasTaskServingStack::orchestrator()->prepareAndEnqueue(['task_packet' => $packetInput]);
        $status = (string) ($env['status'] ?? 'unknown');
        $blocked = $status === 'prepare_blocked';
        $packetId = (string) ($env['task_packet_id']
            ?? data_get($env, 'queue_entry.task_packet_id')
            ?? data_get($env, 'task_packet.task_packet_id', ''));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $status,
                'task_packet_id' => $packetId !== '' ? $packetId : null,
                'reason' => $env['reason'] ?? null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $blocked ? self::FAILURE : self::SUCCESS;
        }

        if ($blocked) {
            $this->warn('Ordem barrada na admissão: '.(string) ($env['reason'] ?? 'sem motivo'));

            return self::FAILURE;
        }

        $this->info("Ordem enfileirada (status={$status}, packet={$packetId}).");

        return self::SUCCESS;
    }
}
