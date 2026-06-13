<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObraBridgeService;
use Illuminate\Console\Command;

/**
 * L5-2: governed Loop -> Obra bridge preflight.
 */
final class AtlasLoopObraBridgeCommand extends Command
{
    protected $signature = 'atlas:loop:obra-bridge
        {--intent= : Loop intent summary}
        {--file=* : Candidate target file, repeatable}
        {--l4-10-evidence= : Real L4-10 Forge/Obra receipt path}
        {--delivery-receipt= : Real Loop->Obra delivery receipt linked to the bridge packet}
        {--create-proposal : Persist a self-improvement backlog draft when the bridge is ready}
        {--no-prioritize : Do not compute backlog priority after creating the draft}
        {--write : Persist the bridge receipt JSON}
        {--path= : Explicit bridge receipt JSON path}
        {--require-delivered : Return failure unless a real multi-file delivery receipt certifies L5-2}
        {--strict : Return failure unless the bridge is ready for operator review}
        {--json : Machine-readable JSON output}';

    protected $description = 'Build a governed Forge handoff packet for multi-file Loop intents without executing Forge.';

    public function handle(AtlasLoopObraBridgeService $bridge): int
    {
        $payload = $bridge->bridge([
            'intent' => $this->stringOption('intent'),
            'files' => (array) $this->option('file'),
            'l4_10_evidence_path' => $this->stringOption('l4-10-evidence'),
            'delivery_receipt_path' => $this->stringOption('delivery-receipt'),
            'create_proposal' => (bool) $this->option('create-proposal'),
            'prioritize_proposal' => ! (bool) $this->option('no-prioritize'),
            'write_receipt' => (bool) $this->option('write'),
            'receipt_path' => $this->stringOption('path'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHuman($payload);
        }

        if ((bool) $this->option('require-delivered') && ($payload['status'] ?? null) !== 'delivered') {
            return self::FAILURE;
        }

        if ((bool) $this->option('strict') && ! in_array(($payload['status'] ?? null), ['ready_for_operator_review', 'delivered'], true)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Loop -> Obra Bridge</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Files', (string) count((array) data_get($payload, 'bridge_packet.target_files', [])));
        $this->components->twoColumnDetail('L4-10 certified', data_get($payload, 'l4_10_gate.certified') ? 'yes' : 'no');
        $this->components->twoColumnDetail('Auto-execute', data_get($payload, 'operator_approval.auto_execute_allowed') ? 'allowed' : 'blocked');
        if (($path = data_get($payload, 'written_receipt_path')) !== null) {
            $this->components->twoColumnDetail('Receipt path', (string) $path);
        }
        foreach ((array) ($payload['blockers'] ?? []) as $blocker) {
            $this->warn((string) $blocker);
        }
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
