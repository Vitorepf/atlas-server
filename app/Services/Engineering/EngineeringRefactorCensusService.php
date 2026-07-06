<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasRefactorDeltaProver;

/**
 * REFACTOR CENSUS — órgão permanente da capacidade que agentes descartáveis
 * usaram nas Obras #8/#12: shape-census de métodos por hash (corpo normalizado
 * strings→S, dígitos→N, whitespace colapsado → sha256) + conta LÍQUIDA honesta
 * embutida com as regras de calibração (corpo ≤3 linhas/site = nao_paga;
 * líquido ≤0 = nao_paga). Read-only: nunca autoriza delete/refactor sozinho.
 *
 * ponytail: token_get_all + brace-matching, não AST; se um método real for
 * mal recortado, upgrade para nikic/php-parser (já em vendor).
 */
final class EngineeringRefactorCensusService
{
    public const SCHEMA_VERSION = 'atlas.engineering.refactor_census.v1';

    /** Linhas de corpo por site abaixo/igual disto = extração não paga. */
    private const MIN_PAYING_BODY_LINES = 3;

    /** Overhead fixo estimado do helper extraído (assinatura+chaves+docblock). */
    private const HELPER_OVERHEAD_LINES = 4;

    private const TOP_CLUSTERS = 25;

    private const TOP_SHINGLE_CLUSTERS = 10;

    public const CALIBRATION_NOTE = 'estimativas de finder superestimam 3-10x — validar com byte-prova na execução';

    public function __construct(private readonly AtlasRefactorDeltaProver $prover) {}

    /**
     * @return array<string,mixed> envelope canônico
     */
    public function census(string $path, int $minCluster = 4): array
    {
        $files = $this->phpFiles($path);
        if ($files === []) {
            return $this->envelope($path, $minCluster, 'empty', 0, [], []);
        }

        $byShape = [];
        foreach ($files as $file => $content) {
            foreach ($this->methodShapes($content) as $shape) {
                $byShape[$shape['hash']][] = ['file' => $file, 'method' => $shape['name'], 'body_lines' => $shape['body_lines']];
            }
        }

        $clusters = [];
        foreach ($byShape as $hash => $sites) {
            if (count($sites) < $minCluster) {
                continue;
            }
            $bodyLines = $sites[0]['body_lines'];
            $filesInCluster = array_values(array_unique(array_column($sites, 'file')));
            // Conta líquida honesta (Obra #8/#12): sites×linhas − 2×arquivos de
            // adoção (import+callsite churn) − overhead do helper extraído.
            $liquid = count($sites) * $bodyLines
                - 2 * count($filesInCluster)
                - ($bodyLines + self::HELPER_OVERHEAD_LINES);
            $flag = ($bodyLines <= self::MIN_PAYING_BODY_LINES || $liquid <= 0) ? 'nao_paga' : 'pagante';

            $clusters[] = [
                'shape_hash' => $hash,
                'sites' => count($sites),
                'files' => $filesInCluster,
                'body_lines_per_site' => $bodyLines,
                'methods' => array_values(array_unique(array_column($sites, 'method'))),
                'loc_liquida_estimada' => $liquid,
                'flag' => $flag,
            ];
        }
        usort($clusters, static fn (array $a, array $b): int => $b['loc_liquida_estimada'] <=> $a['loc_liquida_estimada']);

        return $this->envelope($path, $minCluster, 'ok', count($files), $clusters, array_slice($this->prover->duplicateClusters($files), 0, self::TOP_SHINGLE_CLUSTERS));
    }

    /**
     * @param  list<array<string,mixed>>  $clusters
     * @param  list<array<string,mixed>>  $shingleClusters
     * @return array<string,mixed>
     */
    private function envelope(string $path, int $minCluster, string $status, int $filesScanned, array $clusters, array $shingleClusters): array
    {
        $paying = array_values(array_filter($clusters, static fn (array $c): bool => $c['flag'] === 'pagante'));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'path' => $path,
            'min_cluster' => $minCluster,
            'metrics' => [
                'files_scanned' => $filesScanned,
                'clusters' => count($clusters),
                'candidatos_pagantes' => count($paying),
                'loc_liquida_estimada' => array_sum(array_column($paying, 'loc_liquida_estimada')),
            ],
            'clusters' => array_slice($clusters, 0, self::TOP_CLUSTERS),
            'shingle_cross_file' => $shingleClusters,
            'claim_policy' => [
                'read_only' => true,
                'providers_invoked' => false,
                'deletes_files' => false,
                'refactor_authorization_allowed' => false,
            ],
            'note' => self::CALIBRATION_NOTE,
        ];
    }

    /** @return array<string,string> path => content */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return str_ends_with($path, '.php') ? [$path => (string) file_get_contents($path)] : [];
        }
        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $info) {
            if ($info->isFile() && $info->getExtension() === 'php') {
                $files[$info->getPathname()] = (string) file_get_contents($info->getPathname());
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * Extrai métodos nomeados com corpo normalizado (o MESMO algoritmo dos
     * finders): strings→S, dígitos→N, comentários fora, whitespace colapsado,
     * sha256 do resultado.
     *
     * @return list<array{name:string, hash:string, body_lines:int}>
     */
    private function methodShapes(string $content): array
    {
        $tokens = @token_get_all($content);
        $out = [];
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;
            }
            if ($j >= $n || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                continue; // closure/arrow-fn — census é de métodos nomeados
            }
            $name = $tokens[$j][1];

            // Anda até a '{' de abertura do corpo (métodos abstratos terminam em ';').
            $k = $j;
            $parens = 0;
            $hasBody = false;
            while ($k < $n) {
                $t = $tokens[$k];
                if ($t === '(') {
                    $parens++;
                } elseif ($t === ')') {
                    $parens--;
                } elseif ($t === '{' && $parens === 0) {
                    $hasBody = true;
                    break;
                } elseif ($t === ';' && $parens === 0) {
                    break;
                }
                $k++;
            }
            if (! $hasBody) {
                continue;
            }

            [$normalized, $raw] = $this->collectBody($tokens, $k, $n);
            if ($normalized === '') {
                continue;
            }
            $bodyLines = count(array_filter(
                array_map('trim', explode("\n", $raw)),
                static fn (string $l): bool => $l !== '' && $l !== '{' && $l !== '}',
            ));

            $out[] = ['name' => $name, 'hash' => hash('sha256', $normalized), 'body_lines' => $bodyLines];
            $i = $k;
        }

        return $out;
    }

    /**
     * Brace-matching a partir da '{' de abertura; devolve [corpo normalizado, corpo cru].
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return array{0:string,1:string}
     */
    private function collectBody(array $tokens, int $start, int $n): array
    {
        $depth = 0;
        $normalized = '';
        $raw = '';
        for ($k = $start; $k < $n; $k++) {
            $t = $tokens[$k];
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
            }

            $raw .= is_array($t) ? $t[1] : $t;
            if (is_array($t)) {
                $normalized .= match ($t[0]) {
                    T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE => 'S',
                    T_LNUMBER, T_DNUMBER => 'N',
                    T_COMMENT, T_DOC_COMMENT => '',
                    T_WHITESPACE => ' ',
                    default => $t[1],
                };
            } else {
                $normalized .= $t;
            }

            if ($depth === 0) {
                break;
            }
        }

        return [trim((string) preg_replace('/\s+/', ' ', $normalized)), $raw];
    }
}
