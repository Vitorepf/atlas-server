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

    public const THREAT_MODEL = 'tamper_between_capture_stages_not_db_adversary';

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

        $stages = array_values(AiValueNormalizer::arrayOrEmpty($existingChain['stages'] ?? null));
        $stages[] = [
            'stage' => $stage,
            'stage_payload_hash' => $stagePayloadHash,
            'prev_receipt_hash' => $prevReceiptHash,
            'receipt_hash' => $receiptHash,
        ];

        return $this->envelope($stages);
    }

    /**
     * @param  array<string,mixed>  $chain
     * @return array{status:string,broken_at:?string,stage_count:int,head_receipt_hash:?string}
     */
    public function verify(array $chain): array
    {
        if (($chain['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return [
                'status' => 'unverifiable_legacy',
                'broken_at' => null,
                'stage_count' => 0,
                'head_receipt_hash' => null,
            ];
        }

        $stages = array_values(AiValueNormalizer::arrayOrEmpty($chain['stages'] ?? null));
        if ($stages === []) {
            return [
                'status' => 'unverifiable_legacy',
                'broken_at' => null,
                'stage_count' => 0,
                'head_receipt_hash' => null,
            ];
        }

        $prev = null;
        foreach ($stages as $link) {
            if (! is_array($link)) {
                return [
                    'status' => 'broken_at:invalid_link',
                    'broken_at' => 'invalid_link',
                    'stage_count' => count($stages),
                    'head_receipt_hash' => $this->headReceiptHash($chain),
                ];
            }

            $stage = (string) ($link['stage'] ?? 'unknown');
            $stagePayloadHash = (string) ($link['stage_payload_hash'] ?? '');
            $storedPrev = $link['prev_receipt_hash'] ?? null;
            $storedReceipt = (string) ($link['receipt_hash'] ?? '');

            $expectedPrev = $prev ?? self::GENESIS_RECEIPT;
            $storedPrevHash = AiValueNormalizer::trimmedStringOrNull($storedPrev);
            if ($storedPrevHash === null || $storedPrevHash !== $expectedPrev) {
                return [
                    'status' => 'broken_at:'.$stage,
                    'broken_at' => $stage,
                    'stage_count' => count($stages),
                    'head_receipt_hash' => $this->headReceiptHash($chain),
                ];
            }

            $expectedReceipt = $this->chainLink($expectedPrev, $stagePayloadHash);
            if ($storedReceipt === '' || ! hash_equals($expectedReceipt, $storedReceipt)) {
                return [
                    'status' => 'broken_at:'.$stage,
                    'broken_at' => $stage,
                    'stage_count' => count($stages),
                    'head_receipt_hash' => $this->headReceiptHash($chain),
                ];
            }

            $prev = $storedReceipt;
        }

        return [
            'status' => 'verified',
            'broken_at' => null,
            'stage_count' => count($stages),
            'head_receipt_hash' => $prev,
        ];
    }

    /**
     * @return array{status:string,chained_captures:int,min_captures:int,coverage_rate:?float}
     */
    public function coverageWindowReport(int $minCaptures = 10): array
    {
        if (! DatabaseTableAvailability::has('captures')) {
            return [
                'status' => 'pending_window',
                'chained_captures' => 0,
                'min_captures' => $minCaptures,
                'coverage_rate' => null,
                'note' => 'captures table unavailable',
            ];
        }

        $rows = DB::table('captures')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(max($minCaptures * 4, 40))
            ->get(['id', 'metadata', 'created_at']);

        $recent = $rows->take($minCaptures);
        $chained = $recent->filter(function (object $row): bool {
            $metadata = AiValueNormalizer::arrayOrEmpty(json_decode((string) ($row->metadata ?? '{}'), true));

            return is_array(data_get($metadata, 'cognitive_quarantine.lineage.hmac_lineage'));
        });

        $chainedCount = $chained->count();
        $denominator = $recent->count();
        $fullCoverage = $denominator >= $minCaptures && $chainedCount === $denominator;

        return [
            'status' => $fullCoverage ? 'ready' : 'pending_window',
            'chained_captures' => $chainedCount,
            'min_captures' => $minCaptures,
            'coverage_rate' => $denominator > 0 ? round($chainedCount / $denominator, 4) : null,
            'note' => $fullCoverage
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
        $chain = $this->resolveChain($parsed['kind'], $parsed['id']);

        if ($chain === null) {
            return [
                'ok' => false,
                'status' => 'not_found',
                'ref' => $ref,
                'chain' => null,
                'verify' => [
                    'status' => 'not_found',
                    'broken_at' => null,
                    'stage_count' => 0,
                    'head_receipt_hash' => null,
                ],
            ];
        }

        $verify = $this->verify($chain);

        return [
            'ok' => $verify['status'] === 'verified',
            'status' => $verify['status'],
            'ref' => $ref,
            'chain' => $this->providerSafeChain($chain),
            'verify' => $verify,
        ];
    }

    public function stagePayloadHash(string $stage, array $payload): string
    {
        return hash('sha256', (string) json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'stage' => $stage,
            'payload' => $this->sortKeysRecursive($payload),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function chainLink(?string $prevReceiptHash, string $stagePayloadHash): string
    {
        $prev = $prevReceiptHash ?? self::GENESIS_RECEIPT;

        return hash_hmac('sha256', $prev.'|'.$stagePayloadHash, $this->keyMaterial(), false);
    }

    /**
     * @param  array<string,mixed>  $chain
     */
    public function headReceiptHash(array $chain): ?string
    {
        $stages = array_values(AiValueNormalizer::arrayOrEmpty($chain['stages'] ?? null));
        if ($stages === []) {
            return null;
        }
        $last = $stages[count($stages) - 1];

        return is_array($last) ? (string) ($last['receipt_hash'] ?? '') : null;
    }

    /**
     * @param  list<array<string,mixed>>  $stages
     * @return array<string,mixed>
     */
    public function envelope(array $stages): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'slice' => 'MAXI-07',
            'key_version' => 1,
            'threat_model' => self::THREAT_MODEL,
            'stages' => $stages,
            'head_receipt_hash' => $this->headReceiptHash(['stages' => $stages]),
            'stamped_at' => now()->toJSON(),
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
            'schema_version' => $chain['schema_version'] ?? self::SCHEMA_VERSION,
            'slice' => $chain['slice'] ?? 'MAXI-07',
            'key_version' => $chain['key_version'] ?? 1,
            'threat_model' => $chain['threat_model'] ?? self::THREAT_MODEL,
            'stages' => array_values(AiValueNormalizer::arrayOrEmpty($chain['stages'] ?? null)),
            'head_receipt_hash' => $chain['head_receipt_hash'] ?? $this->headReceiptHash($chain),
            'stamped_at' => $chain['stamped_at'] ?? null,
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

            return ['kind' => AiValueNormalizer::lowerTrimmedString($kind), 'id' => $id];
        }

        return ['kind' => 'capture', 'id' => $trimmed];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveChain(string $kind, string $id): ?array
    {
        return match ($kind) {
            'packet', 'source_packet' => $this->chainFromPacket($id),
            'memory' => $this->chainFromMemory($id),
            default => $this->chainFromCapture($id),
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function chainFromCapture(string $id): ?array
    {
        if (! DatabaseTableAvailability::has('captures')) {
            return null;
        }

        $capture = Capture::query()
            ->where(function ($query) use ($id): void {
                $query->where('id', $id)->orWhere('client_id', $id);
            })
            ->first();

        if ($capture === null) {
            return null;
        }

        $chain = data_get($capture->metadata, 'cognitive_quarantine.lineage.hmac_lineage');

        return is_array($chain) ? $chain : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function chainFromPacket(string $id): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_knowledge_source_packets')) {
            return null;
        }

        $packet = AtlasKnowledgeSourcePacket::query()
            ->where('id', $id)
            ->orWhere('source_hash', AiValueNormalizer::lowerTrimmedString($id))
            ->first();

        if ($packet === null) {
            return null;
        }

        $lineage = AiValueNormalizer::arrayOrEmpty($packet->lineage);
        $chain = $lineage['hmac_lineage'] ?? null;

        return is_array($chain) ? $chain : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function chainFromMemory(string $id): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entries')) {
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
        $configured = config('atlas.capture.hmac_lineage_secret');
        $configuredSecret = AiValueNormalizer::trimmedStringOrNull($configured);
        if ($configuredSecret !== null) {
            return hash_hmac('sha256', 'atlas.capture.hmac_lineage.v1', $configuredSecret, true);
        }

        $appKey = (string) config('app.key', '');

        return hash_hmac(
            'sha256',
            'atlas.capture.hmac_lineage.v1',
            $appKey !== '' ? $appKey : 'atlas.capture.hmac_lineage.fallback.v1',
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
