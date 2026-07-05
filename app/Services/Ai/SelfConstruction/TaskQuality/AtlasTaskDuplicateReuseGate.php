<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * F0 da limpeza 05/07 — o freio no PRODUTOR de duplicação. A medição (jscpd, 05/07/2026) provou
 * que 38% das linhas clonadas do núcleo nascem na esteira SelfConstruction: workers RE-IMPLEMENTAM
 * símbolos que já existem em vez de reusar (caso real do dia: AtlasMaestroPacketClassifierTest
 * duplicado byte-idêntico quebrou o load da suíte inteira com "Cannot redeclare class").
 *
 * Duas verificações, ambas determinísticas (zero LLM):
 *   A. duplicate_class_name (BLOCKER) — a entrega declara class/interface/trait/enum cujo nome já
 *      é declarado por OUTRO arquivo do repo (app/ + tests/). Um segundo símbolo homônimo é quase
 *      sempre re-implementação; quando for homonímia legítima de namespace, o operador rebaixa o
 *      modo ou renomeia. Precisão medida: só 14 basenames duplicados em 7.3k arquivos.
 *   B. clone_block (OBSERVAÇÃO, nunca bloqueia) — um arquivo entregue compartilha um run de >= 30
 *      linhas normalizadas idênticas consecutivas com um irmão do MESMO diretório (o padrão
 *      template-copiado dos workers). Fuzzy demais para blocker; o envelope/receipt carrega o fato.
 *
 * Fail-open por contrato: qualquer Throwable interno vira gate neutro (passed=true) — o chamador
 * já trata exceção da mesma forma que os gates vizinhos (evidence contract / refactor proof).
 */
final class AtlasTaskDuplicateReuseGate
{
    public const SCHEMA = 'atlas.task.duplicate_reuse_gate.v1';

    private const CLONE_BLOCK_MIN_LINES = 30; // ponytail: teto fixo; parametrizar só se ruído real aparecer

    private const DECLARATION_PATTERN = '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/mi';

    /**
     * @param  list<string>  $changedFiles  paths repo-relativos (ex.: app/Services/.../Foo.php)
     * @return array{schema:string, passed:bool, blockers:list<string>, observations:list<string>, examined:int}
     */
    public function evaluate(array $changedFiles, ?string $repoRoot = null): array
    {
        $repoRoot = rtrim($repoRoot ?? base_path(), '/');
        $blockers = [];
        $observations = [];
        $examined = 0;

        $phpChanged = array_values(array_filter(
            array_map('strval', $changedFiles),
            static fn (string $f): bool => str_ends_with($f, '.php'),
        ));
        if ($phpChanged === []) {
            return ['schema' => self::SCHEMA, 'passed' => true, 'blockers' => [], 'observations' => [], 'examined' => 0];
        }

        $index = $this->basenameIndex($repoRoot);
        $changedSet = array_flip($phpChanged);

        foreach ($phpChanged as $file) {
            $abs = $repoRoot.'/'.$file;
            if (! is_file($abs)) {
                continue; // arquivo deletado pela entrega — nada a deduplicar
            }
            $examined++;
            $contents = (string) file_get_contents($abs);

            // A — declarações homônimas em outro arquivo do repo.
            if (preg_match_all(self::DECLARATION_PATTERN, $contents, $m) > 0) {
                foreach (array_unique($m[1]) as $symbol) {
                    foreach ($index[$symbol.'.php'] ?? [] as $existing) {
                        if ($existing === $file || isset($changedSet[$existing])) {
                            continue; // o próprio arquivo, ou outro arquivo DA MESMA entrega (renomeio em curso)
                        }
                        if ($this->declaresSymbol($repoRoot.'/'.$existing, $symbol)) {
                            $blockers[] = 'duplicate_class_name:'.$symbol.':'.$existing;
                        }
                    }
                }
            }

            // B — bloco clonado de um irmão do mesmo diretório (observação).
            $sibling = $this->longestSiblingCloneRun($abs, $contents);
            if ($sibling !== null) {
                $observations[] = 'clone_block:'.$file.'~'.$sibling['file'].':'.$sibling['lines'].'_lines';
            }
        }

        return [
            'schema' => self::SCHEMA,
            'passed' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'observations' => $observations,
            'examined' => $examined,
        ];
    }

    /**
     * Índice basename.php -> paths repo-relativos sob app/ e tests/. Varredura única (~7k arquivos,
     * dezenas de ms) por avaliação; a esteira reporta uma task por vez, sem necessidade de cache.
     *
     * @return array<string, list<string>>
     */
    private function basenameIndex(string $repoRoot): array
    {
        $index = [];
        foreach (['app', 'tests'] as $top) {
            $base = $repoRoot.'/'.$top;
            if (! is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $f) {
                if ($f->getExtension() === 'php') {
                    $index[$f->getBasename()][] = $top.substr($f->getPathname(), strlen($base));
                }
            }
        }

        return $index;
    }

    private function declaresSymbol(string $absPath, string $symbol): bool
    {
        if (! is_file($absPath)) {
            return false;
        }
        $contents = (string) file_get_contents($absPath);

        return preg_match(
            '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+'.preg_quote($symbol, '/').'\b/mi',
            $contents,
        ) === 1;
    }

    /**
     * Maior run de linhas normalizadas idênticas consecutivas contra cada irmão .php do mesmo
     * diretório. Normalização: trim, descarta vazias e linhas só-comentário — o que sobra é
     * estrutura de código real.
     *
     * @return array{file:string, lines:int}|null
     */
    private function longestSiblingCloneRun(string $absPath, string $contents): ?array
    {
        $mine = $this->normalizedLines($contents);
        if (count($mine) < self::CLONE_BLOCK_MIN_LINES) {
            return null;
        }

        $best = null;
        foreach (glob(dirname($absPath).'/*.php') ?: [] as $sibling) {
            if ($sibling === $absPath) {
                continue;
            }
            $theirs = $this->normalizedLines((string) file_get_contents($sibling));
            $run = $this->longestCommonRun($mine, $theirs);
            if ($run >= self::CLONE_BLOCK_MIN_LINES && $run > ($best['lines'] ?? 0)) {
                $best = ['file' => basename($sibling), 'lines' => $run];
            }
        }

        return $best;
    }

    /** @return list<string> */
    private function normalizedLines(string $contents): array
    {
        $out = [];
        foreach (explode("\n", $contents) as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '#')) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    /**
     * Maior sequência comum CONSECUTIVA entre duas listas de linhas — DP por hash de linha,
     * O(n) memória via mapa linha->posições do lado menor.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function longestCommonRun(array $a, array $b): int
    {
        if ($a === [] || $b === []) {
            return 0;
        }
        $positions = [];
        foreach ($b as $j => $line) {
            $positions[$line][] = $j;
        }
        $best = 0;
        $prev = [];
        foreach ($a as $line) {
            $curr = [];
            foreach ($positions[$line] ?? [] as $j) {
                $curr[$j] = ($prev[$j - 1] ?? 0) + 1;
                if ($curr[$j] > $best) {
                    $best = $curr[$j];
                }
            }
            $prev = $curr;
        }

        return $best;
    }
}
