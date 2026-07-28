<?php

declare(strict_types=1);

namespace App\Services\Ai\Signal;

use Illuminate\Console\Scheduling\Schedule;
use Throwable;

/**
 * As quatro invariantes de sinal, medidas — e as que não dão para medir, ditas.
 *
 * Este é o produtor que o adaptador de fee982f52 esperava. Duas das quatro
 * classes saem com contagem 0 e o motivo escrito, e isso é a resposta certa:
 * `gate_field_without_producer` e `producer_without_clock` dependem de saber
 * QUEM ESCREVE uma tabela e QUEM LÊ um campo, e isso é aresta de chamada. O
 * índice de código tem 844k símbolos e ZERO arestas — `atlas_engineering_code_
 * symbols` guarda nome e caminho, não quem chama quem. Uma heurística aqui
 * viraria backlog fabricado com cara de medição, que é pior que backlog
 * faltando.
 *
 * Read-only. Não escreve, não semeia, não enfileira.
 */
final class AtlasSignalInvariantScanner
{
    public const SCHEMA = 'atlas.signal.invariants.v1';

    private const NO_CALL_GRAPH = 'nao derivavel sem grafo de chamadas: atlas_engineering_code_symbols indexa simbolo e caminho, nao arestas de chamada — heuristica aqui seria achado fabricado';

    public function __construct(
        private readonly AtlasTableCensusService $census,
        private readonly AtlasTableReferenceResolver $references,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function scan(): array
    {
        $classes = [
            'table_without_owner' => $this->tablesWithoutOwner(),
            'orphan_cadence' => $this->orphanCadences(),
            'gate_field_without_producer' => [
                'derivable' => false,
                'reason' => self::NO_CALL_GRAPH,
                'findings' => [],
            ],
            'producer_without_clock' => [
                'derivable' => false,
                // Não basta "comando fora do schedule": a maioria dos ~600 comandos
                // é manual por projeto, e chamar todos de produtor sem relógio
                // inventaria a parte "produtor" do achado.
                'reason' => self::NO_CALL_GRAPH.'; o conjunto "comando ausente do schedule" e exato mas nao prova que o comando escreve tabela',
                'findings' => [],
            ],
        ];

        $findings = [];
        foreach ($classes as $class => $result) {
            foreach ($result['findings'] as $finding) {
                $findings[] = ['class' => $class] + $finding;
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'counts' => array_map(static fn (array $r): int => count($r['findings']), $classes),
            'derivable' => array_map(static fn (array $r): bool => (bool) $r['derivable'], $classes),
            'reasons' => array_filter(array_map(static fn (array $r): ?string => $r['reason'] ?? null, $classes)),
            'findings_total' => count($findings),
            'findings' => $findings,
        ];
    }

    /**
     * Tabela construída e nunca escrita, com os arquivos que a citam como alvo.
     *
     * @return array{derivable:bool, reason:string|null, findings:list<array<string,mixed>>}
     */
    private function tablesWithoutOwner(): array
    {
        $empty = (array) $this->census->census()['empty_tables'];
        $refs = $this->references->resolve($empty);

        $findings = [];
        foreach ($empty as $table) {
            $hint = $refs['references'][$table] ?? [];
            $findings[] = array_filter([
                'subject' => $table,
                'allowed_files_hint' => $hint !== [] ? $hint : null,
                'evidence' => 'tabela existe e tem 0 linhas',
            ], static fn (mixed $v): bool => $v !== null);
        }

        return [
            'derivable' => $refs['available'],
            'reason' => $refs['reason'],
            'findings' => $findings,
        ];
    }

    /**
     * Cadência que existe e NÃO PODE disparar: o `when()` dela rejeita agora.
     *
     * É o subconjunto exatamente provável de "cadência órfã" — vem do runtime,
     * não de leitura de código. A outra metade da definição ("cuja tabela de
     * saída não recebe escrita") exigiria o mapa comando→tabela, que é a mesma
     * aresta de chamada que não existe.
     *
     * O alvo é honesto e não inventado: a entrada mora em routes/console.php.
     *
     * @return array{derivable:bool, reason:string|null, findings:list<array<string,mixed>>}
     */
    private function orphanCadences(): array
    {
        try {
            $events = app(Schedule::class)->events();
        } catch (Throwable) {
            return ['derivable' => false, 'reason' => 'scheduler_unavailable', 'findings' => []];
        }

        $findings = [];
        foreach ($events as $event) {
            try {
                $passes = $event->filtersPass(app());
            } catch (Throwable) {
                // Filtro que explode não é filtro que reprova: chutar aqui
                // inventaria um achado a partir de um erro nosso.
                continue;
            }
            if ($passes !== false) {
                continue;
            }

            $commandLine = (string) ($event->command ?? '');
            $findings[] = [
                // O nome sozinho não identifica a ENTRADA: duas linhas de
                // `queue:work` em filas diferentes são duas cadências mortas, e
                // um subject compartilhado faria o adaptador colapsar as duas num
                // achado só — perdendo uma sem que ninguém visse.
                'subject' => $this->commandName($commandLine).'@'.substr(hash('sha256', $commandLine), 0, 8),
                'command' => $this->commandName($commandLine),
                'allowed_files_hint' => ['routes/console.php'],
                'evidence' => 'entrada agendada cujo when() rejeita agora — a cadencia existe e nunca dispara',
                'expression' => (string) $event->expression,
            ];
        }

        return ['derivable' => true, 'reason' => null, 'findings' => $findings];
    }

    /** O nome do comando dentro da linha de execução que o scheduler montou. */
    private function commandName(string $command): string
    {
        if (preg_match("/artisan'?\s+'?([a-z0-9:_-]+)/i", $command, $m) === 1) {
            return $m[1];
        }

        return trim($command) !== '' ? $command : 'unknown';
    }
}
