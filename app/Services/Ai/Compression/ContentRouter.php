<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression;

use App\Services\Ai\Compression\Contracts\Compressor;

/**
 * Routes a content block to the first compressor whose detect() matches (AP-813).
 *
 * Priority is deterministic and specific-before-generic: json → log → search →
 * diff → text. `text` is the conservative fallback (it only collapses provable
 * redundancy), so it is consulted last. Compressors are registered, never hardcoded
 * here — a new content type is onboarded by registering a Compressor.
 */
final class ContentRouter
{
    /** @var array<string, Compressor> keyed by contentType() */
    private array $compressors = [];

    /** @var list<string> detection priority (specific first, text fallback last) */
    private const PRIORITY = ['json', 'log', 'search', 'diff', 'text'];

    /**
     * @param  iterable<Compressor>  $compressors
     */
    public function __construct(iterable $compressors = [])
    {
        foreach ($compressors as $compressor) {
            $this->register($compressor);
        }
    }

    public function register(Compressor $compressor): void
    {
        $this->compressors[$compressor->contentType()] = $compressor;
    }

    /** First matching compressor in priority order, or null. */
    public function route(string $block): ?Compressor
    {
        foreach (self::PRIORITY as $type) {
            $compressor = $this->compressors[$type] ?? null;
            if ($compressor !== null && $compressor->detect($block)) {
                return $compressor;
            }
        }

        // Any registered compressor not in the priority list (extensibility).
        foreach ($this->compressors as $type => $compressor) {
            if (! in_array($type, self::PRIORITY, true) && $compressor->detect($block)) {
                return $compressor;
            }
        }

        return null;
    }

    /** @return list<string> registered content types */
    public function registeredTypes(): array
    {
        return array_keys($this->compressors);
    }
}
