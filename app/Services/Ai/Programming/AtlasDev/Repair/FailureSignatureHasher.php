<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;

/**
 * Two-step signature normalizer for repair-loop convergence detection.
 *
 * The DTO {@see FailureCapsule::signatureOf()} already collapses whitespace
 * and lowercases the excerpt, but a raw error log carries volatile noise
 * (timestamps, absolute paths, run ids, stack-trace line numbers) that
 * would defeat the "same_signature_twice" stop rule across attempts.
 *
 * This hasher cleans those volatile fragments BEFORE the excerpt reaches
 * the DTO, so two attempts that fail "for the same reason" produce the
 * same `failure_signature` even when their logs differ in surface detail.
 *
 * The DTO contract is preserved: callers always pass the normalized
 * excerpt to {@see FailureCapsule::issue()}, and the DTO performs its own
 * idempotent whitespace + lowercase step on top.
 */
final class FailureSignatureHasher
{
    /**
     * Patterns of volatile fragments stripped before hashing. Order matters:
     * longer/more specific patterns come first to avoid partial overlap.
     *
     * @var list<array{0:string,1:string}>  list of [regex, replacement]
     */
    private const VOLATILE_PATTERNS = [
        // ISO-8601 timestamps with optional fractional seconds and timezone.
        ['/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\b/i', '<ts>'],
        // Date-only stamps like 2026-05-16.
        ['/\b\d{4}-\d{2}-\d{2}\b/', '<date>'],
        // Time-only stamps like 12:34:56.789 (with optional ms).
        ['/\b\d{2}:\d{2}:\d{2}(?:\.\d+)?\b/', '<time>'],
        // Epoch seconds/milliseconds (10-13 digits).
        ['/\b1[0-9]{9,12}\b/', '<epoch>'],
        // UUIDs / ordered UUIDs.
        ['/\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/', '<uuid>'],
        // Process ids and "pid 12345" style fragments.
        ['/\bpid[:= ]\s*\d+/i', 'pid=<n>'],
        // Stack-trace line numbers: ":123" or ":123:45" right after a file path.
        ['/(\.[A-Za-z0-9_]+):\d+(?::\d+)?/', '$1:<line>'],
        // Absolute POSIX paths (/Users/..., /tmp/..., /var/..., /private/...).
        ['#(?:/(?:Users|home|tmp|var|private|opt|Volumes|System|Library)/[\w./@%+~\-]+)#', '<path>'],
        // Long lowercase hex blobs (>= 16 chars) — typical of sha256/blob ids.
        ['/\b[0-9a-f]{16,}\b/', '<hex>'],
        // Memory addresses: 0xdeadbeef.
        ['/\b0x[0-9a-fA-F]+\b/', '<addr>'],
        // Port numbers in URLs: :PORT before path/end-of-string.
        ['#(://[^/:]+):\d{2,5}#', '$1:<port>'],
    ];

    /**
     * Apply the volatile-fragment stripper. Whitespace runs collapse to a
     * single space; leading/trailing whitespace is trimmed. The output is
     * lowercase to converge "Error" / "ERROR" / "error" variants.
     */
    public function normalize(string $rawExcerpt): string
    {
        if ($rawExcerpt === '') {
            return '';
        }

        $normalized = $rawExcerpt;
        foreach (self::VOLATILE_PATTERNS as [$regex, $replacement]) {
            $next = preg_replace($regex, $replacement, $normalized);
            if (is_string($next)) {
                $normalized = $next;
            }
        }

        $collapsed = preg_replace('/\s+/', ' ', $normalized);
        if (! is_string($collapsed)) {
            $collapsed = $normalized;
        }

        return strtolower(trim($collapsed));
    }

    /**
     * Compute the deterministic signature the DTO would produce when fed the
     * normalized excerpt. Useful for tests and tooling that want to assert
     * "these two raw logs would converge" without going through the DTO.
     */
    public function signature(string $gate, string $rawExcerpt): string
    {
        return FailureCapsule::signatureOf($gate, $this->normalize($rawExcerpt));
    }
}
