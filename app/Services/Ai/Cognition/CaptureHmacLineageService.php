<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Models\AtlasKnowledgeSourcePacket;
use App\Models\AtlasMemoryEntry;
use App\Models\Capture;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;

/**
 * MAXI-07 — tamper-evident HMAC-chained provenance across capture stages.
 *
 * receipt_hash_n = HMAC_k(receipt_hash_{n-1} ‖ stage_payload_hash_n)
 *
 * Threat model: adulteration between capture stages on the local machine — not a
 * DB adversary. Key material is local-only and MUST never appear in logs, receipts,
 * or provider projections (only derived receipt hashes are exported).
 */
final class CaptureHmacLineageService
{
    public const SCHEMA_VERSION = 'atlas.capture.hmac_lineage.v1';

    public const GENESIS_RECEIPT = 'atlas.capture.hmac_lineage.genesis.v1';

    public const STAGE_SOURCE = 'source';

    public const STAGE_CAPTURE = 'capture';

    public const STAGE_MEMORY = 'memory';


    public const FIELD_STATUS = 'status';

    public const FIELD_HEAD_RECEIPT_HASH = 'head_receipt_hash';

    public const FIELD_STAGES = 'stages';

    public const FIELD_STAGE_COUNT = 'stage_count';

    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public const FIELD_OK = 'ok';

    public const FIELD_BROKEN = 'broken';

    public const FIELD_LINEAGE = 'lineage';

    public const STAGE_UNKNOWN = 'unknown';


    public const FIELD_RECEIPT_HASH = 'receipt_hash';

    public const FIELD_BROKEN_AT = 'broken_at';
    public const FIELD_STAGE = 'stage';
    public const FIELD_CHAINED_CAPTURES = 'chained_captures';
    public const FIELD_MIN_CAPTURES = 'min_captures';
    public const FIELD_COVERAGE_RATE = 'coverage_rate';
    public const FIELD_NOTE = 'note';
    public const FIELD_REF = 'ref';
    public const FIELD_CHAIN = 'chain';
    public const FIELD_VERIFY = 'verify';
    public const FIELD_SLICE = 'slice';
    public const FIELD_KEY_VERSION = 'key_version';
    public const FIELD_THREAT_MODEL = 'threat_model';
    public const FIELD_STAMPED_AT = 'stamped_at';
    public const FIELD_KIND = 'kind';
    public const FIELD_STAGE_PAYLOAD_HASH = 'stage_payload_hash';
    public const FIELD_PREV_RECEIPT_HASH = 'prev_receipt_hash';
    public const FIELD_SOURCE_PACKET = 'source_packet';
    public const FIELD_HMAC_LINEAGE = 'hmac_lineage';

    public const THREAT_MODEL = 'tamper_between_capture_stages_not_db_adversary';

    public const SECRET_CONFIG_KEY = 'atlas.capture.hmac_lineage_secret';

    public const APP_KEY_CONFIG_KEY = 'app.key';

    public const KEY_MATERIAL_LABEL = 'atlas.capture.hmac_lineage.v1';

    public const KEY_MATERIAL_FALLBACK = 'atlas.capture.hmac_lineage.fallback.v1';

    public const STATUS_UNVERIFIABLE_LEGACY = 'unverifiable_legacy';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_PENDING_WINDOW = 'pending_window';

    public const STATUS_READY = 'ready';

    public const STATUS_NOT_FOUND = 'not_found';

    public const KIND_CAPTURE = 'capture';
    public const FIELD_MEMORY = 'memory';
    public const FIELD_PAYLOAD = 'payload';
    public const FIELD_ID = 'id';
    public const FIELD_CAPTURES = 'captures';
    public const FIELD_ATLAS_KNOWLEDGE_SOURCE_PACKETS = 'atlas_knowledge_source_packets';
    public const FIELD_ATLAS_MEMORY_ENTRIES = 'atlas_memory_entries';
    public const FIELD_DELETED_AT = 'deleted_at';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_CLIENT_ID = 'client_id';
    public const FIELD_CREATED_AT = 'created_at';
    public const FIELD_INVALID_LINK = 'invalid_link';
    public const FIELD_METADATA = 'metadata';
    public const FIELD_SOURCE_HASH = 'source_hash';
    public const FIELD_PACKET = 'packet';
    public const FIELD_MAXI_07 = 'MAXI-07';
    public const FIELD_COGNITIVE_QUARANTINE_LINEAGE_HMAC_LINEAGE = 'cognitive_quarantine.lineage.hmac_lineage';

    /**
     * @param  array<string,mixed>  $existingChain
     * @param  array<string,mixed>  $stagePayload
     * @return array<string,mixed>
     */
    public function stampStage(array $existingChain, string $stage, array $stagePayload): array
    {
        $stagePayloadHash = $this->stagePayloadHash($stage, $stagePayload);
        $prevReceiptHash = $this->headReceiptHash($existingChain) ?? self::GENESIS_RECEIPT;
        $receiptHash = $this->chainLink($prevReceiptHash, $stagePayloadHash);

        $stages = array_values(AiValueNormalizer::arrayOrEmpty($existingChain[self::FIELD_STAGES] ?? null));
        $stages[] = [
            self::FIELD_STAGE => $stage,
            self::FIELD_STAGE_PAYLOAD_HASH => $stagePayloadHash,
            self::FIELD_PREV_RECEIPT_HASH => $prevReceiptHash,
            self::FIELD_RECEIPT_HASH => $receiptHash,
        ];

        return $this->envelope($stages);
    }

    /**
     * @param  array<string,mixed>  $chain
     * @return array{status:string,broken_at:?string,stage_count:int,head_receipt_hash:?string}
     */
    public function verify(array $chain): array
    {
        if (($chain[self::FIELD_SCHEMA_VERSION] ?? null) !== self::SCHEMA_VERSION) {
            return [
                self::FIELD_STATUS => self::STATUS_UNVERIFIABLE_LEGACY,
                self::FIELD_BROKEN_AT => null,
                self::FIELD_STAGE_COUNT => 0,
                self::FIELD_HEAD_RECEIPT_HASH => null,
            ];
        }

        $stages = array_values(AiValueNormalizer::arrayOrEmpty($chain[self::FIELD_STAGES] ?? null));
        if ($stages === []) {
            return [
                self::FIELD_STATUS => self::STATUS_UNVERIFIABLE_LEGACY,
                self::FIELD_BROKEN_AT => null,
                self::FIELD_STAGE_COUNT => 0,
                self::FIELD_HEAD_RECEIPT_HASH => null,
            ];
        }

        $prev = null;
        foreach ($stages as $link) {
            if (! is_array($link)) {
                return [
                    self::FIELD_STATUS => 'broken_at:invalid_link',
                    self::FIELD_BROKEN_AT => self::FIELD_INVALID_LINK,
                    self::FIELD_STAGE_COUNT => count($stages),
                    self::FIELD_HEAD_RECEIPT_HASH => $this->headReceiptHash($chain),
                ];
            }

            $stage = (AiValueNormalizer::trimmedStringOrNull($link[self::FIELD_STAGE] ?? null) ?? self::STAGE_UNKNOWN);
            $stagePayloadHash = (AiValueNormalizer::trimmedStringOrNull($link[self::FIELD_STAGE_PAYLOAD_HASH] ?? null) ?? '');
            $storedPrev = $link[self::FIELD_PREV_RECEIPT_HASH] ?? null;
            $storedReceipt = (AiValueNormalizer::trimmedStringOrNull($link[self::FIELD_RECEIPT_HASH] ?? null) ?? '');

            $expectedPrev = $prev ?? self::GENESIS_RECEIPT;
            $storedPrevHash = AiValueNormalizer::trimmedStringOrNull($storedPrev);
            if ($storedPrevHash === null || $storedPrevHash !== $expectedPrev) {
                return [
                    self::FIELD_STATUS => 'broken_at:'.$stage,
                    self::FIELD_BROKEN_AT => $stage,
                    self::FIELD_STAGE_COUNT => count($stages),
                    self::FIELD_HEAD_RECEIPT_HASH => $this->headReceiptHash($chain),
                ];
            }

            $expectedReceipt = $this->chainLink($expectedPrev, $stagePayloadHash);
            if ($storedReceipt === '' || ! hash_equals($expectedReceipt, $storedReceipt)) {
                return [
                    self::FIELD_STATUS => 'broken_at:'.$stage,
                    self::FIELD_BROKEN_AT => $stage,
                    self::FIELD_STAGE_COUNT => count($stages),
                    self::FIELD_HEAD_RECEIPT_HASH => $this->headReceiptHash($chain),
                ];
            }

            $prev = $storedReceipt;
        }

        return [
            self::FIELD_STATUS => self::STATUS_VERIFIED,
            self::FIELD_BROKEN_AT => null,
            self::FIELD_STAGE_COUNT => count($stages),
            self::FIELD_HEAD_RECEIPT_HASH => $prev,
        ];
    }

    /**
     * @return array{status:string,chained_captures:int,min_captures:int,coverage_rate:?float}
     */
    public function coverageWindowReport(int $minCaptures = 10): array
    {
        if (! DatabaseTableAvailability::has(self::FIELD_CAPTURES)) {
            return [
                self::FIELD_STATUS => self::STATUS_PENDING_WINDOW,
                self::FIELD_CHAINED_CAPTURES => 0,
                self::FIELD_MIN_CAPTURES => $minCaptures,
                self::FIELD_COVERAGE_RATE => null,
                self::FIELD_NOTE => 'captures table unavailable',
            ];
        }

        $rows = DB::table('captures')
            ->whereNull(self::FIELD_DELETED_AT)
            ->orderByDesc(self::FIELD_CREATED_AT)
            ->limit(max($minCaptures * 4, 40))
            ->get(['id', self::FIELD_METADATA, 'created_at']);

        $recent = $rows->take($minCaptures);
        $chained = $recent->filter(function (object $row): bool {
            $metadata = AiValueNormalizer::arrayOrEmpty(json_decode((string) ($row->metadata ?? '{}'), true));

            return is_array(data_get($metadata, self::FIELD_COGNITIVE_QUARANTINE_LINEAGE_HMAC_LINEAGE));
        });

        $chainedCount = $chained->count();
        $denominator = $recent->count();
        $fullCoverage = $denominator >= $minCaptures && $chainedCount === $denominator;

        return [
            self::FIELD_STATUS => $fullCoverage ? self::STATUS_READY : self::STATUS_PENDING_WINDOW,
            self::FIELD_CHAINED_CAPTURES => $chainedCount,
            self::FIELD_MIN_CAPTURES => $minCaptures,
            self::FIELD_COVERAGE_RATE => $denominator > 0 ? round($chainedCount / $denominator, 4) : null,
            self::FIELD_NOTE => $fullCoverage
                ? '100% of recent captures carry hmac_lineage'
                : 'MAXI-07 aceite pleno awaits ≥'.$minCaptures.' new captures with chained lineage',
        ];
    }

    /**
     * @return array{ok:bool,status:string,ref:string,chain:array<string,mixed>|null,verify:array<string,mixed>}
     */
    public function verifyRef(string $ref): array
    {
        $parsed = $this->parseRef($ref);
        $chain = $this->resolveChain($parsed[self::FIELD_KIND], $parsed[self::FIELD_ID]);

        if ($chain === null) {
            return [
                self::FIELD_OK => false,
                self::FIELD_STATUS => self::STATUS_NOT_FOUND,
                self::FIELD_REF => $ref,
                self::FIELD_CHAIN => null,
                self::FIELD_VERIFY => [
                    self::FIELD_STATUS => self::STATUS_NOT_FOUND,
                    self::FIELD_BROKEN_AT => null,
                    self::FIELD_STAGE_COUNT => 0,
                    self::FIELD_HEAD_RECEIPT_HASH => null,
                ],
            ];
        }

        $verify = $this->verify($chain);

        return [
            self::FIELD_OK => $verify[self::FIELD_STATUS] === self::STATUS_VERIFIED,
            self::FIELD_STATUS => $verify[self::FIELD_STATUS],
            self::FIELD_REF => $ref,
            self::FIELD_CHAIN => $this->providerSafeChain($chain),
            self::FIELD_VERIFY => $verify,
        ];
    }

    public function stagePayloadHash(string $stage, array $payload): string
    {
        return hash(self::FIELD_SHA256, (string) json_encode([
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STAGE => $stage,
            self::FIELD_PAYLOAD => $this->sortKeysRecursive($payload),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function chainLink(?string $prevReceiptHash, string $stagePayloadHash): string
    {
        $prev = $prevReceiptHash ?? self::GENESIS_RECEIPT;

        return hash_hmac(self::FIELD_SHA256, $prev.'|'.$stagePayloadHash, $this->keyMaterial(), false);
    }

    /**
     * @param  array<string,mixed>  $chain
     */
    public function headReceiptHash(array $chain): ?string
    {
        $stages = array_values(AiValueNormalizer::arrayOrEmpty($chain[self::FIELD_STAGES] ?? null));
        if ($stages === []) {
            return null;
        }
        $last = $stages[count($stages) - 1];

        return is_array($last) ? (AiValueNormalizer::trimmedStringOrNull($last[self::FIELD_RECEIPT_HASH] ?? null) ?? '') : null;
    }

    /**
     * @param  list<array<string,mixed>>  $stages
     * @return array<string,mixed>
     */
    public function envelope(array $stages): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_SLICE => self::FIELD_MAXI_07,
            self::FIELD_KEY_VERSION => 1,
            self::FIELD_THREAT_MODEL => self::THREAT_MODEL,
            self::FIELD_STAGES => $stages,
            self::FIELD_HEAD_RECEIPT_HASH => $this->headReceiptHash([self::FIELD_STAGES => $stages]),
            self::FIELD_STAMPED_AT => now()->toJSON(),
        ];
    }

    /**
     * Provider-safe export — never includes key material.
     *
     * @param  array<string,mixed>  $chain
     * @return array<string,mixed>
     */
    public function providerSafeChain(array $chain): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => $chain[self::FIELD_SCHEMA_VERSION] ?? self::SCHEMA_VERSION,
            self::FIELD_SLICE => $chain[self::FIELD_SLICE] ?? self::FIELD_MAXI_07,
            self::FIELD_KEY_VERSION => $chain[self::FIELD_KEY_VERSION] ?? 1,
            self::FIELD_THREAT_MODEL => $chain[self::FIELD_THREAT_MODEL] ?? self::THREAT_MODEL,
            self::FIELD_STAGES => array_values(AiValueNormalizer::arrayOrEmpty($chain[self::FIELD_STAGES] ?? null)),
            self::FIELD_HEAD_RECEIPT_HASH => $chain[self::FIELD_HEAD_RECEIPT_HASH] ?? $this->headReceiptHash($chain),
            self::FIELD_STAMPED_AT => $chain[self::FIELD_STAMPED_AT] ?? null,
        ];
    }

    /**
     * @return array{kind:string,id:string}
     */
    private function parseRef(string $ref): array
    {
        $trimmed = AiValueNormalizer::trimmedStringOrNull($ref) ?? '';
        if (str_contains($trimmed, ':')) {
            [$kind, $id] = explode(':', $trimmed, 2);

            return [self::FIELD_KIND => AiValueNormalizer::lowerTrimmedString($kind), self::FIELD_ID => $id];
        }

        return [self::FIELD_KIND => self::KIND_CAPTURE, self::FIELD_ID => $trimmed];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveChain(string $kind, string $id): ?array
    {
        return match ($kind) {
            self::FIELD_PACKET, self::FIELD_SOURCE_PACKET => $this->chainFromPacket($id),
            self::FIELD_MEMORY => $this->chainFromMemory($id),
            default => $this->chainFromCapture($id),
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function chainFromCapture(string $id): ?array
    {
        if (! DatabaseTableAvailability::has(self::FIELD_CAPTURES)) {
            return null;
        }

        $capture = Capture::query()
            ->where(function ($query) use ($id): void {
                $query->where('id', $id)->orWhere(self::FIELD_CLIENT_ID, $id);
            })
            ->first();

        if ($capture === null) {
            return null;
        }

        $chain = data_get($capture->metadata, self::FIELD_COGNITIVE_QUARANTINE_LINEAGE_HMAC_LINEAGE);

        return is_array($chain) ? $chain : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function chainFromPacket(string $id): ?array
    {
        if (! DatabaseTableAvailability::has(self::FIELD_ATLAS_KNOWLEDGE_SOURCE_PACKETS)) {
            return null;
        }

        $packet = AtlasKnowledgeSourcePacket::query()
            ->where('id', $id)
            ->orWhere(self::FIELD_SOURCE_HASH, AiValueNormalizer::lowerTrimmedString($id))
            ->first();

        if ($packet === null) {
            return null;
        }

        $lineage = AiValueNormalizer::arrayOrEmpty($packet->lineage);
        $chain = $lineage[self::FIELD_HMAC_LINEAGE] ?? null;

        return is_array($chain) ? $chain : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function chainFromMemory(string $id): ?array
    {
        if (! DatabaseTableAvailability::has(self::FIELD_ATLAS_MEMORY_ENTRIES)) {
            return null;
        }

        $entry = AtlasMemoryEntry::query()->find($id);
        if ($entry === null) {
            return null;
        }

        $chain = data_get($entry->metadata, 'acos_max.maxi_07.capture_hmac_lineage');

        return is_array($chain) ? $chain : null;
    }

    private function keyMaterial(): string
    {
        $configured = config(self::SECRET_CONFIG_KEY);
        $configuredSecret = AiValueNormalizer::trimmedStringOrNull($configured);
        if ($configuredSecret !== null) {
            return hash_hmac(self::FIELD_SHA256, self::KEY_MATERIAL_LABEL, $configuredSecret, true);
        }

        $appKey = AiValueNormalizer::trimmedStringOrNull(config(self::APP_KEY_CONFIG_KEY, '')) ?? '';

        return hash_hmac(
            self::FIELD_SHA256,
            self::KEY_MATERIAL_LABEL,
            $appKey !== '' ? $appKey : self::KEY_MATERIAL_FALLBACK,
            true,
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sortKeysRecursive(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortKeysRecursive($value);
            }
        }

        return $payload;
    }
}
