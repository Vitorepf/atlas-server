<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * REFACTOR ORIGINATION SCOUT — the brain-side producer the design-spec gate
 * was waiting for. Scans a directory's PHP files with the SAME duplication
 * metric the delta prover judges deliveries by, and turns the strongest
 * cross-file duplication cluster into a complete, evidence-backed
 * refactor_design_spec + staged chain (via the composer) ready to enqueue.
 *
 * Every spec field is DERIVED from measured evidence — file names, block
 * counts, excerpt — never invented. No cluster ⇒ honest empty answer
 * (origination abstains; it never fabricates work to look busy).
 *
 * Deterministic and provider-free: this is the evidence floor of origination.
 * A semantic (LLM) design critic can later REFINE these specs; it does not
 * replace the measurement.
 */
final class AtlasRefactorOpportunityScout
{
    public const SCHEMA = 'atlas.task_serving.refactor_opportunity.v1';

    public function __construct(
        private readonly ?AtlasRefactorDeltaProver $prover = null,
        private readonly ?AtlasRefactorChainComposer $composer = null,
    ) {}

    /**
     * @return array{schema:string, status:string, cluster?:array<string,mixed>, design?:array<string,mixed>, chain?:list<array<string,mixed>>}
     */
    public function scout(string $root, string $relativeDir, int $maxFiles = 200): array
    {
        $dir = rtrim($root, '/').'/'.trim($relativeDir, '/');
        if (! is_dir($dir)) {
            return ['schema' => self::SCHEMA, 'status' => 'directory_not_found'];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (count($files) >= $maxFiles) {
                break;
            }
            /** @var \SplFileInfo $item */
            if (! $item->isFile() || $item->getExtension() !== 'php') {
                continue;
            }
            $rel = ltrim(str_replace(rtrim($root, '/'), '', $item->getPathname()), '/');
            if (str_contains($rel, '/vendor/') || str_ends_with($rel, 'Test.php')) {
                continue;
            }
            $files[$rel] = (string) file_get_contents($item->getPathname());
        }
        if (count($files) < 2) {
            return ['schema' => self::SCHEMA, 'status' => 'not_enough_files', 'files_scanned' => count($files)];
        }

        $clusters = ($this->prover ?? new AtlasRefactorDeltaProver)->duplicateClusters($files);
        if ($clusters === []) {
            return ['schema' => self::SCHEMA, 'status' => 'no_cross_file_duplication', 'files_scanned' => count($files)];
        }

        $top = $clusters[0];
        $callers = $top['files'];
        $seamDir = dirname($callers[0]);
        $seamFile = $seamDir.'/Shared'.preg_replace('/\.php$/', '', basename($callers[0])).'Seam.php';
        $blockCount = (int) $top['occurrences'];

        $design = [
            'problem' => sprintf(
                'the same %d-line block appears %d times across %d files (%s) — measured by the delta prover shingle metric, hash %s',
                6, $blockCount, count($callers), implode(', ', $callers), $top['shingle_hash'],
            ),
            'proposed_abstraction' => sprintf(
                'extract the duplicated block into %s as the single seam and make every caller consume it',
                $seamFile,
            ),
            'rejected_alternative' => 'keeping per-file copies (or hiding them behind a trait mixin) preserves the duplication as callable drift — every future fix must be applied N times',
            'risk' => 'callers may carry local variations of the duplicated block — diff each occurrence against the excerpt before extracting; behavior must be preserved per caller',
            'expected_delta' => sprintf(
                'duplicate_blocks shrinks by at least %d and loc shrinks across the %d callers (prover-verifiable at report time)',
                max(1, $blockCount - 1), count($callers),
            ),
            'callers' => $callers,
        ];

        $chain = ($this->composer ?? new AtlasRefactorChainComposer)->compose([
            'objective' => 'refatore a duplicação medida em '.$relativeDir.' para uma costura única',
            'refactor_design_spec' => $design,
            'seam_files' => [$seamFile, $callers[0]],
            'caller_files' => array_slice($callers, 1),
            'legacy_files' => [],
            'proof_files' => ['tests/Unit/'.preg_replace('/^app\//', '', $seamDir).'/SharedSeamEquivalenceTest.php'],
        ]);

        return [
            'schema' => self::SCHEMA,
            'status' => 'opportunity_found',
            'files_scanned' => count($files),
            'cluster' => $top,
            'design' => $design,
            'chain' => $chain,
        ];
    }
}
