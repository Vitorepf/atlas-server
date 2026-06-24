<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

use Illuminate\Support\Str;
use PDO;
use RuntimeException;

/**
 * QUATERNITY · DIALOGUE-DRIVEN PACKET LEDGER — append-only SQLite ledger that records every state transition
 * in the dialogue-to-packet pipeline. Each row carries a sha256 chain link
 * ({@see DialogueLedgerChainHasher}) so {@see verifyChain()} detects any historical mutation. The transition
 * policy enforces order ({@see DialogueLedgerTransitionPolicy}).
 *
 * INVARIANTS:
 *   - APPEND-ONLY: no public update() / delete() method — only {@see append()}, {@see get()},
 *     {@see verifyChain()}, {@see list()}.
 *   - OPERATOR PRIMITIVE: any Approved or Rejected event with null operator_signature is rejected — this is
 *     the QUATERNITY invariant proving the operator was a real primitive, not optional.
 *   - CHAIN INTEGRITY: this_row_hash = sha256(canonical body including prev_row_hash); verifyChain() walks
 *     genesis→tail recomputing each row.
 *
 * STORAGE: a single SQLite table `dialogue_ledger_events` at storage/atlas/maestro/dialogue/ledger.sqlite (or
 * an injected path / :memory: for tests).
 */
final class AtlasMaestroDialogueDrivenPacketLedger
{
    public const TABLE = 'dialogue_ledger_events';

    /** @var null|callable():int */
    private $clock;

    private readonly PDO $pdo;

    public function __construct(?string $sqlitePath = null, ?callable $clock = null)
    {
        $this->clock = $clock;
        $path = $sqlitePath ?? self::defaultPath();
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        $this->pdo = new PDO('sqlite:'.$path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    /**
     * @param  array{
     *     event_type:string,
     *     shape_hash:string,
     *     cortex_snapshot_hash:string,
     *     operator_signature?:?string,
     *     payload?:array<string,mixed>
     * }  $fields
     */
    public function append(array $fields): DialogueLedgerEvent
    {
        $eventType = (string) ($fields['event_type'] ?? '');
        if (! in_array($eventType, DialogueLedgerEvent::ALL_TYPES, true)) {
            throw new RuntimeException('Unknown dialogue ledger event type: '.$eventType);
        }
        $shapeHash = (string) ($fields['shape_hash'] ?? '');
        if ($shapeHash === '') {
            throw new RuntimeException('shape_hash is required');
        }

        $operatorSig = $fields['operator_signature'] ?? null;
        if (in_array($eventType, [DialogueLedgerEvent::TYPE_APPROVED, DialogueLedgerEvent::TYPE_REJECTED], true)
            && ($operatorSig === null || $operatorSig === '')) {
            throw new RuntimeException('Approved/Rejected events REQUIRE a non-null operator_signature (operator is a real primitive)');
        }

        $currentState = $this->stateForShape($shapeHash);
        DialogueLedgerTransitionPolicy::assertAllowed($currentState, $eventType, $shapeHash);

        $parentUlid = $this->lastUlidForShape($shapeHash);
        $prevRowHash = $this->lastRowHashGlobal();
        $ulid = (string) Str::ulid();
        $createdAt = gmdate(DATE_ATOM, $this->now());

        $event = new DialogueLedgerEvent(
            ulid: $ulid,
            parentUlid: $parentUlid,
            eventType: $eventType,
            shapeHash: $shapeHash,
            cortexSnapshotHash: (string) ($fields['cortex_snapshot_hash'] ?? ''),
            operatorSignature: $operatorSig === null ? null : (string) $operatorSig,
            payload: is_array($fields['payload'] ?? null) ? $fields['payload'] : [],
            createdAtUtc: $createdAt,
            prevRowHash: $prevRowHash,
            thisRowHash: '', // computed next
        );
        $thisRowHash = DialogueLedgerChainHasher::hashRow($event);
        $event = new DialogueLedgerEvent(
            ulid: $event->ulid,
            parentUlid: $event->parentUlid,
            eventType: $event->eventType,
            shapeHash: $event->shapeHash,
            cortexSnapshotHash: $event->cortexSnapshotHash,
            operatorSignature: $event->operatorSignature,
            payload: $event->payload,
            createdAtUtc: $event->createdAtUtc,
            prevRowHash: $event->prevRowHash,
            thisRowHash: $thisRowHash,
        );

        $this->pdo->prepare(
            'INSERT INTO '.self::TABLE.' (ulid, parent_ulid, event_type, shape_hash, cortex_snapshot_hash, operator_signature, payload_json, created_at_utc, prev_row_hash, this_row_hash) VALUES (:ulid, :parent_ulid, :event_type, :shape_hash, :cortex_snapshot_hash, :operator_signature, :payload_json, :created_at_utc, :prev_row_hash, :this_row_hash)'
        )->execute([
            'ulid' => $event->ulid,
            'parent_ulid' => $event->parentUlid,
            'event_type' => $event->eventType,
            'shape_hash' => $event->shapeHash,
            'cortex_snapshot_hash' => $event->cortexSnapshotHash,
            'operator_signature' => $event->operatorSignature,
            'payload_json' => (string) json_encode($event->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at_utc' => $event->createdAtUtc,
            'prev_row_hash' => $event->prevRowHash,
            'this_row_hash' => $event->thisRowHash,
        ]);

        return $event;
    }

    public function get(string $ulid): ?DialogueLedgerEvent
    {
        $stmt = $this->pdo->prepare('SELECT * FROM '.self::TABLE.' WHERE ulid = :ulid LIMIT 1');
        $stmt->execute(['ulid' => $ulid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate((array) $row);
    }

    /**
     * @return list<DialogueLedgerEvent>
     */
    public function list(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM '.self::TABLE.' ORDER BY rowid ASC');
        $out = [];
        foreach (($stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $out[] = $this->hydrate((array) $row);
        }

        return $out;
    }

    /**
     * @return array{ok:bool, total:int, broken_ulid:?string, computed_hash:?string, stored_hash:?string}
     */
    public function verifyChain(): array
    {
        $events = $this->list();
        $expectedPrev = DialogueLedgerChainHasher::GENESIS_PREV_HASH;
        foreach ($events as $event) {
            if (! hash_equals($expectedPrev, $event->prevRowHash)) {
                return ['ok' => false, 'total' => count($events), 'broken_ulid' => $event->ulid, 'computed_hash' => $expectedPrev, 'stored_hash' => $event->prevRowHash];
            }
            $recomputed = DialogueLedgerChainHasher::hashRow($event);
            if (! hash_equals($recomputed, $event->thisRowHash)) {
                return ['ok' => false, 'total' => count($events), 'broken_ulid' => $event->ulid, 'computed_hash' => $recomputed, 'stored_hash' => $event->thisRowHash];
            }
            $expectedPrev = $event->thisRowHash;
        }

        return ['ok' => true, 'total' => count($events), 'broken_ulid' => null, 'computed_hash' => null, 'stored_hash' => null];
    }

    private function stateForShape(string $shapeHash): string
    {
        $stmt = $this->pdo->prepare('SELECT event_type FROM '.self::TABLE.' WHERE shape_hash = :sh ORDER BY rowid DESC LIMIT 1');
        $stmt->execute(['sh' => $shapeHash]);
        $last = $stmt->fetchColumn();

        return $last === false ? DialogueLedgerTransitionPolicy::STATE_GENESIS : (string) $last;
    }

    private function lastUlidForShape(string $shapeHash): ?string
    {
        $stmt = $this->pdo->prepare('SELECT ulid FROM '.self::TABLE.' WHERE shape_hash = :sh ORDER BY rowid DESC LIMIT 1');
        $stmt->execute(['sh' => $shapeHash]);
        $last = $stmt->fetchColumn();

        return $last === false ? null : (string) $last;
    }

    private function lastRowHashGlobal(): string
    {
        $stmt = $this->pdo->query('SELECT this_row_hash FROM '.self::TABLE.' ORDER BY rowid DESC LIMIT 1');
        $last = $stmt === false ? false : $stmt->fetchColumn();

        return $last === false ? DialogueLedgerChainHasher::GENESIS_PREV_HASH : (string) $last;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function hydrate(array $row): DialogueLedgerEvent
    {
        $payload = json_decode((string) ($row['payload_json'] ?? '[]'), true);

        return new DialogueLedgerEvent(
            ulid: (string) ($row['ulid'] ?? ''),
            parentUlid: isset($row['parent_ulid']) && $row['parent_ulid'] !== null ? (string) $row['parent_ulid'] : null,
            eventType: (string) ($row['event_type'] ?? ''),
            shapeHash: (string) ($row['shape_hash'] ?? ''),
            cortexSnapshotHash: (string) ($row['cortex_snapshot_hash'] ?? ''),
            operatorSignature: isset($row['operator_signature']) && $row['operator_signature'] !== null ? (string) $row['operator_signature'] : null,
            payload: is_array($payload) ? $payload : [],
            createdAtUtc: (string) ($row['created_at_utc'] ?? ''),
            prevRowHash: (string) ($row['prev_row_hash'] ?? ''),
            thisRowHash: (string) ($row['this_row_hash'] ?? ''),
        );
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                rowid INTEGER PRIMARY KEY AUTOINCREMENT,
                ulid TEXT NOT NULL UNIQUE,
                parent_ulid TEXT NULL,
                event_type TEXT NOT NULL,
                shape_hash TEXT NOT NULL,
                cortex_snapshot_hash TEXT NOT NULL,
                operator_signature TEXT NULL,
                payload_json TEXT NOT NULL,
                created_at_utc TEXT NOT NULL,
                prev_row_hash TEXT NOT NULL,
                this_row_hash TEXT NOT NULL
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_'.self::TABLE.'_shape ON '.self::TABLE.' (shape_hash)');
    }

    private function now(): int
    {
        $clock = $this->clock;
        if (is_callable($clock)) {
            return (int) $clock();
        }

        return time();
    }

    private static function defaultPath(): string
    {
        if (function_exists('storage_path')) {
            try {
                return storage_path('atlas/maestro/dialogue/ledger.sqlite');
            } catch (\Throwable) {
            }
        }

        return sys_get_temp_dir().'/atlas-maestro-dialogue-ledger.sqlite';
    }
}
