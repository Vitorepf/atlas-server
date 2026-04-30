<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AtlasCliHelpCommand extends Command
{
    protected $signature = 'atlas:cli:help {--json : Print machine-readable JSON}';

    protected $description = 'Show the Atlas CLI product command map.';

    public function handle(): int
    {
        $payload = [
            'product' => 'Atlas CLI',
            'default_flow' => 'atlas ask "sua pergunta" ou atlas dev "sua tarefa"',
            'commands' => $this->commands(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas CLI</>', 'terminal principal do Atlas AI');
        $this->line('Use o Atlas como entrada unica para conversa, desenvolvimento, revisao, memoria de sessao e qualidade.');

        foreach ($payload['commands'] as $section => $commands) {
            $this->newLine();
            $this->line('<fg=bright-blue;options=bold>'.str_replace('_', ' ', (string) $section).'</>');
            $this->table(
                ['comando', 'uso'],
                collect($commands)->map(fn (array $command): array => [
                    $command['command'],
                    $command['description'],
                ])->all(),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<int, array{command:string, description:string}>>
     */
    private function commands(): array
    {
        return [
            'fluxo_principal' => [
                [
                    'command' => 'atlas ask "..."',
                    'description' => 'Conversa direta com streaming, memoria e sessao do Atlas.',
                ],
                [
                    'command' => 'atlas dev "..."',
                    'description' => 'Workflow de desenvolvimento com preflight, plano, provider strategy e quality gate.',
                ],
                [
                    'command' => 'atlas debug "..."',
                    'description' => 'Investigacao tecnica sem editar por padrao; isola causa e proxima correcao minima.',
                ],
                [
                    'command' => 'atlas fix "..."',
                    'description' => 'Repair loop de desenvolvimento para bug, teste falho ou quality gate.',
                ],
                [
                    'command' => 'atlas plan "..."',
                    'description' => 'Planejamento tecnico sem editar codigo por padrao.',
                ],
                [
                    'command' => 'atlas review "..."',
                    'description' => 'Revisao critica de codigo, arquitetura ou decisao.',
                ],
                [
                    'command' => 'atlas compare "..."',
                    'description' => 'Dual-review explicito usando Claude e Codex como motores internos.',
                ],
                [
                    'command' => 'atlas research "..."',
                    'description' => 'Pesquisa no contexto disponivel do Atlas, citando apenas fontes recuperadas.',
                ],
            ],
            'operacao' => [
                [
                    'command' => 'atlas status',
                    'description' => 'Dashboard rapido do workspace, sessao, providers e runtime.',
                ],
                [
                    'command' => 'atlas tui',
                    'description' => 'TUI navegavel com paineis de conversa, plano, diff, testes, permissoes, memoria e traces.',
                ],
                [
                    'command' => 'atlas bootstrap',
                    'description' => 'Comando recomendado unico para configurar, instalar e validar o Atlas CLI.',
                ],
                [
                    'command' => 'atlas version',
                    'description' => 'Mostra versao, branch, commit e raiz do Atlas CLI.',
                ],
                [
                    'command' => 'atlas dogfood report',
                    'description' => 'Mostra cobertura de uso real, cenarios obrigatorios e falhas de produto.',
                ],
                [
                    'command' => 'atlas dogfood run',
                    'description' => 'Executa smoke dogfood seguro dos cenarios principais e registra evidencias.',
                ],
                [
                    'command' => 'atlas release --version=v2.0.0',
                    'description' => 'Gate de release: docs, CI, dogfood, readiness final e tag opcional.',
                ],
                [
                    'command' => 'atlas update --dry-run',
                    'description' => 'Planeja update local com lista de commits e migrations antes de aplicar.',
                ],
                [
                    'command' => 'atlas schedule list',
                    'description' => 'Lista tarefas agendadas que o Atlas executa sozinho pelo scheduler local.',
                ],
                [
                    'command' => 'atlas schedule add "titulo" --schedule="every 2h" --prompt="..."',
                    'description' => 'Cria uma tarefa recorrente ou pontual com skills, workspace e output auditavel.',
                ],
            ],
            'diagnostico_avancado' => [
                [
                    'command' => 'atlas doctor --strict',
                    'description' => 'Validacao terminal chamada automaticamente ao fim do bootstrap.',
                ],
                [
                    'command' => 'atlas final --strict',
                    'description' => 'Verifica B0-B8 e readiness final do produto Atlas CLI.',
                ],
                [
                    'command' => 'atlas dogfood record --scenario=dev_task --result=passed',
                    'description' => 'Registra uso real para o gate de produto final.',
                ],
                [
                    'command' => 'atlas setup',
                    'description' => 'Comando baixo nivel usado pelo bootstrap para diagnosticar binarios.',
                ],
                [
                    'command' => 'atlas install',
                    'description' => 'Comando baixo nivel usado pelo bootstrap para instalar o launcher.',
                ],
                [
                    'command' => 'atlas providers --refresh',
                    'description' => 'Comando baixo nivel usado pelo bootstrap para atualizar health de providers.',
                ],
            ],
            'sessao_memoria' => [
                [
                    'command' => 'atlas state',
                    'description' => 'Mostra objetivo, fase, notas e proximos passos da sessao terminal.',
                ],
                [
                    'command' => 'atlas steer "..."',
                    'description' => 'Registra um nudge [STEER] para a proxima chamada de provider da thread ativa.',
                ],
                [
                    'command' => 'atlas compact',
                    'description' => 'Compacta a sessao longa em estado reutilizavel.',
                ],
                [
                    'command' => 'atlas search "..."',
                    'description' => 'Busca conversas anteriores por palavras, frase exata, OR, NOT e prefixo*.',
                ],
                [
                    'command' => 'atlas handoff --to=codex_cli',
                    'description' => 'Registra troca de provider mantendo continuidade.',
                ],
                [
                    'command' => 'atlas memory review',
                    'description' => 'Revisa memory deltas tipados antes de entrarem no contexto do Atlas.',
                ],
                [
                    'command' => 'atlas skills list',
                    'description' => 'Lista bundles agentskills.io disponiveis com tier, trust e warnings.',
                ],
                [
                    'command' => 'atlas skills show dev-quality-gate',
                    'description' => 'Mostra conteudo, path, hash e recursos de uma skill.',
                ],
                [
                    'command' => 'atlas skills doctor',
                    'description' => 'Valida descoberta, trust gate, quarentena e warnings das skills.',
                ],
            ],
            'runtime_qualidade' => [
                [
                    'command' => 'atlas runtime <tool>',
                    'description' => 'Executa ferramentas nativas com permissao, checkpoint e auditoria.',
                ],
                [
                    'command' => 'atlas checkpoint',
                    'description' => 'Lista, inspeciona e restaura checkpoints de edicao.',
                ],
                [
                    'command' => 'atlas quality --run-tests --yes',
                    'description' => 'Gera pacote de conclusao e valida testes.',
                ],
                [
                    'command' => 'atlas trace last',
                    'description' => 'Mostra trace recente com eventos de runtime e plano de execucao.',
                ],
                [
                    'command' => 'atlas permissions status',
                    'description' => 'Lista aprovacoes temporarias de runtime por workspace, path e tool.',
                ],
                [
                    'command' => 'atlas test',
                    'description' => 'Atalho para quality gate com testes.',
                ],
            ],
        ];
    }
}
