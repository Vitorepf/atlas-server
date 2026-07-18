<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * MAXI-05 — pure derivation of immune signature families from incident facts.
 *
 * Privacy: only content_hash, sorted marker names, and hostile_class enter the
 * family payload. Raw capture or memory text is never stored or hashed inline.
 */
final class ImmuneSignatureDeriver
{
    public const SCHEMA_VERSION = 'atlas.cognition.immune_signature_family.v1';
    public const FIELD_CONTENT_HASH = 'content_hash';
    public const FIELD_MARKER_CENTROID = 'marker_centroid';
    public const FIELD_SIGNATURE = 'signature';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_FAMILY = 'family';
    public const FIELD_HOSTILE_CLASS = 'hostile_class';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_PRIVATE_SENSITIVE = 'private_sensitive';
    public const FIELD_UNTRUSTED_CONTENT = 'untrusted_content';

    /** @var list<string> */
    public const HOSTILE_CLASSES = [
        'prompt_injection',
        self::FIELD_PRIVATE_SENSITIVE,
        self::FIELD_UNTRUSTED_CONTENT,
    ];

    /**
     * @param  list<string>  $matchedSignals
     * @return array{signature:string,family:array<string,mixed>}
     */
    public function derive(string $contentHash, string $hostileClass, array $matchedSignals): array
    {
        $family = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_CONTENT_HASH => $this->normalizeHash($contentHash),
            self::FIELD_MARKER_CENTROID => $this->markerCentroid($matchedSignals),
            self::FIELD_HOSTILE_CLASS => $this->normalizeClass($hostileClass),
        ];

        return [
            self::FIELD_SIGNATURE => hash(self::FIELD_SHA256, json_encode($family, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            self::FIELD_FAMILY => $family,
        ];
    }

    public function contentHashFromText(string $text): string
    {
        return hash(self::FIELD_SHA256, $text);
    }

    /**
     * @param  list<string>  $matchedSignals
     */
    public function markerCentroid(array $matchedSignals): string
    {
        $signals = $this->normalizeSignals($matchedSignals);

        return hash(self::FIELD_SHA256, implode('|', $signals));
    }

    /**
     * @param  list<string>  $matchedSignals
     * @return list<string>
     */
    public function normalizeSignals(array $matchedSignals): array
    {
        $out = [];
        foreach ($matchedSignals as $signal) {
            $signal = AiValueNormalizer::lowerTrimmedString($signal);
            if ($signal !== '' && preg_match('/^[a-z0-9_]+$/', $signal) === 1) {
                $out[$signal] = true;
            }
        }

        $keys = array_keys($out);
        sort($keys);

        return $keys;
    }

    public function normalizeHash(string $contentHash): string
    {
        $contentHash = AiValueNormalizer::lowerTrimmedString($contentHash);

        return preg_match('/^[a-f0-9]{64}$/', $contentHash) === 1
            ? $contentHash
            : hash(self::FIELD_SHA256, $contentHash);
    }

    public function normalizeClass(string $hostileClass): string
    {
        $hostileClass = AiValueNormalizer::lowerTrimmedString($hostileClass);

        return in_array($hostileClass, self::HOSTILE_CLASSES, true)
            ? $hostileClass
            : 'untrusted_content';
    }
}
