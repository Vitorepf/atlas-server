<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasKnowledgeIngestionFabricService
{
    public const SCHEMA_VERSION = 'atlas.aucri.knowledge_ingestion_fabric.v1';

    public const SOURCE_PACKET_SCHEMA = 'atlas.knowledge.source_packet.v1';

    public const INGESTION_JOB_SCHEMA = 'atlas.knowledge.ingestion_job.v1';

    public const NORMALIZATION_RECEIPT_SCHEMA = 'atlas.knowledge.normalization_receipt.v1';

    public const LINEAGE_REF_SCHEMA = 'atlas.knowledge.lineage_ref.v1';

    private const SOURCE_TYPES = ['text', 'pdf', 'image', 'youtube', 'repo_file', 'spreadsheet', 'url'];

    public function __construct(private readonly AtlasRetrievalPrivacyTrustLayerService $privacyTrustLayer) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function normalize(array $input = []): array
    {
        $sourceType = $this->sourceType($input);
        $originUri = $this->originUri($input, $sourceType);
        $rawContent = $this->rawContent($input);
        $language = $this->language($input, $originUri, $rawContent);
        $confidence = $this->confidence($input, $sourceType, $rawContent);
        $sourceHash = MissionCanonicalHash::sha256([$sourceType, $originUri, $rawContent]);
        $versionHash = MissionCanonicalHash::sha256([$sourceHash, $language, $confidence, $this->adapterVersion($sourceType)]);
        $lineage = $this->lineage($input, $sourceType, $originUri, $sourceHash, $versionHash);
        $privacy = $this->privacyTrustLayer->evaluate([
            'raw_context' => $rawContent,
            'provider_target' => (string) ($input['provider_target'] ?? 'external'),
            'risk_level' => (string) ($input['risk_level'] ?? 'low'),
            'source_refs' => [[
                'source_type' => $sourceType,
                'source_ref' => $originUri,
                'classification' => (string) ($input['classification'] ?? 'public'),
                'authority_level' => (string) ($input['authority_level'] ?? 'operator_supplied'),
            ]],
        ]);
        $privacyStatus = (string) data_get($privacy, 'provider_gate.status', 'blocked');
        $normalizationReceipt = $this->normalizationReceipt($sourceType, $sourceHash, $versionHash, $lineage, $privacy, $confidence, $rawContent);
        $sourcePacket = $this->sourcePacket(
            $sourceType,
            $originUri,
            $sourceHash,
            $versionHash,
            $language,
            $confidence,
            $lineage,
            $privacy,
            $normalizationReceipt,
            $input,
        );
        $ingestionJob = $this->ingestionJob($sourcePacket, $privacyStatus);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $privacyStatus === 'blocked' ? 'blocked' : 'ready',
            'generated_at' => Carbon::now()->toIso8601String(),
            'ingestion_job' => $ingestionJob,
            'source_packet' => $sourcePacket,
            'normalization_receipt' => $normalizationReceipt,
            'lineage_refs' => $lineage,
            'privacy_gate' => [
                'schema_version' => AtlasRetrievalPrivacyTrustLayerService::PROVIDER_GATE_SCHEMA,
                'status' => $privacyStatus,
                'classification' => (string) data_get($privacy, 'provider_gate.classification', 'unknown'),
                'provider_allowed' => (bool) data_get($privacy, 'provider_gate.provider_allowed', false),
                'trust_receipt_hash' => (string) data_get($privacy, 'trust_receipt.receipt_hash', ''),
                'privacy_trust_hash' => (string) ($privacy['privacy_trust_hash'] ?? ''),
            ],
            'adapters' => [
                'adapter_id' => $this->adapterId($sourceType),
                'adapter_version' => $this->adapterVersion($sourceType),
                'extraction_mode' => $this->extractionMode($sourceType, $input),
                'raw_content_exposed' => false,
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'raw_text_exposed' => false,
                'indexed' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['ingestion_fabric_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function sourceType(array $input): string
    {
        $explicit = strtolower(trim((string) ($input['source_type'] ?? '')));
        if (in_array($explicit, self::SOURCE_TYPES, true)) {
            return $explicit;
        }

        $uri = strtolower((string) ($input['origin_uri'] ?? $input['url'] ?? ''));
        if (str_contains($uri, 'youtube.com') || str_contains($uri, 'youtu.be')) {
            return 'youtube';
        }
        if (preg_match('/\.pdf($|\?)/', $uri) === 1) {
            return 'pdf';
        }
        if (preg_match('/\.(png|jpg|jpeg|heic|webp)($|\?)/', $uri) === 1) {
            return 'image';
        }
        if (preg_match('/\.(csv|xlsx|xls|tsv)($|\?)/', $uri) === 1) {
            return 'spreadsheet';
        }
        if (preg_match('/\.(php|ts|tsx|js|py|md|json)($|\?)/', $uri) === 1) {
            return 'repo_file';
        }
        if (filter_var($uri, FILTER_VALIDATE_URL)) {
            return 'url';
        }

        return 'text';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function originUri(array $input, string $sourceType): string
    {
        $uri = trim((string) ($input['origin_uri'] ?? $input['url'] ?? $input['path'] ?? ''));

        return $uri !== '' ? $uri : 'operator://inline/'.$sourceType.'/'.MissionCanonicalHash::sha256((string) ($input['content'] ?? $input['text'] ?? 'empty'));
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function rawContent(array $input): string
    {
        foreach (['content', 'text', 'transcript', 'ocr_text', 'summary'] as $key) {
            if (isset($input[$key]) && is_scalar($input[$key])) {
                return trim((string) $input[$key]);
            }
        }

        return trim((string) ($input['origin_uri'] ?? $input['url'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function language(array $input, string $originUri, string $rawContent): string
    {
        $language = strtolower(trim((string) ($input['language'] ?? '')));
        if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) === 1) {
            return $language;
        }

        $sample = strtolower($rawContent.' '.$originUri);
        if (preg_match('/\b(the|and|with|from|transcript)\b/', $sample) === 1) {
            return 'en';
        }
        if (preg_match('/\b(el|la|con|para|transcripcion)\b/', $sample) === 1) {
            return 'es';
        }

        return 'pt-br';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function confidence(array $input, string $sourceType, string $rawContent): float
    {
        if (array_key_exists('confidence', $input) && $input['confidence'] !== null && $input['confidence'] !== '') {
            return round(max(0.0, min(1.0, (float) $input['confidence'])), 4);
        }

        $base = match ($sourceType) {
            'repo_file' => 0.96,
            'text', 'url' => 0.88,
            'pdf', 'spreadsheet' => 0.82,
            'youtube' => 0.74,
            'image' => 0.66,
            default => 0.70,
        };

        if ($rawContent === '') {
            $base -= 0.22;
        }

        return round(max(0.1, min(0.99, $base)), 4);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    private function lineage(array $input, string $sourceType, string $originUri, string $sourceHash, string $versionHash): array
    {
        $originKind = filter_var($originUri, FILTER_VALIDATE_URL) ? 'url' : (str_starts_with($originUri, 'operator://') ? 'operator' : 'path');
        $refs = [[
            'schema_version' => self::LINEAGE_REF_SCHEMA,
            'lineage_kind' => 'origin',
            'source_type' => $sourceType,
            'origin_kind' => $originKind,
            'origin_hash' => MissionCanonicalHash::sha256($originUri),
            'source_hash' => $sourceHash,
            'version_hash' => $versionHash,
        ]];

        foreach ((array) ($input['lineage_refs'] ?? []) as $index => $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $sourceRef = (string) ($ref['source_ref'] ?? $ref['origin_uri'] ?? 'lineage:'.$index);
            $refs[] = [
                'schema_version' => self::LINEAGE_REF_SCHEMA,
                'lineage_kind' => (string) ($ref['lineage_kind'] ?? 'derived_from'),
                'source_type' => (string) ($ref['source_type'] ?? $sourceType),
                'origin_kind' => (string) ($ref['origin_kind'] ?? 'reference'),
                'origin_hash' => MissionCanonicalHash::sha256($sourceRef),
                'source_hash' => MissionCanonicalHash::sha256($sourceRef),
                'version_hash' => MissionCanonicalHash::sha256([$sourceRef, $versionHash]),
            ];
        }

        return $refs;
    }

    /**
     * @param  array<int,array<string,mixed>>  $lineage
     * @param  array<string,mixed>  $privacy
     * @return array<string,mixed>
     */
    private function normalizationReceipt(string $sourceType, string $sourceHash, string $versionHash, array $lineage, array $privacy, float $confidence, string $rawContent): array
    {
        $receipt = [
            'schema_version' => self::NORMALIZATION_RECEIPT_SCHEMA,
            'source_type' => $sourceType,
            'source_hash' => $sourceHash,
            'version_hash' => $versionHash,
            'normalized_text_ref' => MissionCanonicalHash::sha256('normalized:'.$sourceHash),
            'lineage_count' => count($lineage),
            'confidence' => $confidence,
            'privacy_status' => (string) data_get($privacy, 'provider_gate.status', 'blocked'),
            'redaction_status' => (string) data_get($privacy, 'redaction_receipt.redaction_status', 'unknown'),
            'raw_content_hash' => MissionCanonicalHash::sha256($rawContent === '' ? 'empty' : $rawContent),
            'raw_content_exposed' => false,
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<int,array<string,mixed>>  $lineage
     * @param  array<string,mixed>  $privacy
     * @param  array<string,mixed>  $normalizationReceipt
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function sourcePacket(
        string $sourceType,
        string $originUri,
        string $sourceHash,
        string $versionHash,
        string $language,
        float $confidence,
        array $lineage,
        array $privacy,
        array $normalizationReceipt,
        array $input,
    ): array {
        return [
            'schema_version' => self::SOURCE_PACKET_SCHEMA,
            'source_type' => $sourceType,
            'origin_uri_hash' => MissionCanonicalHash::sha256($originUri),
            'source_hash' => $sourceHash,
            'version_hash' => $versionHash,
            'normalized_text_ref' => (string) $normalizationReceipt['normalized_text_ref'],
            'media_refs' => $this->mediaRefs($sourceType, $originUri, $input),
            'language' => $language,
            'confidence' => $confidence,
            'lineage' => $lineage,
            'privacy_status' => (string) data_get($privacy, 'provider_gate.status', 'blocked'),
            'privacy_classification' => (string) data_get($privacy, 'provider_gate.classification', 'unknown'),
            'ingestion_status' => data_get($privacy, 'provider_gate.status') === 'blocked' ? 'blocked_by_privacy' : 'normalized',
            'receipt_hash' => (string) $normalizationReceipt['receipt_hash'],
        ];
    }

    /**
     * @param  array<string,mixed>  $sourcePacket
     * @return array<string,mixed>
     */
    private function ingestionJob(array $sourcePacket, string $privacyStatus): array
    {
        $job = [
            'schema_version' => self::INGESTION_JOB_SCHEMA,
            'job_id' => MissionCanonicalHash::sha256(['akif', $sourcePacket['source_hash'], $sourcePacket['version_hash']]),
            'source_type' => (string) $sourcePacket['source_type'],
            'status' => $privacyStatus === 'blocked' ? 'blocked' : 'ready_for_indexing',
            'source_hash' => (string) $sourcePacket['source_hash'],
            'version_hash' => (string) $sourcePacket['version_hash'],
            'lineage_hash' => MissionCanonicalHash::sha256($sourcePacket['lineage']),
            'writes' => false,
        ];
        $job['job_hash'] = MissionCanonicalHash::sha256($job);

        return $job;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,string>>
     */
    private function mediaRefs(string $sourceType, string $originUri, array $input): array
    {
        if (! in_array($sourceType, ['youtube', 'image', 'pdf'], true)) {
            return [];
        }

        return [[
            'media_type' => $sourceType,
            'media_ref_hash' => MissionCanonicalHash::sha256((string) ($input['media_ref'] ?? $originUri)),
        ]];
    }

    private function adapterId(string $sourceType): string
    {
        return 'akif.'.$sourceType.'.adapter';
    }

    private function adapterVersion(string $sourceType): string
    {
        return 'akif.'.$sourceType.'.adapter.v1';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function extractionMode(string $sourceType, array $input): string
    {
        if ($sourceType === 'youtube') {
            return (string) ($input['transcript_source'] ?? 'transcript_metadata_only');
        }

        return match ($sourceType) {
            'image' => 'ocr_metadata_only',
            'pdf' => 'pdf_text_metadata_only',
            'spreadsheet' => 'tabular_metadata_only',
            'repo_file' => 'repo_file_text',
            default => 'text_normalization',
        };
    }
}
