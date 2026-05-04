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
            'default_flow' => 'atlas dev para programacao diaria; atlas forge para tarefas medias/dificeis/extremas',
            'commands' => $this->commands(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas CLI</>', 'terminal principal do Atlas');
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
                    'command' => 'atlas start',
                    'description' => 'Briefing focado para comecar o dia: contexto + UMA proxima acao concreta (alias: atlas focus, atlas work).',
                ],
                [
                    'command' => 'atlas completion install',
                    'description' => 'Imprime as linhas para ativar autocomplete bash/zsh; tab completa comandos, providers, modos.',
                ],
                [
                    'command' => 'atlas ask "..."',
                    'description' => 'Conversa direta com streaming, memoria e sessao do Atlas.',
                ],
                [
                    'command' => 'atlas ask --image tela.png "..."',
                    'description' => 'Anexa imagem real ao pedido para screenshots, UI e bugs visuais.',
                ],
                [
                    'command' => 'atlas dev + "analise essa tela"',
                    'description' => 'No cockpit, detecta imagem copiada no clipboard quando o pedido menciona tela/screenshot/print.',
                ],
                [
                    'command' => 'atlas dev + Ctrl+V',
                    'description' => 'Fluxo produto para Print Screen: Ctrl+V cola imagem no composer, mostra [img:N] e anexa antes de enviar.',
                ],
                [
                    'command' => 'atlas dev',
                    'description' => 'Abre o Atlas Dev Cockpit interativo; cada mensagem usa Atlas Decide, policy efetiva e perfil de programacao.',
                ],
                [
                    'command' => 'atlas forge',
                    'description' => 'Modo maximo interativo para tarefas medias/dificeis: Open Brain obrigatorio, auto-test, repair loop e evidencias.',
                ],
                [
                    'command' => 'atlas dev "..." --ai=codex|claude --model=opus|spark|<model-id>',
                    'description' => 'Entrada one-shot ainda suportada; o uso normal recomendado e abrir atlas dev sem prompt.',
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
                    'command' => 'atlas bootstrap --provider-projection=status',
                    'description' => 'Diagnostica, revisa diff ou aplica CLAUDE.md/AGENTS.md provider-safe apenas quando a acao e confirmacao sao explicitas.',
                ],
                [
                    'command' => 'atlas bootstrap --operator-mode --operator-root=/Users/vitorepf',
                    'description' => 'Habilita /Users/vitorepf como raiz autorizada e faz ask/dev herdarem danger-full-access governado.',
                ],
                [
                    'command' => 'atlas skills trust',
                    'description' => 'Confia uma vez nas skills locais do repo atual; elas complementam o Atlas, sem substituir skills internas.',
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
                    'command' => 'atlas interrupt',
                    'description' => 'Cancela a execucao Atlas ativa neste workspace.',
                ],
                [
                    'command' => 'atlas continue',
                    'description' => 'Retoma o ultimo plano de dev nao finalizado neste workspace.',
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
                    'description' => 'Revisa, aceita e promove memory deltas tipados para o registry central.',
                ],
                [
                    'command' => 'atlas memory govern',
                    'description' => 'Rebaixa memorias com feedback ruim e detecta duplicatas/conflitos revisaveis.',
                ],
                [
                    'command' => 'atlas memory review-queue',
                    'description' => 'Mostra a fila unificada de revisao de privacidade, verbatim e relacoes abertas.',
                ],
                [
                    'command' => 'atlas memory relations',
                    'description' => 'Lista e resolve relacoes duplicate/conflict geradas pela governanca de memoria.',
                ],
                [
                    'command' => 'atlas memory privacy',
                    'description' => 'Aplica e revisa redaction, classe de privacidade e uso externo no registry central.',
                ],
                [
                    'command' => 'atlas memory verbatim',
                    'description' => 'Registra, revisa, libera/bloqueia e consulta trechos exatos com redaction e politica de privacidade.',
                ],
                [
                    'command' => 'atlas memory projection',
                    'description' => 'Gera, revisa diff, aplica com confirmacao, grava, adota, inspeciona e diagnostica CLAUDE.md/AGENTS.md curtos.',
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
                    'command' => 'atlas tools doctor --workspace=<repo>',
                    'description' => 'Lista o registry canonico do Super Tool Runtime, detecta instalacoes, registra skips auditaveis e mostra postura de risco/custo.',
                ],
                [
                    'command' => 'atlas tools authority --json',
                    'description' => 'Mostra matriz T0-T3, grupos de autoridade, primarias/complementares/fallbacks/executores e recomendacoes anti-buffet.',
                ],
                [
                    'command' => 'atlas tools authority-policies --json',
                    'description' => 'Mostra o contrato auditavel de severidade por grupo de autoridade: o que bloqueia, o que avisa e o motivo usado pelos gates.',
                ],
                [
                    'command' => 'atlas tools set-authority-policy semantic_sast --block-severity=critical --block-severity=high --warn-severity=medium --workspace=<repo>',
                    'description' => 'Configura override auditavel por workspace/global para thresholds de gate por authority group, sem alterar o default seguro do codigo.',
                ],
                [
                    'command' => 'atlas tools commands <tool> --workspace=<repo> --json',
                    'description' => 'Lista command recipes seguros do registry para a ferramenta, incluindo argv, dry-run default, tier, sandbox, privacidade e task type.',
                ],
                [
                    'command' => 'atlas tools run-recipe <tool> --recipe=version --workspace=<repo> --json',
                    'description' => 'Executa uma recipe declarada no registry, herdando argv e guardrails em vez de aceitar comando ad hoc.',
                ],
                [
                    'command' => 'atlas tools run <tool> --command=<argv> --sandbox-mode=worktree --privacy-level=standard --task-type=refactor --dry-run|--approved',
                    'description' => 'Executa ou planeja uma ferramenta pelo contrato generico de politica, executor seguro, normalizador e evidence store.',
                ],
                [
                    'command' => 'atlas tools run <tool> --tool-env=KEY=VALUE --output-limit=12000 --requires-provider-safe',
                    'description' => 'Passa env seguro, limita stdout/stderr persistido e exige output seguro para provider/modelo quando aplicavel.',
                ],
                [
                    'command' => 'atlas tools approve|revoke|policies <tool> --scope=workspace --ttl-hours=24',
                    'description' => 'Gerencia aprovacoes auditaveis para ferramentas de maior risco ou rede pelo Super Tool Runtime.',
                ],
                [
                    'command' => 'atlas tools waive-finding|revoke-finding-waiver --finding-id=<id> --reason=<motivo> --ttl-hours=24',
                    'description' => 'Gerencia waiver auditavel de findings sem apagar evidencia e com efeito controlado nos gates.',
                ],
                [
                    'command' => 'atlas tools evidence [tool] --workspace=<repo> --status=failed --surface=engineering_quality_scan',
                    'description' => 'Consulta evidencias filtradas por workspace, tool, status, surface, policy decision e contexto.',
                ],
                [
                    'command' => 'atlas tools evidence [tool] --recipe=version --recipe-category=diagnostic --recipe-blocking-capable=false --json',
                    'description' => 'Consulta evidencias de recipes especificas, separando diagnostico nao bloqueante de scans/gates reais.',
                ],
                [
                    'command' => 'atlas tools gate [tool] --workspace=<repo> --required-tool=<slug> --require-evidence',
                    'description' => 'Avalia evidencias normalizadas e retorna gate passed/warning/blocked com falhas bloqueantes auditaveis.',
                ],
                [
                    'command' => 'atlas tools release-gate --workspace=<repo>',
                    'description' => 'Avalia release gate Security/SBOM usando evidencias de engineering_quality_scan e release_gate, com waivers validos.',
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
                [
                    'command' => 'atlas benchmark --suite=<slug> --workspace=<repo> --tier=release --sandbox=docker --docker-service=app --docker-cache=auto --visual-e2e=auto --quality-scan=auto --harness-policy=auto --provider-runtime=docker --gate-profile=release',
                    'description' => 'Executa uma suite Atlas-Bench com sandbox/testes Docker, cache controlado, artifacts, visual/E2E automatico, quality/security scan local, provider runtime isolado opcional e release gates automatizados.',
                ],
                [
                    'command' => 'atlas benchmark --suite=<fair-suite> --workspace=<repo> --claude-only --model=opus --complete --auto-test --gate-profile=release --json',
                    'description' => 'Executa benchmark Fair Claude opt-in: Atlas harness + claude_cli + Claude Opus, sem fallback/decide/council, exigindo gates deterministicos e pass_without_human.',
                ],
                [
                    'command' => 'atlas benchmark cleanup --cache-retention-days=14 --artifact-retention-days=30',
                    'description' => 'Audita caches Docker e artifacts antigos do Harness; use --apply para apagar candidatos depois do dry-run.',
                ],
                [
                    'command' => 'atlas benchmark seed --from-recent-runs=10 --tier=smoke --refresh-manifest',
                    'description' => 'Cria a suite padrao, promove runs reais e atualiza o manifesto do corpus.',
                ],
                [
                    'command' => 'atlas benchmark calibrate --suite=<slug> --limit=200',
                    'description' => 'Calibra politica de rollout, saude do corpus e candidatos a quarentena a partir dos outcomes reais.',
                ],
                [
                    'command' => 'atlas engineering run --task-id=<id> --model-policy=best-quality --sandbox=worktree|docker --provider-runtime=host|docker|auto --quality-scan=auto',
                    'description' => 'Roda uma task pelo Engineering Harness Runner com contrato, selecao auditavel de provider/modelo, isolamento, runtime do provider, controles, patch, testes, quality/security scan opcional e score.',
                ],
                [
                    'command' => 'atlas project blueprint prepare|create|validate|freeze --project-id=<id>',
                    'description' => 'Opera blueprint de engenharia project-level com inventory, scenarios, phase plan, coverage validator, hash deterministico, versionamento e freeze governado.',
                ],
                [
                    'command' => 'atlas project tasks generate --project-id=<id> --from-blueprint=<version>',
                    'description' => 'Gera tasks tecnicas a partir de blueprint congelado com engineering_contract forte e preservacao de tasks humanas.',
                ],
                [
                    'command' => 'atlas qa --task-id=<id> --status=passed --step="..." --expected="..." --actual="..."',
                    'description' => 'Registra QA manual rica com passos, resultado esperado/real, screenshot/artifact, console/network, confidence e notas de risco.',
                ],
                [
                    'command' => 'atlas review --deep --task-id=<id>',
                    'description' => 'Registra review profundo com severity, confidence, category, evidence refs, recommendation e threshold P0/P1 bloqueante.',
                ],
                [
                    'command' => 'atlas db review|explain --task-id=<id> --workspace=<repo>',
                    'description' => 'Executa gate Postgres para rollback, migrations destrutivas, FK/indices, constraints, JSONB, timezone, query plan, locks, raw SQL e backfill.',
                ],
                [
                    'command' => 'atlas engineering replay <run-id> --attempt=1 --model=<model-id> --model-policy=balanced --workspace=<repo> --auto-test',
                    'description' => 'Reexecuta um run ou attempt especifico do Harness em modo replay controlado; quando reexecuta provider, herda ou sobrescreve provider/modelo preservando rastreabilidade contra a origem.',
                ],
                [
                    'command' => 'atlas engineering harnessability calibrate --limit=300',
                    'description' => 'Calibra thresholds de autonomia do Harness a partir de runs historicos, divida de qualidade e outcomes reais.',
                ],
                [
                    'command' => 'atlas engineering knowledge sync --prune',
                    'description' => 'Sincroniza os docs canonicos de engenharia para o registry Postgres usado pelo context pack, manutencao e revisao do Atlas.',
                ],
                [
                    'command' => 'atlas engineering knowledge index-code --prune',
                    'description' => 'Indexa modulos reais, simbolos, rotas, comandos, migrations, testes e links docs->codigo para a IA entender o Harness pelo codigo atual.',
                ],
                [
                    'command' => 'atlas engineering knowledge modules --docs-status=undocumented',
                    'description' => 'Mostra lacunas de documentacao tecnica por modulo de codigo para orientar manutencao e revisao do Atlas.',
                ],
                [
                    'command' => 'atlas engineering quality-scan --profile=standard --changed-only',
                    'description' => 'Roda scan profissional de qualidade/seguranca com ferramentas gratuitas ou locais detectadas, artifacts e findings normalizados.',
                ],
                [
                    'command' => 'atlas engineering api-contract --spec=docs/openapi.yaml --strict',
                    'description' => 'Valida contrato OpenAPI contra rotas Laravel, respostas documentadas e persiste findings por endpoint no Evidence Store.',
                ],
                [
                    'command' => 'atlas engineering security-scan --profile=release',
                    'description' => 'Roda apenas sensores locais de seguranca/vulnerabilidade pelo Super Tool Runtime: Gitleaks, Semgrep, OSV-Scanner, Trivy e Grype quando disponiveis.',
                ],
                [
                    'command' => 'atlas engineering sbom --profile=release',
                    'description' => 'Gera evidencia SBOM local via Syft, com metricas e artifact summary normalizados no Evidence Store.',
                ],
                [
                    'command' => 'atlas engineering visual-smoke --baseline=strict --screenshot-baseline=strict --screenshot-driver=auto --start-command="npm run dev -- --host 127.0.0.1 --port {port}"',
                    'description' => 'Executa smoke visual local gerenciado pelo Atlas e grava snapshots DOM, screenshots por rota e diff visual usando Playwright do workspace ou runtime configurado pelo Atlas.',
                ],
                [
                    'command' => 'atlas engineering visual-driver install',
                    'description' => 'Instala/atualiza o runtime Playwright gratuito e local gerenciado pelo Atlas para screenshots do visual smoke quando o repo alvo nao tem Playwright.',
                ],
                [
                    'command' => 'atlas engineering visual-baseline promote --apply',
                    'description' => 'Promove o ultimo manifest de visual smoke para baseline DOM e screenshot depois da revisao dos artifacts.',
                ],
                [
                    'command' => 'atlas engineering benchmark --suite=<slug>',
                    'description' => 'Alias explicito para Atlas-Bench quando quiser separar comandos de engenharia.',
                ],
            ],
        ];
    }
}
