<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\AuditTrail;

/**
 * Self-describing manifest header produced by {@see AtlasLoopAuditTrailExporter}.
 *
 * Serialised as the FIRST line of every JSONL export so the file is tamper-evident
 * standalone (sha256_of_body covers the concatenated event lines that follow).
 */
final readonly class ExportManifest
{
    public const SCHEMA_VERSION = 'atlas.loop.audit_trail.export.v1';

    /**
     * @param  array<string,string>  $source_ledger_versions
     */
    public function __construct(
        public string $schema_version,
        public array $source_ledger_versions,
        public string $window_from,
        public string $window_to,
        public int $event_count,
        public string $sha256_of_body,
    ) {}

    /**
     * Deterministic, key-sorted JSON shape — exact bytes that the exporter
     * writes as the manifest header line.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'event_count' => $this->event_count,
            'schema_version' => $this->schema_version,
            'sha256_of_body' => $this->sha256_of_body,
            'source_ledger_versions' => $this->source_ledger_versions,
            'window_from' => $this->window_from,
            'window_to' => $this->window_to,
        ];
        ksort($payload['source_ledger_versions']);

        return $payload;
    }
}
