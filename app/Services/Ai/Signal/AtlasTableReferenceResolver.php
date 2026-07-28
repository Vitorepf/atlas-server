<?php

declare(strict_types=1);

namespace App\Services\Ai\Signal;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Quem, no código, cita esta tabela.
 *
 * Sem isto, uma tabela vazia é uma lacuna sem endereço: o compilador de cadeias
 * marcava os 572 nós `not_muscle_ready` e o backlog inteiro valia zero, porque
 * `allowed_files_hint` só sai do achado e nunca é inventado.
 *
 * A busca é de CONTEÚDO, não de símbolo. O índice de código (844k linhas em
 * `atlas_engineering_code_symbols`) guarda nome de símbolo e caminho — ele sabe
 * que classe existe, não que arquivo menciona a string `ai_approval_requests`.
 * Para esta pergunta o índice é o instrumento errado, e `rg` é o certo: ele lê o
 * conteúdo agora, e qualquer um repete o comando à mão.
 *
 * Migration é referência REAL e alvo FALSO: ela define a forma, nunca escreve a
 * linha. Se contasse como pista, toda tabela vazia pareceria endereçada — cada
 * uma tem a sua — e o placar subiria sem que um só nó ficasse trabalhável. Ela
 * sai num balde próprio, para que "definida e citada por mais ninguém" seja um
 * número visível em vez de um verde barato.
 */
final class AtlasTableReferenceResolver
{
    public const SCHEMA = 'atlas.signal.table_reference_resolver.v1';

    /** @var list<string> */
    private const SEARCH_PATHS = ['app', 'config', 'routes', 'database'];

    private const MIGRATION_PREFIX = 'database/migrations/';

    private const MAX_HINTS_PER_TABLE = 10;

    public function __construct(private readonly ?string $repoRootOverride = null) {}

    /**
     * @param  list<string>  $tables
     * @return array{available:bool, reason:string|null, references:array<string,list<string>>, migration_only:list<string>, unreferenced:list<string>}
     */
    public function resolve(array $tables): array
    {
        $tables = array_values(array_unique(array_filter(
            array_map('strval', $tables),
            // Só nomes de identificador. Um padrão fora dessa forma viraria regex
            // arbitrária dentro do rg — e o resultado deixaria de ser verificável.
            static fn (string $t): bool => preg_match('/^[a-z0-9_]+$/i', $t) === 1,
        )));

        $empty = ['references' => [], 'migration_only' => [], 'unreferenced' => $tables];

        if ($tables === []) {
            return ['available' => true, 'reason' => null, 'references' => [], 'migration_only' => [], 'unreferenced' => []];
        }

        $output = $this->runRipgrep($tables);
        if ($output === null) {
            // Sem busca não há pista, e ausência de pista NUNCA vira pista vazia
            // silenciosa: o motivo viaja para quem for ler o placar.
            return ['available' => false, 'reason' => 'ripgrep_unavailable'] + $empty;
        }

        $hits = [];
        $migrationHits = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $split = strrpos($line, ':');
            if ($split === false) {
                continue;
            }
            $file = substr($line, 0, $split);
            $table = substr($line, $split + 1);
            if (! in_array($table, $tables, true)) {
                continue;
            }
            if (str_starts_with($file, self::MIGRATION_PREFIX)) {
                $migrationHits[$table][$file] = true;

                continue;
            }
            $hits[$table][$file] = true;
        }

        $references = [];
        $migrationOnly = [];
        $unreferenced = [];
        foreach ($tables as $table) {
            if (isset($hits[$table])) {
                $files = array_keys($hits[$table]);
                sort($files);
                $references[$table] = array_slice($files, 0, self::MAX_HINTS_PER_TABLE);

                continue;
            }
            if (isset($migrationHits[$table])) {
                $migrationOnly[] = $table;

                continue;
            }
            $unreferenced[] = $table;
        }

        return [
            'available' => true,
            'reason' => null,
            'references' => $references,
            'migration_only' => $migrationOnly,
            'unreferenced' => $unreferenced,
        ];
    }

    /**
     * Uma passada só para as 286 tabelas (~0,1s), em vez de 286 processos.
     *
     * @param  list<string>  $tables
     */
    private function runRipgrep(array $tables): ?string
    {
        // \b nas duas pontas: sem isso `jobs` casaria dentro de `failed_jobs` e
        // de meia dúzia de palavras, e a pista apontaria para o arquivo errado.
        $patterns = implode("\n", array_map(static fn (string $t): string => '\b'.preg_quote($t, '/').'\b', $tables));

        $root = $this->repoRootOverride ?? base_path();
        // rg sai com 2 se QUALQUER caminho não existir, e um único diretório
        // ausente derrubaria a busca inteira para um "sem pista" mentiroso.
        $paths = array_values(array_filter(self::SEARCH_PATHS, static fn (string $p): bool => is_dir($root.'/'.$p)));
        if ($paths === []) {
            return null;
        }

        try {
            $process = new Process(
                // --no-ignore: rg respeita .gitignore por padrão e devolve ZERO
                // silencioso em caminho ignorado — no-op que já custou caro neste
                // repo. Que arquivo cita esta tabela é fato do disco, e não pode
                // depender de configuração de git.
                ['rg', '-o', '--no-heading', '--with-filename', '--no-ignore', '-f', '-', ...$paths],
                $root,
                null,
                $patterns,
                120.0,
            );
            $process->run();
        } catch (Throwable) {
            return null;
        }

        // rg devolve 1 quando não achou nada — é resposta, não falha.
        return in_array($process->getExitCode(), [0, 1], true) ? $process->getOutput() : null;
    }
}
