<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support\BaselineSignature;


/**
 * SCOPED delta-gate: compares a fresh-run snapshot of failures against the
 * pinned {@see BaselineSignature} and reports ONLY the new failures.
 *
 * Pre-existing reds (the 4 documented HTTP-smoke failures, the documented
 * pint-violation file set, the documented phpstan error set) are explicitly
 * excluded from the delta and listed in {@see DeltaGateResult::excludedPreExisting}.
 *
 * Tamper-resistance: the gate is bound to a single `BaselineSignature`
 * instance captured at construction time. Re-loading the artifact, mutating
 * the on-disk file, or passing an alternative signature to the comparison
 * methods has no effect on a gate that was already constructed against the
 * pinned copy. The result carries {@see DeltaGateResult::baselinePayloadHash}
 * so callers can prove the gate is anchored to the pinned copy.
 */
final class DeltaGate
{
    private readonly BaselineSignature $baseline;

    /**
     * @var non-empty-string SHA-256 of the canonical payload the bound
     *                       baseline was constructed from. Captured once at construction so
     *                       a validator can prove the gate was bound to the pinned copy.
     */
    public readonly string $baselinePayloadHash;

    public function __construct(BaselineSignature $baseline)
    {
        $this->baseline = $baseline;
        $this->baselinePayloadHash = $baseline->payloadHash;
    }

    /**
     * Build a gate anchored to the pinned artifact loaded by `$loader`. The
     * loader's snapshot is captured exactly once, so re-calling `fromLoader`
     * within a single run returns a gate bound to the originally-loaded copy.
     */
    public static function fromLoader(BaselineSignatureLoader $loader): self
    {
        return new self($loader->load());
    }

    /**
     * Delta-match a fresh run against the pinned baseline.
     *
     * @param  iterable<string>  $freshTestIds  Fresh failing test identifiers (`<file path>::<method>`).
     * @param  iterable<array{file: string, line: int, message: string, identifier?: ?string}>  $freshPhpstan
     * @param  iterable<string>  $freshPintFiles
     */
    public function compare(
        iterable $freshTestIds,
        iterable $freshPhpstan,
        iterable $freshPintFiles,
    ): DeltaGateResult {
        $excludedTests = $this->indexExcludedTestIds();
        $excludedPhpstan = $this->indexExcludedPhpstan();
        $excludedPint = $this->indexExcludedPintFiles();

        $newTests = [];
        $seenNewTests = [];
        $excludedTestsSeen = [];
        foreach ($freshTestIds as $id) {
            $id = (string) $id;
            if ($id === '') {
                continue;
            }
            if (isset($excludedTests[$id])) {
                $excludedTestsSeen[$id] = true;

                continue;
            }
            if (! isset($seenNewTests[$id])) {
                $seenNewTests[$id] = true;
                $newTests[] = $id;
            }
        }

        $newPhpstan = [];
        $seenNewPhpstan = [];
        $excludedPhpstanSeen = [];
        foreach ($freshPhpstan as $entry) {
            $normalized = $this->normalizePhpstanEntry($entry);
            if ($normalized === null) {
                continue;
            }
            $key = $this->phpstanKey($normalized);
            if (isset($excludedPhpstan[$key])) {
                $excludedPhpstanSeen[$key] = true;

                continue;
            }
            if (! isset($seenNewPhpstan[$key])) {
                $seenNewPhpstan[$key] = true;
                $newPhpstan[] = $normalized;
            }
        }

        $newPint = [];
        $seenNewPint = [];
        $excludedPintSeen = [];
        foreach ($freshPintFiles as $file) {
            $file = (string) $file;
            if ($file === '') {
                continue;
            }
            if (isset($excludedPint[$file])) {
                $excludedPintSeen[$file] = true;

                continue;
            }
            if (! isset($seenNewPint[$file])) {
                $seenNewPint[$file] = true;
                $newPint[] = $file;
            }
        }

        $passes = $newTests === [] && $newPhpstan === [] && $newPint === [];

        return new DeltaGateResult(
            passes: $passes,
            newFailures: [
                'tests' => $newTests,
                'phpstan' => $newPhpstan,
                'pint' => $newPint,
            ],
            excludedPreExisting: [
                'tests' => array_keys($excludedTestsSeen),
                'phpstan' => array_values(array_map(
                    fn (string $k): array => $excludedPhpstan[$k],
                    array_keys($excludedPhpstanSeen),
                )),
                'pint' => array_keys($excludedPintSeen),
            ],
            baselinePayloadHash: $this->baselinePayloadHash,
        );
    }

    /**
     * @return array<string, bool>
     */
    private function indexExcludedTestIds(): array
    {
        $index = [];
        foreach ($this->baseline->excludedTestIds() as $id) {
            $index[(string) $id] = true;
        }

        return $index;
    }

    /**
     * @return array<string, array{file: string, line: int, message: string, identifier?: ?string}>
     */
    private function indexExcludedPhpstan(): array
    {
        $index = [];
        foreach ($this->baseline->excludedPhpstanEntries() as $entry) {
            $index[$this->phpstanKey($entry)] = $entry;
        }

        return $index;
    }

    /**
     * @return array<string, bool>
     */
    private function indexExcludedPintFiles(): array
    {
        $index = [];
        foreach ($this->baseline->excludedPintFiles() as $file) {
            $index[(string) $file] = true;
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{file: string, line: int, message: string, identifier?: ?string}|null
     */
    private function normalizePhpstanEntry(array $entry): ?array
    {
        $file = (string) ($entry['file'] ?? '');
        $message = (string) ($entry['message'] ?? '');
        if ($file === '' || $message === '') {
            return null;
        }

        return [
            'file' => $file,
            'line' => (int) ($entry['line'] ?? 0),
            'message' => $message,
            'identifier' => isset($entry['identifier']) ? (string) $entry['identifier'] : null,
        ];
    }

    /**
     * Stable delta-match key for a phpstan entry. The phpstan identifier alone
     * is not enough because the same identifier can repeat on the same line;
     * the (file, line, normalized first-line-of-message) tuple uniquely
     * identifies a single phpstan finding.
     *
     * @param  array{file: string, line: int, message: string, identifier?: ?string}  $entry
     */
    private function phpstanKey(array $entry): string
    {
        $messageFirstLine = trim((string) preg_replace('/\s+/', ' ', explode("\n", $entry['message'])[0]));

        return $entry['file'].':'.$entry['line'].'::'.$messageFirstLine;
    }
}
