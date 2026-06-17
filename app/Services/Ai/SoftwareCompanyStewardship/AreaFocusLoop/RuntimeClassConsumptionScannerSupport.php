<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Cohesive extraction from {@see RuntimeClassConsumptionScanner}: the
 * "which new product class is consumed by some other product file" loop.
 *
 * Kept on its own so the orchestrator's {@see RuntimeClassConsumptionScanner::scan()}
 * stays a thin composer over (a) baseline resolution, (b) new-file detection,
 * (c) THIS helper. The helper owns the per-new-file candidate fan-out
 * (word-boundary git grep + tokenizer-confirmed source read).
 */
final class RuntimeClassConsumptionScannerSupport
{
    public function __construct(private readonly RuntimeClassConsumptionScanner $scanner)
    {
    }

    /**
     * For each (short name => own relpath) new product class, find whether ANY
     * other product .php in the worktree references it as CODE. Returns the
     * subset of short names that are consumed (order preserved per discovery,
     * with duplicates collapsed by the orchestrator's normalizer).
     *
     * @param  array<string,string>  $newFiles  short name => relpath of the new class file
     * @param  string  $worktree  filesystem root to grep + read
     * @return list<string>
     */
    public function findConsumedForNewFiles(array $newFiles, string $worktree): array
    {
        $consumed = [];
        foreach ($newFiles as $short => $ownFile) {
            // Word-boundary grep narrows candidate consumers cheaply (so 'Port'
            // does not even list 'Portfolio'); search the WORKTREE so this cycle's
            // own wiring counts. Tokenizer confirmation rejects comment/string hits.
            $grep = $this->scanner->runGit($worktree, ['grep', '-l', '-w', '--', $short, '--', 'app']);
            if ($grep['ok'] !== true) {
                continue; // grep exit 1 = no match anywhere
            }
            foreach (preg_split('/\R/', trim((string) $grep['out'])) ?: [] as $hit) {
                $hit = trim($hit);
                if ($hit === '' || $hit === $ownFile || ! $this->scanner->isProductPhpClassPath($hit)) {
                    continue;
                }
                $src = @file_get_contents($worktree.'/'.$hit);
                if (is_string($src) && $this->scanner->sourceReferencesClass($src, $short)) {
                    $consumed[] = $short;
                    break;
                }
            }
        }

        return $consumed;
    }
}
