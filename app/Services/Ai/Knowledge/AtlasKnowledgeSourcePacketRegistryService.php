<?php

namespace App\Services\Ai\Knowledge;

use App\Models\AtlasKnowledgeSourcePacket;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

/**
 * Atlas Cognition Operating System — AKIF (Knowledge Ingestion Fabric) service.
 *
 * Schema canonico: atlas.knowledge.source_packet.v1.
 * Doc canon: atlas-cognition-operating-system.md (AUCRI bloco #14).
 *
 * Pipeline canonico AKIF:
 *   source detection -> ARPTL privacy gate -> extraction (phase 2) ->
 *   dedup by source_hash -> source_packet emit -> ASEF/AHRI/AURG consume.
 *
 * Phase 1 (esta versao):
 *  - register(): cria source_packet com hash determinismo + lineage + privacy_status.
 *  - findBySourceHash(): dedup canonico.
 *  - listProviderSafe(): para consumers que precisam de packets seguros.
 *  - block(): marca packet como blocked por privacy/conteudo.
 *  - ready(): marca packet como ready para downstream.
 *  - Sem extracao OCR/transcricao (phase 2).
 *  - Sem queue jobs (phase 2).
 *
 * Cognitive immune compliance:
 *  - Default ingestion_status = `received` (quarentena).
 *  - Promotion para `ready` so via ready() apos validacao explicita.
 *  - privacy_status influencia provider_safe deterministicamente.
 *
 * Invariantes:
 *  - source_hash + source_type ja existente -> retorna packet existente (idempotente).
 *  - receipt_hash sha256 deterministico de payload canonico ordenado.
 *  - lineage inclui pelo menos source_type, origin_uri, ingested_at, ingester.
 *
 * Source types canonicos (Phase 1 enum):
 */
class AtlasKnowledgeSourcePacketRegistryService
{
    public const SOURCE_TYPE_DOC = 'doc';

    public const SOURCE_TYPE_PDF = 'pdf';

    public const SOURCE_TYPE_YOUTUBE = 'youtube';

    public const SOURCE_TYPE_REPO = 'repo';

    public const SOURCE_TYPE_URL = 'url';

    public const SOURCE_TYPE_DATASET = 'dataset';

    public const SOURCE_TYPE_IMAGE = 'image';

    public const SOURCE_TYPE_AUDIO = 'audio';

    public const SOURCE_TYPE_MANUAL = 'manual';

    public const ALLOWED_SOURCE_TYPES = [
        self::SOURCE_TYPE_DOC,
        self::SOURCE_TYPE_PDF,
        self::SOURCE_TYPE_YOUTUBE,
        self::SOURCE_TYPE_REPO,
        self::SOURCE_TYPE_URL,
        self::SOURCE_TYPE_DATASET,
        self::SOURCE_TYPE_IMAGE,
        self::SOURCE_TYPE_AUDIO,
        self::SOURCE_TYPE_MANUAL,
    ];

    public const PRIVACY_NORMAL = 'normal';

    public const PRIVACY_PRIVATE = 'private';

    public const PRIVACY_SENSITIVE = 'sensitive';

    public const PRIVACY_SECRET = 'secret';

    public const ALLOWED_PRIVACY_STATUSES = [
        self::PRIVACY_NORMAL,
        self::PRIVACY_PRIVATE,
        self::PRIVACY_SENSITIVE,
        self::PRIVACY_SECRET,
    ];

    public const INGESTION_RECEIVED = 'received';

    public const INGESTION_CLASSIFYING = 'classifying';

    public const INGESTION_EXTRACTING = 'extracting';

    public const INGESTION_READY = 'ready';

    public const INGESTION_BLOCKED = 'blocked';

    public const INGESTION_FAILED = 'failed';

    public const ALLOWED_INGESTION_STATUSES = [
        self::INGESTION_RECEIVED,
        self::INGESTION_CLASSIFYING,
        self::INGESTION_EXTRACTING,
        self::INGESTION_READY,
        self::INGESTION_BLOCKED,
        self::INGESTION_FAILED,
    ];

    /**
     * Registra um novo source packet (ou retorna existente por source_hash).
     *
     * @param  array<string,mixed>  $params  Required: source_type, origin_uri, source_hash.
     *                                       Optional: language, confidence, metadata, privacy_status, ingester.
     * @return array{ok:bool, status:string, packet_id:?string, source_hash:?string, receipt_hash:?string, reason:?string}
     */
    public function register(array $params): array
    {
        $sourceType = (string) ($params['source_type'] ?? '');
        $originUri = (string) ($params['origin_uri'] ?? '');
        $sourceHash = (string) ($params['source_hash'] ?? '');

        if (! in_array($sourceType, self::ALLOWED_SOURCE_TYPES, true)) {
            return $this->envelope(false, 'invalid_source_type', null, null, null, 'source_type nao canonico');
        }

        if ($originUri === '') {
            return $this->envelope(false, 'invalid_origin_uri', null, null, null, 'origin_uri obrigatorio');
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', $sourceHash)) {
            return $this->envelope(false, 'invalid_source_hash', null, null, null, 'source_hash deve ser sha256 hex');
        }

        $privacyStatus = $this->normalizePrivacyStatus($params['privacy_status'] ?? self::PRIVACY_NORMAL);
        $providerSafe = ! in_array($privacyStatus, [self::PRIVACY_SECRET], true);
        $language = $this->normalizeLanguage($params['language'] ?? null);
        $confidence = $this->normalizeConfidence($params['confidence'] ?? null);
        $metadata = is_array($params['metadata'] ?? null) ? $params['metadata'] : null;
        $ingester = (string) ($params['ingester'] ?? 'unknown');
        $now = now();

        $lineage = [
            'source_type' => $sourceType,
            'origin_uri' => $originUri,
            'ingested_at' => $now->toIso8601String(),
            'ingester' => $ingester,
        ];

        $versionHash = hash('sha256', json_encode([
            'source_hash' => $sourceHash,
            'metadata' => $metadata,
            'language' => $language,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        $receiptPayload = [
            'schema_version' => 'atlas.knowledge.source_packet.v1',
            'source_type' => $sourceType,
            'origin_uri' => $originUri,
            'source_hash' => strtolower($sourceHash),
            'version_hash' => $versionHash,
            'language' => $language,
            'confidence' => $confidence,
            'privacy_status' => $privacyStatus,
            'provider_safe' => $providerSafe,
            'lineage' => $lineage,
        ];
        $receiptHash = hash('sha256', json_encode($receiptPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        if (! $this->tableExists()) {
            return $this->envelope(
                ok: false,
                status: 'table_missing',
                packetId: null,
                sourceHash: $sourceHash,
                receiptHash: $receiptHash,
                reason: 'tabela atlas_knowledge_source_packets ainda nao existe; rode migrations.'
            );
        }

        // Dedup canonico por source_hash + active deleted_at NULL.
        $existing = AtlasKnowledgeSourcePacket::query()
            ->where('source_hash', strtolower($sourceHash))
            ->whereNull('deleted_at')
            ->first();

        if ($existing !== null) {
            return $this->envelope(
                ok: true,
                status: 'already_registered',
                packetId: (string) $existing->id,
                sourceHash: $existing->source_hash,
                receiptHash: $existing->receipt_hash,
            );
        }

        $packet = AtlasKnowledgeSourcePacket::create([
            'id' => (string) Str::uuid(),
            'schema_version' => 'atlas.knowledge.source_packet.v1',
            'source_type' => $sourceType,
            'origin_uri' => $originUri,
            'source_hash' => strtolower($sourceHash),
            'version_hash' => $versionHash,
            'language' => $language,
            'confidence' => $confidence,
            'lineage' => $lineage,
            'privacy_status' => $privacyStatus,
            'provider_safe' => $providerSafe,
            'ingestion_status' => self::INGESTION_RECEIVED,
            'receipt_hash' => $receiptHash,
            'metadata' => $metadata,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->envelope(
            ok: true,
            status: 'registered',
            packetId: (string) $packet->id,
            sourceHash: $packet->source_hash,
            receiptHash: $packet->receipt_hash,
        );
    }

    public function findBySourceHash(string $sourceHash): ?AtlasKnowledgeSourcePacket
    {
        if (! preg_match('/^[a-f0-9]{64}$/i', $sourceHash) || ! $this->tableExists()) {
            return null;
        }

        return AtlasKnowledgeSourcePacket::query()
            ->where('source_hash', strtolower($sourceHash))
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Marca packet como ready apos validacao externa.
     */
    public function ready(string $packetId): array
    {
        return $this->transition($packetId, self::INGESTION_READY, null);
    }

    /**
     * Marca packet como blocked com razao.
     */
    public function block(string $packetId, string $reason): array
    {
        return $this->transition($packetId, self::INGESTION_BLOCKED, $reason);
    }

    /**
     * Lista packets provider_safe + status ready (consumers downstream).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listProviderSafe(?string $sourceType = null, int $limit = 50): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        $query = AtlasKnowledgeSourcePacket::query()
            ->where('provider_safe', true)
            ->where('ingestion_status', self::INGESTION_READY)
            ->whereNull('deleted_at');

        if ($sourceType !== null) {
            $query->where('source_type', $sourceType);
        }

        return $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AtlasKnowledgeSourcePacket $p): array => [
                'packet_id' => (string) $p->id,
                'source_type' => $p->source_type,
                'origin_uri' => $p->origin_uri,
                'source_hash' => $p->source_hash,
                'version_hash' => $p->version_hash,
                'language' => $p->language,
                'confidence' => $p->confidence,
                'lineage' => $p->lineage,
                'receipt_hash' => $p->receipt_hash,
                'created_at' => $p->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public static function isValidSourceType(string $type): bool
    {
        return in_array($type, self::ALLOWED_SOURCE_TYPES, true);
    }

    public static function isValidPrivacyStatus(string $status): bool
    {
        return in_array($status, self::ALLOWED_PRIVACY_STATUSES, true);
    }

    public static function isValidIngestionStatus(string $status): bool
    {
        return in_array($status, self::ALLOWED_INGESTION_STATUSES, true);
    }

    private function transition(string $packetId, string $newStatus, ?string $reason): array
    {
        if (! $this->tableExists()) {
            return $this->envelope(false, 'table_missing', null, null, null, 'tabela ausente');
        }

        $packet = AtlasKnowledgeSourcePacket::find($packetId);
        if ($packet === null) {
            return $this->envelope(false, 'packet_not_found', null, null, null, 'packet_id nao encontrado');
        }

        $packet->ingestion_status = $newStatus;
        if ($reason !== null) {
            $packet->blocking_reason = mb_substr($reason, 0, 512);
        }
        $packet->save();

        return $this->envelope(
            ok: true,
            status: 'transitioned_to_'.$newStatus,
            packetId: (string) $packet->id,
            sourceHash: $packet->source_hash,
            receiptHash: $packet->receipt_hash,
        );
    }

    private function tableExists(): bool
    {
        return DatabaseTableAvailability::has('atlas_knowledge_source_packets');
    }

    private function normalizePrivacyStatus(mixed $value): string
    {
        $str = is_string($value) ? trim(strtolower($value)) : '';

        return in_array($str, self::ALLOWED_PRIVACY_STATUSES, true) ? $str : self::PRIVACY_NORMAL;
    }

    private function normalizeLanguage(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim(strtolower($value));
        if (! preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $trimmed)) {
            return null;
        }

        return substr($trimmed, 0, 8);
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        if ($f < 0.0) {
            return 0.0;
        }
        if ($f > 1.0) {
            return 1.0;
        }

        return round($f, 3);
    }

    private function envelope(bool $ok, string $status, ?string $packetId, ?string $sourceHash, ?string $receiptHash, ?string $reason = null): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'packet_id' => $packetId,
            'source_hash' => $sourceHash,
            'receipt_hash' => $receiptHash,
            'schema_version' => 'atlas.knowledge.source_packet.v1',
            'reason' => $reason,
        ];
    }
}
