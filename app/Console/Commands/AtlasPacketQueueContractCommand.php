<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasPacketQueueContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Self-Construction Packet Queue CLI.
 *
 *   php artisan atlas:aaeos:packet-queue-contract
 *     [--packet=AIP-SPLIT-DOCS-0001:docs:none:docs/x/]   // id:lane:risk:allowed_file (repeatable)
 *     [--claimed=AIP-SPLIT-DOCS-0001:RES-1:claude-a:session-a]
 *     [--completed=AIP-SPLIT-DEP-0001:RES-9:codex-a]
 *     [--withheld=AIP-HOT-0001:runtimes/python/voice_realtime/x.py]
 *     [--json]
 *
 * Read-only, deterministic. Decides each packet's queue_state
 * (available|claimed|completed|blocked_by_dependency|withheld), ranks the
 * assignable packets and names the recommended one. It NEVER dispatches work,
 * executes work, marks completion, persists a claim or writes the ledger.
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
 */
class AtlasPacketQueueContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:packet-queue-contract
        {--packet=* : candidate packet as id:lane:collision_risk:allowed_file (repeatable)}
        {--claimed=* : active reservation as packet_id:reservation_id:actor:session}
        {--completed=* : completed reservation as packet_id:reservation_id:actor}
        {--withheld=* : hot external work as packet_id:forbidden_path}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · read-only packet queue deciding queue_state and rank across available, claimed, completed, blocked and withheld packets without dispatch or completion.';

    public function handle(AtlasPacketQueueContractService $service): int
    {
        try {
            $input = [
                'packets' => $this->packets(),
                'active_reservations' => $this->activeReservations(),
                'completed_reservations' => $this->completedReservations(),
                'withheld' => $this->withheld(),
            ];

            if ($input['packets'] === [] && $input['withheld'] === []) {
                $input = $this->defaultDemoInput();
            }

            $result = $service->preview($input);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasPacketQueueContractService::STATUS_READY
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'packet_queue_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function packets(): array
    {
        $out = [];
        foreach ($this->optionList('packet') as $token) {
            $parts = explode(':', $token, 4);
            $id = trim($parts[0] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'packet_id' => $id,
                'lane' => trim($parts[1] ?? '') !== '' ? trim($parts[1]) : 'runtime_read_only',
                'collision_risk' => trim($parts[2] ?? '') !== '' ? trim($parts[2]) : 'none',
                'allowed_files' => trim($parts[3] ?? '') !== '' ? [trim($parts[3])] : [],
                'scope_validator_command' => 'php artisan atlas:engineering:knowledge docs-health --json',
            ];
        }

        return $out;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function activeReservations(): array
    {
        $out = [];
        foreach ($this->optionList('claimed') as $token) {
            [$packetId, $rest] = array_pad(explode(':', $token, 2), 2, '');
            $packetId = trim($packetId);
            if ($packetId === '') {
                continue;
            }
            $fields = explode(':', $rest);
            $out[$packetId] = [
                'reservation_id' => trim($fields[0] ?? '') !== '' ? trim($fields[0]) : 'RES-ACTIVE',
                'actor' => trim($fields[1] ?? '') !== '' ? trim($fields[1]) : 'local-a',
                'session' => trim($fields[2] ?? '') !== '' ? trim($fields[2]) : 'session-a',
                'lease_expires_at' => '2026-06-01T00:00:00Z',
            ];
        }

        return $out;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function completedReservations(): array
    {
        $out = [];
        foreach ($this->optionList('completed') as $token) {
            [$packetId, $rest] = array_pad(explode(':', $token, 2), 2, '');
            $packetId = trim($packetId);
            if ($packetId === '') {
                continue;
            }
            $fields = explode(':', $rest);
            $out[$packetId] = [
                'reservation_id' => trim($fields[0] ?? '') !== '' ? trim($fields[0]) : 'RES-DONE',
                'actor' => trim($fields[1] ?? '') !== '' ? trim($fields[1]) : 'local-a',
                'completed_at' => '2026-06-01T00:00:00Z',
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function withheld(): array
    {
        $out = [];
        foreach ($this->optionList('withheld') as $token) {
            [$packetId, $path] = array_pad(explode(':', $token, 2), 2, '');
            $packetId = trim($packetId);
            if ($packetId === '') {
                continue;
            }
            $out[] = [
                'packet_id' => $packetId,
                'reason' => 'Hot external work owned by another active front.',
                'forbidden_scope' => trim($path) !== '' ? [trim($path)] : [],
            ];
        }

        return $out;
    }

    /**
     * @return array<int,string>
     */
    private function optionList(string $name): array
    {
        $raw = $this->option($name);
        if (is_array($raw)) {
            return array_values(array_filter($raw, static fn ($v): bool => is_string($v) && trim($v) !== ''));
        }

        return is_string($raw) && trim($raw) !== '' ? [$raw] : [];
    }

    /**
     * A safe, self-explaining default queue so the bare command emits a real,
     * deterministic preview (one available, one dependency-blocked, one
     * claimed-elsewhere, one withheld hot packet).
     *
     * @return array<string,mixed>
     */
    private function defaultDemoInput(): array
    {
        return [
            'packets' => [
                [
                    'packet_id' => 'AIP-SPLIT-DOCS-0001',
                    'lane' => 'docs',
                    'objective' => 'Author a documentation contract.',
                    'depends_on' => [],
                    'allowed_files' => ['docs/engineering-knowledge-base/self-construction/'],
                    'collision_risk' => 'none',
                    'scope_validator_command' => 'php artisan atlas:engineering:knowledge docs-health --json',
                ],
                [
                    'packet_id' => 'AIP-SPLIT-DEP-0001',
                    'lane' => 'runtime_read_only',
                    'objective' => 'Add a read-only runtime surface.',
                    'depends_on' => ['AIP-SPLIT-DOCS-0001'],
                    'allowed_files' => ['app/Services/Ai/Foo.php'],
                    'collision_risk' => 'low',
                    'scope_validator_command' => 'php artisan test --filter Foo',
                ],
                [
                    'packet_id' => 'AIP-SPLIT-CLAIMED-0001',
                    'lane' => 'tests',
                    'objective' => 'Focused tests for a read-only surface.',
                    'depends_on' => [],
                    'allowed_files' => ['tests/Unit/Ai/FooTest.php'],
                    'collision_risk' => 'none',
                    'scope_validator_command' => 'php artisan test --filter FooTest',
                ],
            ],
            'active_reservations' => [
                'AIP-SPLIT-CLAIMED-0001' => [
                    'reservation_id' => 'RES-CLAIMED-1',
                    'actor' => 'claude-a',
                    'session' => 'session-a',
                    'lease_expires_at' => '2026-06-01T00:00:00Z',
                ],
            ],
            'completed_reservations' => [],
            'withheld' => [
                [
                    'packet_id' => 'AIP-HOT-0001',
                    'reason' => 'Hot external work owned by another active front.',
                    'forbidden_scope' => ['runtimes/python/voice_realtime/realtime_session.py'],
                ],
            ],
        ];
    }
}
