<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Provenance;

use App\Services\Ai\SelfConstruction\Maestro\Support\AtlasMaestroCanonicalSorter;
use InvalidArgumentException;

final class AtlasMaestroPacketProvenanceComposer
{
    /**
     * @var list<string>
     */
    private const ALLOWED_ORIGIN_KINDS = [
        'operator_intent',
        'cortex_fact',
        'loop_emergence',
    ];

    /** @var list<string> Canonical task-content fields the content_hash is derived from. */
    private const CANONICAL_CONTENT_FIELDS = ['objective', 'allowed_files', 'acceptance_criteria', 'required_evidence'];

    /**
     * Derives a content_hash from only the CANONICAL task content — objective, allowed_files,
     * acceptance_criteria, required_evidence — so mutable/transient packet fields (lease_id,
     * status, claim state, give_back_count, timestamps, ...) never change a packet's identity
     * hash just because it moved through the queue.
     *
     * The hash itself only ever derives from CANONICAL_CONTENT_FIELDS (order-independent: fields
     * are read by name, then json-canonicalized with recursive ksort, so associative key order in
     * $packet can never change content_hash). `source`, `allowed_files_fingerprint` and
     * `acceptance_fingerprint` are supplementary provenance metadata alongside the hash — never
     * derived from raw provider prompts or secret-bearing transient fields, only from the caller's
     * declared `source` and the same canonical allowed_files/acceptance_criteria lists.
     *
     * @param  array<string,mixed>  $packet
     * @return array{provenance_record:array<string,mixed>, content_hash:string, canonical_fields:list<string>, omitted_transient_fields:list<string>, source:string, allowed_files_fingerprint:string, acceptance_fingerprint:string}
     */
    public function composeContentHash(array $packet): array
    {
        $canonicalPayload = [];
        foreach (self::CANONICAL_CONTENT_FIELDS as $field) {
            $value = $packet[$field] ?? null;
            if ($field === 'objective') {
                $canonicalPayload[$field] = trim((string) $value);

                continue;
            }
            $list = is_array($value) ? array_values(array_map('strval', $value)) : [];
            sort($list, SORT_STRING);
            $canonicalPayload[$field] = $list;
        }

        $contentHash = hash('sha256', $this->canonicalJson($canonicalPayload));
        $allowedFilesFingerprint = hash('sha256', $this->canonicalJson($canonicalPayload['allowed_files']));
        $acceptanceFingerprint = hash('sha256', $this->canonicalJson($canonicalPayload['acceptance_criteria']));
        $source = trim((string) ($packet['source'] ?? ''));

        $omittedTransientFields = array_values(array_diff(
            array_keys($packet),
            self::CANONICAL_CONTENT_FIELDS,
        ));
        sort($omittedTransientFields, SORT_STRING);

        return [
            'provenance_record' => [
                'content_hash' => $contentHash,
                'canonical_fields' => self::CANONICAL_CONTENT_FIELDS,
                'source' => $source,
                'allowed_files_fingerprint' => $allowedFilesFingerprint,
                'acceptance_fingerprint' => $acceptanceFingerprint,
            ],
            'content_hash' => $contentHash,
            'canonical_fields' => self::CANONICAL_CONTENT_FIELDS,
            'omitted_transient_fields' => $omittedTransientFields,
            'source' => $source,
            'allowed_files_fingerprint' => $allowedFilesFingerprint,
            'acceptance_fingerprint' => $acceptanceFingerprint,
        ];
    }

    /**
     * @param  array<string,mixed>  $inputs
     * @return array<string,mixed>
     */
    public function compose(string $taskPacketId, array $inputs): array
    {
        $originKind = (string) ($inputs['origin_kind'] ?? '');
        if (! in_array($originKind, self::ALLOWED_ORIGIN_KINDS, true)) {
            throw new InvalidArgumentException('Unsupported origin_kind for provenance record.');
        }

        $originId = trim((string) ($inputs['origin_id'] ?? ''));
        if ($originId === '') {
            throw new InvalidArgumentException('Provenance record requires a non-empty origin_id.');
        }

        $chainInputs = $inputs['chain'] ?? null;
        if (! is_array($chainInputs) || $chainInputs === []) {
            throw new InvalidArgumentException('Provenance record requires a non-empty chain.');
        }

        $chain = [];
        foreach (array_values($chainInputs) as $index => $link) {
            if (! is_array($link)) {
                throw new InvalidArgumentException('Each provenance chain link must be an array.');
            }

            $chain[] = $this->composeLink($taskPacketId, $index, $link);
        }

        $record = [
            'packet_id' => $taskPacketId,
            'origin_kind' => $originKind,
            'origin_id' => $originId,
            'chain' => $chain,
        ];

        // Allowed-files hash: deterministic hash of the sorted allowed_files list.
        if (isset($inputs['allowed_files']) && is_array($inputs['allowed_files'])) {
            $files = array_values(array_map('strval', $inputs['allowed_files']));
            sort($files, SORT_STRING);
            $record['allowed_files_hash'] = hash('sha256', $this->canonicalJson($files));
        }

        // Acceptance hash: deterministic hash of the sorted acceptance_criteria list.
        if (isset($inputs['acceptance_criteria']) && is_array($inputs['acceptance_criteria'])) {
            $criteria = array_values(array_map('strval', $inputs['acceptance_criteria']));
            sort($criteria, SORT_STRING);
            $record['acceptance_hash'] = hash('sha256', $this->canonicalJson($criteria));
        }

        // Missing source: mark when the origin source is absent from the declared inventory.
        if (array_key_exists('source_missing', $inputs)) {
            $record['source_missing'] = (bool) $inputs['source_missing'];
        }

        // Author/critic separation: who generated vs who reviewed the packet.
        if (isset($inputs['author']) && trim((string) $inputs['author']) !== '') {
            $record['author'] = trim((string) $inputs['author']);
        }
        if (isset($inputs['critic']) && trim((string) $inputs['critic']) !== '') {
            $record['critic'] = trim((string) $inputs['critic']);
        }

        return $record;
    }

    /**
     * @param  array<string,mixed>  $link
     * @return array<string,string|null>
     */
    private function composeLink(string $taskPacketId, int $index, array $link): array
    {
        $parentId = $this->nullableString($link['parent_id'] ?? null);
        $sourceKind = trim((string) ($link['source_kind'] ?? ''));
        $sourceId = trim((string) ($link['source_id'] ?? ''));
        $capturedAt = $this->normalizeTimestamp((string) ($link['captured_at'] ?? ''));

        if ($sourceKind === '' || $sourceId === '' || $capturedAt === '') {
            throw new InvalidArgumentException('Each provenance chain link requires non-empty source_kind, source_id, and captured_at.');
        }

        $contentPayload = [
            'parent_id' => $parentId,
            'source_kind' => $sourceKind,
            'source_id' => $sourceId,
            'captured_at' => $capturedAt,
        ];

        $contentHash = hash('sha256', $this->canonicalJson($contentPayload));
        $linkId = hash(
            'sha256',
            $this->canonicalJson([
                'packet_id' => $taskPacketId,
                'index' => $index,
                'parent_id' => $parentId,
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'captured_at' => $capturedAt,
                'content_hash' => $contentHash,
            ])
        );

        return [
            'link_id' => $linkId,
            'parent_id' => $parentId,
            'source_kind' => $sourceKind,
            'source_id' => $sourceId,
            'captured_at' => $capturedAt,
            'content_hash' => $contentHash,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeTimestamp(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        return (new \DateTimeImmutable($trimmed))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode(
            AtlasMaestroCanonicalSorter::sortRecursive($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
