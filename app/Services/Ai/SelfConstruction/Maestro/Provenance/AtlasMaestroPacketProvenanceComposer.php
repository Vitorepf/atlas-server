<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Provenance;

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

        return [
            'packet_id' => $taskPacketId,
            'origin_kind' => $originKind,
            'origin_id' => $originId,
            'chain' => $chain,
        ];
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

        $contentPayload = array_key_exists('content', $link)
            ? $link['content']
            : [
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
            $this->sortRecursive($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }

        ksort($value, SORT_STRING);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->sortRecursive($item);
        }

        return $sorted;
    }
}
