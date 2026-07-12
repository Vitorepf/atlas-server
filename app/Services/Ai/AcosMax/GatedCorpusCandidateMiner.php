<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

final class GatedCorpusCandidateMiner
{
    public const SCHEMA_VERSION = 'atlas.corpus.gated_candidate_miner.v1';

    /** @var list<string> */
    private const PROTECTED = ['sensitive', 'secret', 'cyber'];

    /**
     * @param  list<array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    public static function mine(array $sources): array
    {
        $candidates = [];
        $omitted = [];
        foreach ($sources as $source) {
            $privacy = strtolower((string) ($source['privacy_class'] ?? 'normal'));
            if (in_array($privacy, self::PROTECTED, true)) {
                $omitted[] = 'protected_class_omitted';
                continue;
            }
            $text = trim((string) ($source['text'] ?? ''));
            $ref = trim((string) ($source['ref'] ?? ''));
            if ($text === '' || $ref === '') {
                continue;
            }
            $candidates[] = [
                'schema_version' => self::SCHEMA_VERSION,
                'origin_ref' => $ref,
                'source' => (string) ($source['source'] ?? 'unknown'),
                'candidate_hash' => sha1($ref."\n".$text),
                'admission' => [
                    'via_asi_02' => true,
                    'immune_gates_apply' => true,
                    'direct_write' => false,
                ],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $candidates === [] ? 'insufficient_signal' : 'ok',
            'candidates' => $candidates,
            'omitted' => array_values(array_unique($omitted)),
            'source' => [
                'candidate_only' => true,
                'writes_memory_directly' => false,
                'count_is_acceptance' => false,
            ],
        ];
    }
}
