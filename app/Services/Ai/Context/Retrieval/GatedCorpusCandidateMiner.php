<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Retrieval;

use App\Services\Ai\Support\AiValueNormalizer;

final class GatedCorpusCandidateMiner
{
    public const SCHEMA_VERSION = 'atlas.corpus.gated_candidate_miner.v1';

    /** @var list<string> */
    public const PROTECTED = ['sensitive', 'secret', 'cyber'];

    public const SOURCE_UNKNOWN = 'unknown';

    public const STATUS_OK = 'ok';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SOURCE = 'source';
    public const FIELD_ORIGIN_REF = 'origin_ref';
    public const FIELD_CANDIDATE_HASH = 'candidate_hash';
    public const FIELD_ADMISSION = 'admission';
    public const FIELD_STATUS = 'status';
    public const FIELD_CANDIDATES = 'candidates';
    public const FIELD_DIRECT_WRITE = 'direct_write';
    public const FIELD_CANDIDATE_ONLY = 'candidate_only';
    public const FIELD_COUNT_IS_ACCEPTANCE = 'count_is_acceptance';
    public const FIELD_IMMUNE_GATES_APPLY = 'immune_gates_apply';
    public const FIELD_OMITTED = 'omitted';
    public const FIELD_VIA_ASI_02 = 'via_asi_02';
    public const FIELD_WRITES_MEMORY_DIRECTLY = 'writes_memory_directly';
    public const FIELD_TEXT = 'text';
    public const FIELD_REF = 'ref';
    public const FIELD_PRIVACY_CLASS = 'privacy_class';
    public const FIELD_NORMAL = 'normal';
    public const FIELD_PROTECTED_CLASS_OMITTED = 'protected_class_omitted';

    /**
     * @param  list<array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    public static function mine(array $sources): array
    {
        $candidates = [];
        $omitted = [];
        foreach ($sources as $source) {
            $privacy = AiValueNormalizer::lowerTrimmedString($source[self::FIELD_PRIVACY_CLASS] ?? self::FIELD_NORMAL);
            if (in_array($privacy, self::PROTECTED, true)) {
                $omitted[] = self::FIELD_PROTECTED_CLASS_OMITTED;
                continue;
            }
            $text = AiValueNormalizer::trimmedStringOrNull($source[self::FIELD_TEXT] ?? null);
            $ref = AiValueNormalizer::trimmedStringOrNull($source[self::FIELD_REF] ?? null);
            if ($text === null || $ref === null) {
                continue;
            }
            $candidates[] = [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_ORIGIN_REF => $ref,
                self::FIELD_SOURCE => AiValueNormalizer::trimmedStringOrNull($source[self::FIELD_SOURCE] ?? null) ?? self::SOURCE_UNKNOWN,
                self::FIELD_CANDIDATE_HASH => sha1($ref."\n".$text),
                self::FIELD_ADMISSION => [
                    self::FIELD_VIA_ASI_02 => true,
                    self::FIELD_IMMUNE_GATES_APPLY => true,
                    self::FIELD_DIRECT_WRITE => false,
                ],
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $candidates === [] ? self::STATUS_INSUFFICIENT_SIGNAL : self::STATUS_OK,
            self::FIELD_CANDIDATES => $candidates,
            self::FIELD_OMITTED => array_values(array_unique($omitted)),
            self::FIELD_SOURCE => [
                self::FIELD_CANDIDATE_ONLY => true,
                self::FIELD_WRITES_MEMORY_DIRECTLY => false,
                self::FIELD_COUNT_IS_ACCEPTANCE => false,
            ],
        ];
    }
}
