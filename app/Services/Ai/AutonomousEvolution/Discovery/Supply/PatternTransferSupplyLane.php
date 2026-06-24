<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Supply;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * Pattern-transfer supply lane.
 *
 * ANTI-GOODHART: this lane never mints behavior-preserving refactor or coverage-only work. It emits a
 * directive only when the comprehension model proves a material asymmetry between sibling subdirs: one sibling
 * already has a concrete suffix-pattern class (for example `*Validator`) while the other has the structural
 * slot (classes with a target prefix) but no class with that suffix. The actual implementation must still be
 * red→green (`red_required=true`); the structural pattern gap is a supply signal, not proof of completion.
 */
final class PatternTransferSupplyLane implements SupplyLaneContract
{
    public const OBJECTIVE_KIND = 'pattern_transfer';

    /**
     * Structural entrypoint suffixes are evidence that a subdir has a slot, not transfer-worthy helper patterns.
     *
     * @var array<string,true>
     */
    private const NON_TRANSFER_SUFFIXES = [
        'Controller' => true,
    ];

    /**
     * @return list<array{objective:string, payload:array<string,mixed>, members:list<string>}>
     */
    public function mint(AtlasLoopScopeComprehensionModel $model, string $repoRoot): array
    {
        rtrim($repoRoot, '/');

        $forbidden = array_fill_keys(array_map(
            static fn (string $path): string => ltrim($path, '/'),
            array_values($model->forbidden),
        ), true);

        $bySubdir = [];
        foreach ($model->inventory as $node) {
            $path = $this->nodePath($node);
            $fqcn = ltrim((string) ($node['fqcn'] ?? ''), '\\');
            if ($path === '' || $fqcn === '') {
                continue;
            }

            $bySubdir[$this->subdir($path)][] = [
                'path' => $path,
                'fqcn' => $fqcn,
                'stem' => pathinfo($path, PATHINFO_FILENAME),
                'is_forbidden' => (bool) ($node['is_forbidden'] ?? false),
            ];
        }

        if (count($bySubdir) < 2) {
            return [];
        }

        ksort($bySubdir, SORT_STRING);

        $byExpected = [];
        foreach ($bySubdir as $sourceSubdir => $sourceNodes) {
            $sourceParent = $this->parentSubdir($sourceSubdir);
            foreach ($sourceNodes as $source) {
                $sourcePath = $source['path'];
                if ($source['is_forbidden'] || isset($forbidden[$sourcePath])) {
                    continue;
                }

                $suffix = $this->pascalSuffix($source['stem']);
                if (strlen($suffix) < 3 || isset(self::NON_TRANSFER_SUFFIXES[$suffix])) {
                    continue;
                }

                foreach ($bySubdir as $targetSubdir => $targetNodes) {
                    if ($targetSubdir === $sourceSubdir || $this->parentSubdir($targetSubdir) !== $sourceParent) {
                        continue;
                    }
                    if ($this->subdirHasSuffix($targetNodes, $suffix)) {
                        continue;
                    }

                    $targetPrefix = $this->targetPrefix($targetNodes);
                    $expectedBasename = $targetPrefix.$suffix.'.php';
                    $expectedPath = trim($targetSubdir.'/'.$expectedBasename, '/');
                    $dedupKey = $targetSubdir.'|'.$suffix;

                    if (isset($byExpected[$dedupKey])) {
                        continue;
                    }

                    $byExpected[$dedupKey] = [
                        'expected_path' => $expectedPath,
                        'spec' => $this->spec(
                            sourceSubdir: $sourceSubdir,
                            sourcePath: $sourcePath,
                            sourceFqcn: $source['fqcn'],
                            targetSubdir: $targetSubdir,
                            expectedBasename: $expectedBasename,
                            expectedPath: $expectedPath,
                            suffix: $suffix,
                        ),
                    ];
                }
            }
        }

        $rows = array_values($byExpected);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['expected_path'], (string) $b['expected_path']));

        return array_values(array_map(static fn (array $row): array => $row['spec'], $rows));
    }

    /**
     * @return array{objective:string,payload:array<string,mixed>,members:list<string>}
     */
    private function spec(
        string $sourceSubdir,
        string $sourcePath,
        string $sourceFqcn,
        string $targetSubdir,
        string $expectedBasename,
        string $expectedPath,
        string $suffix,
    ): array {
        return [
            'objective' => sprintf(
                'O padrão `*%s` está provado em %s (ex.: %s) mas ausente em %s. Originar `%s` em %s com red test que falha por inexistência → implementar até verde.',
                $suffix,
                $sourceSubdir,
                $sourcePath,
                $targetSubdir,
                $expectedBasename,
                $targetSubdir,
            ),
            'payload' => [
                'objective_kind' => self::OBJECTIVE_KIND,
                'source' => 'pattern_transfer',
                'pattern_suffix' => $suffix,
                'source_fqcn' => $sourceFqcn,
                'source_path' => $sourcePath,
                'target_subdir' => $targetSubdir,
                'expected_path' => $expectedPath,
                'red_required' => true,
                'comprehension_originated' => true,
                'provenance' => AtlasLoopScopeComprehensionModel::PROVENANCE_WRITABLE_PROSE,
            ],
            'members' => [$sourcePath, $targetSubdir],
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     */
    private function nodePath(array $node): string
    {
        return ltrim(trim((string) ($node['rel_path'] ?? ($node['path'] ?? ''))), '/');
    }

    private function subdir(string $path): string
    {
        return trim(str_replace('\\', '/', dirname(ltrim($path, '/'))), './');
    }

    private function parentSubdir(string $subdir): string
    {
        return trim(str_replace('\\', '/', dirname($subdir)), './');
    }

    private function pascalSuffix(string $stem): string
    {
        if (preg_match('/([A-Z][a-z0-9]*|[A-Z]+)$/', $stem, $match) !== 1) {
            return '';
        }

        return (string) $match[1];
    }

    /**
     * @param  list<array{path:string,fqcn:string,stem:string,is_forbidden:bool}>  $nodes
     */
    private function subdirHasSuffix(array $nodes, string $suffix): bool
    {
        foreach ($nodes as $node) {
            if ($this->pascalSuffix($node['stem']) === $suffix) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{path:string,fqcn:string,stem:string,is_forbidden:bool}>  $nodes
     */
    private function targetPrefix(array $nodes): string
    {
        usort($nodes, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        foreach ($nodes as $node) {
            $suffix = $this->pascalSuffix($node['stem']);
            if ($suffix === '') {
                continue;
            }
            $prefix = substr($node['stem'], 0, -strlen($suffix));
            if ($prefix !== '') {
                return $prefix;
            }
        }

        return '';
    }
}
