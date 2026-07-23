<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support\BaselineSignature;


/**
 * Immutable snapshot of the pre-existing "reds" in the AtlasDev scope, captured
 * once at mission start (M0) and pinned to a committed artifact. The mission
 * delta-gate compares a fresh run against this signature and reports ONLY the
 * new failures; the documented pre-existing reds are explicitly excluded.
 *
 * Identity contract per section (each entry carries enough identity for delta
 * matching against a fresh run):
 *   - tests:   { id, file, test }
 *       id is "<file path>::<test method>" and is the delta-match key.
 *   - phpstan: { file, line, message, identifier }
 *       delta-match key is (file, line, message-trimmed-prefix) — line + the
 *       phpstan identifier alone is not enough because the same line/identifier
 *       can repeat; the message first-line disambiguates.
 *   - pint:    { file }
 *       delta-match key is the file path (pint does not report per-line).
 *
 * The signature is a value object: it is constructed once from the pinned
 * artifact, never mutated, and exposes only read-only accessors. The
 * {@see DeltaGate} receives a `BaselineSignature` instance and the gate's
 * verdict depends solely on the instance it was constructed with; mutating the
 * on-disk artifact during a gated run therefore cannot mask a new failure.
 */
final class BaselineSignature
{
    public const SCHEMA_VERSION = 'atlas-dev-elevation.baseline-signature.v1';

    /**
     * SHA-256 of the canonical JSON payload the snapshot was constructed from.
     * Captured at load time so the delta-gate can prove (and assert) it is
     * using the pinned copy, not a re-read mid-run.
     */
    public readonly string $payloadHash;

    /**
     * @param  array{
     *     schema_version: string,
     *     captured_at: string,
     *     scope: array{tests: string, phpstan: string, pint: string},
     *     tests: array{count: int, entries: list<array{id: string, file: string, test: string, reason?: string}>},
     *     phpstan: array{count: int, entries: list<array{file: string, line: int, message: string, identifier?: ?string}>},
     *     pint: array{count: int, entries: list<array{file: string}>},
     * }  $payload
     * @param  list<string>  $testIds
     * @param  list<array{file: string, line: int, message: string, identifier?: ?string}>  $phpstanEntries
     * @param  list<string>  $pintFiles
     */
    private function __construct(
        public readonly array $payload,
        public readonly array $testIds,
        public readonly array $phpstanEntries,
        public readonly array $pintFiles,
        string $payloadHash,
    ) {
        $this->payloadHash = $payloadHash;
    }

    /**
     * Build an immutable snapshot from the decoded JSON payload of the pinned
     * artifact. Validates the schema_version and the documented counts so a
     * truncated/tampered artifact fails loudly at load time rather than
     * silently producing a lax delta.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        self::assertShape($payload);

        $testIds = array_values(array_unique(array_map(
            static fn (array $e): string => (string) ($e['id'] ?? ''),
            $payload['tests']['entries'],
        )));

        $phpstan = array_values(array_map(
            static fn (array $e): array => [
                'file' => (string) $e['file'],
                'line' => (int) $e['line'],
                'message' => (string) $e['message'],
                'identifier' => isset($e['identifier']) ? (string) $e['identifier'] : null,
            ],
            $payload['phpstan']['entries'],
        ));

        $pintFiles = array_values(array_unique(array_map(
            static fn (array $e): string => (string) $e['file'],
            $payload['pint']['entries'],
        )));

        return new self(
            payload: $payload,
            testIds: $testIds,
            phpstanEntries: $phpstan,
            pintFiles: $pintFiles,
            payloadHash: self::computePayloadHash($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function computePayloadHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    public function testCount(): int
    {
        return (int) ($this->payload['tests']['count'] ?? 0);
    }

    public function phpstanCount(): int
    {
        return (int) ($this->payload['phpstan']['count'] ?? 0);
    }

    public function pintCount(): int
    {
        return (int) ($this->payload['pint']['count'] ?? 0);
    }

    /**
     * @return list<string>
     */
    public function excludedTestIds(): array
    {
        return $this->testIds;
    }

    /**
     * @return list<array{file: string, line: int, message: string, identifier?: ?string}>
     */
    public function excludedPhpstanEntries(): array
    {
        return $this->phpstanEntries;
    }

    /**
     * @return list<string>
     */
    public function excludedPintFiles(): array
    {
        return $this->pintFiles;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function assertShape(array $payload): void
    {
        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException(
                'BaselineSignature: unsupported schema_version; expected '.self::SCHEMA_VERSION
                .', got '.var_export($payload['schema_version'] ?? null, true)
            );
        }

        foreach (['tests', 'phpstan', 'pint'] as $section) {
            if (! isset($payload[$section]['count'], $payload[$section]['entries']) || ! is_array($payload[$section]['entries'])) {
                throw new \InvalidArgumentException(
                    'BaselineSignature: missing or malformed section ['.$section.']'
                );
            }

            $declared = (int) $payload[$section]['count'];
            $actual = count($payload[$section]['entries']);
            if ($declared !== $actual) {
                throw new \InvalidArgumentException(
                    'BaselineSignature: section ['.$section.'] declared count '.$declared
                    .' does not match entries count '.$actual
                );
            }
        }
    }
}
