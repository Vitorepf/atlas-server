<?php

namespace App\Services\Ai\Programming\Forge;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Transactional owner for Forge's canonical scope leases. */
class ForgeScopeReservationService
{
    private const TABLE = 'atlas_task_scope_reservations';

    public function __construct(private readonly AtlasEvidenceLedger $ledger) {}

    /** @return array{acquired:bool,replayed:bool,reservation:array<string,mixed>|null,reason?:string} */
    public function acquire(
        string $runId,
        string $scopePath,
        string $mode,
        string $leaseOwner,
        string $leaseToken,
        string $authorityHash,
        string $baselineHash,
        string $idempotencyKey,
        int $leaseSeconds,
    ): array {
        $path = $this->canonicalPath($scopePath);
        $scopeKey = hash('sha256', $path);
        $now = Carbon::now();
        $posture = (string) config('atlas.forge.scope_reservations.posture', 'enforce');

        $result = DB::transaction(function () use (
            $runId, $path, $scopeKey, $mode, $leaseOwner, $leaseToken,
            $authorityHash, $baselineHash, $idempotencyKey, $leaseSeconds, $now, $posture,
        ): array {
            $replay = DB::table(self::TABLE)->where('idempotency_key', $idempotencyKey)->first();
            if ($replay !== null) {
                $this->assertReplayContract($replay, [
                    'run_id' => $runId,
                    'canonical_scope_key' => $scopeKey,
                    'mode' => $mode,
                    'lease_owner' => $leaseOwner,
                    'lease_token' => $leaseToken,
                    'authority_hash' => $authorityHash,
                    'baseline_hash' => $baselineHash,
                ]);

                return ['acquired' => true, 'replayed' => true, 'reservation' => $this->receipt($replay)];
            }

            if (in_array($posture, ['disabled', 'drain'], true)) {
                return ['acquired' => false, 'replayed' => false, 'reservation' => null, 'reason' => 'acquisition_'.$posture];
            }

            $active = DB::table(self::TABLE)
                ->where('active_scope_key', $scopeKey)
                ->lockForUpdate()
                ->first();
            if ($active !== null && Carbon::parse($active->lease_expires_at)->isFuture()) {
                return ['acquired' => false, 'replayed' => false, 'reservation' => $this->receipt($active)];
            }
            if ($active !== null) {
                DB::table(self::TABLE)->where('id', $active->id)->update([
                    'state' => 'expired',
                    'active_scope_key' => null,
                    'released_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $fence = ((int) DB::table(self::TABLE)
                ->where('canonical_scope_key', $scopeKey)
                ->max('fencing_token')) + 1;
            $id = (string) Str::uuid();
            $row = [
                'id' => $id,
                'run_id' => $runId,
                'canonical_scope_key' => $scopeKey,
                'scope_path' => $path,
                'active_scope_key' => $scopeKey,
                'mode' => $mode,
                'lease_owner' => $leaseOwner,
                'lease_token' => $leaseToken,
                'authority_hash' => $authorityHash,
                'state' => 'active',
                'fencing_token' => $fence,
                'baseline_hash' => $baselineHash,
                'idempotency_key' => $idempotencyKey,
                'lease_expires_at' => $now->copy()->addSeconds(max(1, $leaseSeconds)),
                'released_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $inserted = DB::table(self::TABLE)->insertOrIgnore($row);
            if ($inserted === 0) {
                $idempotentWinner = DB::table(self::TABLE)->where('idempotency_key', $idempotencyKey)->first();
                if ($idempotentWinner !== null) {
                    $this->assertReplayContract($idempotentWinner, [
                        'run_id' => $runId,
                        'canonical_scope_key' => $scopeKey,
                        'mode' => $mode,
                        'lease_owner' => $leaseOwner,
                        'lease_token' => $leaseToken,
                        'authority_hash' => $authorityHash,
                        'baseline_hash' => $baselineHash,
                    ]);

                    return ['acquired' => true, 'replayed' => true, 'reservation' => $this->receipt($idempotentWinner)];
                }

                $winner = DB::table(self::TABLE)->where('active_scope_key', $scopeKey)->first();
                if ($winner === null) {
                    throw new \RuntimeException('atlas.forge.scope_reservation: conflict winner could not be reconstructed');
                }

                return ['acquired' => false, 'replayed' => false, 'reservation' => $this->receipt($winner)];
            }

            return ['acquired' => true, 'replayed' => false, 'reservation' => $this->receipt((object) $row)];
        }, 3);

        if ($result['acquired'] && ! $result['replayed']) {
            $this->emitAfterCommit('acquired', $result['reservation']);
        }

        return $result;
    }

    /** @return array{renewed:bool,reservation:array<string,mixed>|null} */
    public function renew(string $id, string $owner, string $token, int $fence, int $leaseSeconds): array
    {
        $now = Carbon::now();
        $affected = DB::table(self::TABLE)
            ->where('id', $id)->where('state', 'active')->where('lease_owner', $owner)
            ->where('lease_token', $token)->where('fencing_token', $fence)
            ->where('lease_expires_at', '>', $now)
            ->update(['lease_expires_at' => $now->copy()->addSeconds(max(1, $leaseSeconds)), 'updated_at' => $now]);
        $reservation = $this->reconstruct($id);
        if ($affected === 1 && $reservation !== null) {
            $this->emitAfterCommit('renewed', $reservation);
        }

        return ['renewed' => $affected === 1, 'reservation' => $reservation];
    }

    /** @return array{released:bool,reservation:array<string,mixed>|null} */
    public function release(string $id, string $owner, string $token, int $fence, string $state = 'released'): array
    {
        $now = Carbon::now();
        $affected = DB::table(self::TABLE)
            ->where('id', $id)->where('state', 'active')->where('lease_owner', $owner)
            ->where('lease_token', $token)->where('fencing_token', $fence)
            ->where('lease_expires_at', '>', $now)
            ->update([
                'state' => $state,
                'active_scope_key' => null,
                'released_at' => $now,
                'updated_at' => $now,
            ]);
        $reservation = $this->reconstruct($id);
        if ($affected === 1 && $reservation !== null) {
            $this->emitAfterCommit($state, $reservation);
        }

        return ['released' => $affected === 1, 'reservation' => $reservation];
    }

    /** @return array<string,mixed>|null */
    public function reconstruct(string $id): ?array
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();

        return $row === null ? null : $this->receipt($row);
    }

    /** @return array<string,mixed> */
    private function receipt(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'run_id' => (string) $row->run_id,
            'canonical_scope_key' => (string) $row->canonical_scope_key,
            'scope_path' => (string) $row->scope_path,
            'mode' => (string) $row->mode,
            'lease_owner' => (string) $row->lease_owner,
            'lease_token' => (string) $row->lease_token,
            'authority_hash' => (string) $row->authority_hash,
            'state' => (string) $row->state,
            'fencing_token' => (int) $row->fencing_token,
            'baseline_hash' => (string) $row->baseline_hash,
            'idempotency_key' => (string) $row->idempotency_key,
            'lease_expires_at' => Carbon::parse($row->lease_expires_at)->toISOString(),
            'released_at' => $row->released_at === null ? null : Carbon::parse($row->released_at)->toISOString(),
        ];
    }

    private function canonicalPath(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', trim($path))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /** @param array<string,string> $expected */
    private function assertReplayContract(object $row, array $expected): void
    {
        foreach ($expected as $field => $value) {
            if ((string) $row->{$field} !== $value) {
                throw new \RuntimeException('atlas.forge.scope_reservation: idempotency contract mismatch for '.$field);
            }
        }
    }

    /** @param array<string,mixed> $reservation */
    private function emitAfterCommit(string $action, array $reservation): void
    {
        DB::afterCommit(function () use ($action, $reservation): void {
            $this->ledger->record(LedgerEventType::ToolEvidenceRecorded, [
                'receipt_id' => 'forge-reservation:'.$reservation['id'].':'.$reservation['fencing_token'].':'.$action,
                'scope_type' => 'forge_reservation',
                'scope_id' => $reservation['id'],
                'action' => $action,
                'reservation' => $reservation,
            ], [
                'envelope_id' => $reservation['run_id'],
                'correlation_id' => $reservation['run_id'],
                'scope_type' => 'forge_reservation',
                'scope_id' => $reservation['id'],
                'emitter_stage' => 'atlas.forge.scope_reservation',
            ]);
        });
    }
}
