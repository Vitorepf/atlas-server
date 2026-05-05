> Cleanup status: superseded_source_material.
> Canonical replacement: docs/atlas-cli-final-product.md; docs/atlas-cli-5x-claude-code-plan.md; docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md; docs/engineering-knowledge-base/atlas-ai-governed-backlog.md.
> Cleanup note: CLI roadmap source material. Product authority now lives in CLI product/5x docs and architecture deltas must enter the governed backlog before execution.

# ATLAS CLI - ROADMAP DE PRODUTO FINAL EM 7 PONTOS

**Documentacao especifica para levar o Atlas CLI da versao V1 utilizavel ate a versao final verificavel**

---

| | |
|---|---|
| **Operador** | Vitor Emanuel |
| **Sistema** | Atlas |
| **Documento** | Atlas CLI - Roadmap de Produto Final em 7 Pontos |
| **Versao** | 1.0 |
| **Data** | 30 de abril de 2026 |
| **Status** | Plano de produto e implementacao |
| **Autoridade superior** | Atlas_AI_Documentacao_Final.md, Atlas_AI_Harness_v1.md, Atlas_CLI_TUI_Estado_da_Arte_Plano_Implementacao.md |
| **ADR de nomenclatura** | Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md |
| **Anexo tecnico** | Atlas_CLI_Packets_v1.md |
| **Decisao central** | O Atlas CLI deve substituir o uso direto de Claude Code, Codex CLI e futuros providers no Mac |

---

## 0. Decisao Executiva

O Atlas CLI nao deve ser apenas um atalho para Claude ou Codex.

O Atlas CLI deve ser o **produto terminal principal do Atlas AI**:

- entende o workspace;
- preserva sessao longa;
- roteia o melhor provider;
- executa ferramentas pelo runtime proprio;
- controla permissao;
- registra traces;
- gera checkpoints;
- roda testes;
- compacta contexto;
- aprende com o trabalho;
- entrega resposta clara sem despejar codigo desnecessario.

Claude, Codex, GPT e qualquer modelo futuro sao motores internos. O operador deve abrir o terminal e usar:

```bash
atlas
atlas dev "..."
atlas review "..."
atlas test
atlas status
atlas tui
atlas bootstrap
```

O comando `atlas bootstrap` permanece o unico fluxo recomendado para configuracao, instalacao e validacao do CLI.

Comandos citados neste documento que nao existem hoje em `bin/atlas` aparecem como `[Vx-spec]`. Eles sao especificacao futura, nao produto pronto.

---

## 1. Produto Final Desejado

O Atlas CLI esta completo quando Vitor consegue fazer trabalho pesado de desenvolvimento por horas sem abrir Claude Code ou Codex CLI diretamente.

### Experiencia final esperada

1. Vitor abre o terminal dentro de qualquer repo.
2. Roda `atlas dev "implemente X"`.
3. Atlas detecta stack, estado Git, arquivos relevantes, testes e riscos.
4. Atlas escolhe provider e skill.
5. Atlas cria plano, executa etapas e pede permissao apenas quando ha risco real.
6. Atlas edita arquivos com checkpoint automatico.
7. Atlas roda testes relevantes.
8. Atlas corrige falhas em loop controlado.
9. Atlas revisa o proprio diff.
10. Atlas entrega resumo claro, arquivos alterados, testes, riscos e proximos passos.
11. Atlas registra trace e memory delta.
12. Em outro dia, Atlas retoma a sessao com contexto suficiente.

### Regra de ouro

O Atlas CLI final deve tornar o uso direto de Claude Code/Codex CLI uma excecao, nao o fluxo padrao.

---

## 2. Mapa Das Versoes

| Versao | Nome | Objetivo | Estado esperado |
|---|---|---|---|
| V1 | CLI confiavel | Instalar, diagnosticar, conversar, revisar e iniciar dev workflow com seguranca | Utilizavel no dia a dia com supervisao |
| V1.5 | Dev workflow operacional | `atlas dev` executa plano, runtime, diff, testes e completion packet | Substitui provider direto em tarefas pequenas e medias |
| V2 | TUI e runtime profissional | Interface terminal visual, permissao por sessao, traces ricos e checkpoints claros | Uso confortavel por horas |
| V2.5 | Inteligencia acumulada | Router aprende, memory delta vira rotina, skill/router melhoram por evidencia | Atlas fica melhor com uso real |
| V3 | Estado da arte | Loop autonomo robusto, provider orchestration, qualidade mensurada e rollback confiavel | Substitui Claude Code/Codex CLI como padrao |
| Final | Auge do produto | Atlas e superficie unica de dev no Mac, com memoria, seguranca, verificacao e aprendizado superiores | Produto terminal completo |

---

## 3. Os 7 Pontos Do Produto Final

1. `atlas dev` end-to-end mais autonomo.
2. TUI real interativa, com stack final Go + Bubble Tea conforme ADR.
3. Tool events e traces mais ricos.
4. Permission engine por sessao.
5. Memory delta pos-trabalho.
6. Provider router mais inteligente.
7. Instalacao, release, upgrade e rollback como produto.

Cada ponto abaixo tem objetivo, comportamento esperado, implementacao por versao e criterio de pronto. Os contratos JSON completos foram extraidos para `Atlas_CLI_Packets_v1.md`.

---

## 3.1 Mapping: 8 Fases Do Plano CLI/TUI ↔ 7 Pontos Finais

Os 8 blocos do plano CLI/TUI sao fases de execucao. Os 7 pontos deste documento sao vetores transversais de produto.

| Fase do plano CLI/TUI | Vetores principais |
|---|---|
| 1. Dashboard terminal | TUI, provider router, bootstrap/produto instalavel |
| 2. Sessao longa profissional | memory delta, traces, provider handoff |
| 3. Dev workflow nativo | `atlas dev`, tool events, permission session, completion packet |
| 4. TUI interativa real | TUI, permission session, traces, checkpoints |
| 5. Permission engine avancada | permission session, tool events, produto seguro |
| 6. Quality loop robusto | `atlas dev`, traces, testes, memory delta |
| 7. Model router e provider strategy | provider router, traces, quality history |
| 8. Memoria e aprendizado pos-trabalho | memory delta, router learning, continuidade |

Regra: os 7 pontos nao substituem as 8 fases; eles medem se cada fase esta contribuindo para o produto final.

---

## 3.2 Mapa Para Codigo Atual

| Area | Codigo atual | Lacuna principal |
|---|---|---|
| Bootstrap/doctor | `atlas-server/app/Console/Commands/AtlasCliBootstrapCommand.php`, `AtlasCliDoctorService.php` | upgrade/rollback ainda nao existem |
| Dev workflow | `atlas-server/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php`, `AtlasCliDevCommand.php` | falta executor por etapas end-to-end |
| Dashboard/TUI V1 | `AtlasCliDashboardService.php`, `AtlasCliDashboardCommand.php` | ainda e dashboard console, nao TUI Bubble Tea |
| Runtime/tools | `atlas-server/app/Services/Ai/Runtime/AiToolRuntime.php` | falta persistencia rica de tool events |
| Permissoes | `AiPermissionEngine.php`, `AiToolPermissionEngine.php` | falta permission session persistente |
| Sessao/handoff | `AtlasCliSessionService.php`, `AiCompactionService.php`, `AiProviderHandoffService.php` | falta integracao profunda ao `atlas dev` |
| Provider strategy | `AtlasCliProviderStrategyService.php`, `AiProviderHealthService.php` | falta router por metricas historicas |

Este roadmap nao parte do zero; ele organiza a evolucao sobre essas pecas existentes.

---

## 4. Ponto 1 - `atlas dev` End-To-End Mais Autonomo

### Objetivo

Transformar `atlas dev` no fluxo principal de desenvolvimento pesado.

Hoje o CLI ja tem fundacao de preflight, quality, runtime e provider strategy. O produto final precisa ir alem: o Atlas deve conduzir a tarefa do pedido inicial ate a validacao final.

### Comportamento final

```bash
atlas dev "implemente streaming token a token no endpoint X"
```

O Atlas deve:

1. identificar repo, stack, branch, dirty files e testes;
2. criar plano tecnico curto;
3. selecionar provider e skill;
4. abrir checkpoint antes de escrita;
5. executar edicoes via runtime proprio;
6. rodar testes relevantes;
7. corrigir falhas;
8. fazer review do diff;
9. compactar contexto da sessao;
10. entregar completion packet.

### Implementacao por versao

#### V1

- Consolidar `AtlasCliDevWorkflowService`.
- `atlas dev` gera preflight completo:
  - workspace;
  - branch;
  - dirty files;
  - stack;
  - testes detectados;
  - provider recomendado;
  - permissao necessaria;
  - plano inicial.
- `atlas dev --plan-only` deve ser sempre read-only.
- `atlas dev --json` deve emitir pacote parseavel.
- `atlas dev` deve explicar quando nao pode executar.

#### V1.5

- Criar `DevExecutionPlan`.
- Criar executor por etapas:
  - inspect;
  - plan;
  - edit;
  - test;
  - repair;
  - review;
  - finish.
- Integrar runtime tools:
  - `workspace.profile`;
  - `search.rg`;
  - `file.read`;
  - `file.patch`;
  - `test.run`;
  - `git.diff`;
  - `checkpoint.restore`.
- Criar limite de loops:
  - max 3 ciclos de correcao por padrao;
  - se falhar, entregar causa e proximo passo.

Tradeoff: 3 ciclos e limite inicial porque reduz looping cego e ainda permite corrigir falhas simples de teste. Se dados reais mostrarem que 2 ou 4 ciclos performam melhor, o limite deve mudar por evidencia.

#### V2

- `atlas dev` usa TUI para acompanhar:
  - plano;
  - etapa atual;
  - arquivos tocados;
  - testes;
  - diff;
  - permissoes pendentes.
- Suporte a pausa/retomada:
  - `[V2-spec] atlas dev --resume`;
  - `/pause`;
  - `/continue`;
  - `/handoff`.
- Review automatico com provider diferente quando a tarefa for critica.

#### V2.5

- `atlas dev` aprende com historico:
  - quais testes costumam validar cada area;
  - arquivos relacionados recorrentes;
  - provider mais forte por tipo de tarefa;
  - erros recorrentes do projeto.
- Memory delta automatico ao fim de sessoes importantes.

#### V3 / Final

- Dev loop autonomo controlado:
  - Atlas escolhe estrategia;
  - executa com runtime proprio;
  - troca provider se necessario;
  - corrige regressao;
  - gera PR-ready summary;
  - registra trace completo.
- Modo final:

```text
[V3-spec] atlas dev "..." --complete
```

Esse modo so termina quando:

- diff esta coerente;
- testes relevantes rodaram ou justificativa objetiva existe;
- riscos foram declarados;
- completion packet foi criado;
- memory delta foi avaliado.

### Contrato tecnico

Contrato completo: `dev_execution` em `Atlas_CLI_Packets_v1.md`.

### Criterio de pronto

- `atlas dev` consegue finalizar uma alteracao pequena sem abrir Claude/Codex direto.
- Falhas sao explicadas com causa e proximo passo.
- Toda escrita cria checkpoint.
- Toda finalizacao gera completion packet.

---

## 5. Ponto 2 - TUI Real Interativa

### Objetivo

Criar uma interface terminal visual que torne o uso prolongado mais claro que chat puro.

### Comportamento final

```bash
atlas tui
```

Paineis esperados:

- conversa;
- plano;
- etapa atual;
- arquivos alterados;
- diff;
- testes;
- providers;
- permissao;
- checkpoints;
- memoria/contexto;
- logs e traces.

### Implementacao por versao

#### V1

- `atlas status` rapido e confiavel.
- `atlas tui` como dashboard terminal inicial.
- `atlas tui --json` para automacao.
- `atlas tui --watch=N` para refresh.

#### V1.5

- Criar layout TUI por secoes:
  - workspace;
  - sessao;
  - providers;
  - runtime;
  - quality;
  - proximas acoes.
- Adicionar atalhos basicos:
  - `r` refresh;
  - `s` state;
  - `q` sair.

#### V2

- TUI interativa real:
  - selecionar thread;
  - abrir diff;
  - abrir testes;
  - aprovar/recusar permissao;
  - restaurar checkpoint.
- Paineis alternaveis:
  - `1` conversa;
  - `2` plano;
  - `3` diff;
  - `4` testes;
  - `5` permissao;
  - `6` memoria;
  - `7` traces.

#### V2.5

- TUI acompanha `atlas dev` em tempo real.
- Eventos de streaming aparecem na UI.
- Tool events aparecem como timeline.
- Warnings de risco aparecem com linguagem simples.

#### V3 / Final

- TUI vira painel operacional completo:
  - trabalho por horas;
  - retomada de sessao;
  - handoff de provider;
  - diff review;
  - approve gates;
  - rollback;
  - memory delta review.

### Criterio de pronto

- Vitor consegue entender o estado da sessao em menos de 10 segundos.
- Nenhuma permissao relevante fica escondida.
- Diff, testes e checkpoints ficam acessiveis sem sair do Atlas.

---

## 6. Ponto 3 - Tool Events E Traces Mais Ricos

### Objetivo

Fazer toda acao importante do Atlas deixar rastro auditavel.

### Comportamento final

Cada leitura, escrita, patch, shell, teste, permissao, provider call e checkpoint deve gerar evento.

### Implementacao por versao

#### V1

- Padronizar `ToolEvent`.
- Registrar eventos principais no trace:
  - tool;
  - input resumido;
  - risco;
  - permissao;
  - status;
  - duracao;
  - erro.

#### V1.5

- Persistir tool events em tabela propria.
- Relacionar eventos a:
  - thread;
  - session;
  - job;
  - trace;
  - provider;
  - workspace.
- Criar comandos:
  - `[V1.5-spec] atlas trace last`;
  - `[V1.5-spec] atlas trace show <id>`.

#### V2

- TUI mostra timeline de eventos.
- Eventos de escrita exibem diff hash e checkpoint.
- Eventos de shell exibem stdout/stderr resumido.
- Eventos de teste exibem resultado e arquivos relacionados.

#### V2.5

- Traces viram fonte para router e aprendizado:
  - provider que falhou;
  - comando que quebrou;
  - teste que validou;
  - padrao de erro recorrente.

#### V3 / Final

- Todo trabalho do Atlas e reproduzivel por trace:
  - plano;
  - contexto usado;
  - ferramentas;
  - decisoes;
  - permissao;
  - resultado;
  - memory delta.

### Contrato tecnico

Contrato completo: `tool_event` em `Atlas_CLI_Packets_v1.md`.

### Criterio de pronto

- Toda edicao tem trace.
- Todo shell mutavel tem trace.
- Todo teste tem trace.
- Toda permissao tem justificativa.

---

## 7. Ponto 4 - Permission Engine Por Sessao

### Objetivo

Permitir autonomia sem perder controle.

O Atlas deve ser mais seguro que usar provider direto, porque toda acao passa por politica propria.

### Modos de permissao

| Modo | Pode fazer | Exige aprovacao |
|---|---|---|
| `read` | ler arquivos, buscar, perfilar workspace | nao |
| `write` | aplicar patch, criar arquivos, rodar testes | sim para primeira escrita ou mudanca grande |
| `danger` | comandos destrutivos, rede sensivel, alteracoes amplas | sempre |

### Implementacao por versao

#### V1

- Validar roots permitidos.
- Bloquear path fora de workspace.
- Classificar tools por risco.
- Bloquear danger por default.
- Exigir aprovacao para escrita.

#### V1.5

- Criar `PermissionSession`.
- Permitir aprovacoes temporarias:
  - por tool;
  - por path;
  - por duracao;
  - por objetivo.
- Criar comandos:
  - `[V1.5-spec] atlas permissions status`;
  - `[V1.5-spec] atlas permissions approve write --path app/`;
  - `[V1.5-spec] atlas permissions revoke all`.

#### V2

- TUI mostra fila de permissoes.
- Permissao explica risco em uma frase.
- Large patch exige resumo antes.
- Shell mutavel exige classificacao.

#### V2.5

- Politicas por workspace:
  - repos confiaveis;
  - repos sensiveis;
  - comandos sempre bloqueados;
  - comandos sempre permitidos em read-only.
- Detecao de segredos antes de enviar contexto ou aplicar patch.

#### V3 / Final

- Permission engine adaptativa:
  - aprende padroes seguros por repo;
  - reduz friccao para acoes repetidas seguras;
  - mantem bloqueio duro para perigo real.

### Contrato tecnico

Contrato completo: `permission_session` em `Atlas_CLI_Packets_v1.md`.

### Criterio de pronto

- Escrita nunca acontece sem politica valida.
- Danger nunca roda por acidente.
- O operador entende o que esta aprovando.
- Aprovacoes podem ser revogadas.

---

## 8. Ponto 5 - Memory Delta Pos-Trabalho

### Objetivo

Fazer o Atlas melhorar depois de cada sessao importante.

O Atlas nao deve apenas resolver uma tarefa. Ele deve capturar o que aprendeu sobre:

- projeto;
- arquitetura;
- decisoes;
- preferencias de Vitor;
- erros recorrentes;
- comandos uteis;
- padroes de qualidade.

### Implementacao por versao

#### V1

- Criar `MemoryDelta` no completion packet.
- Gerar candidatos simples:
  - decisao tomada;
  - arquivo importante;
  - teste relevante;
  - risco observado.

#### V1.5

- Criar fila de revisao:
  - `[V1.5-spec] atlas memory review`;
  - `[V1.5-spec] atlas memory accept <id>`;
  - `[V1.5-spec] atlas memory reject <id>`.

- Memory delta precisa ter:
  - tipo;
  - evidencia;
  - escopo;
  - validade temporal;
  - confianca;
  - quando usar;
  - quando nao usar.

#### V2

- TUI mostra memory deltas pendentes.
- Atlas pergunta de forma curta se deve salvar memoria relevante.
- Memory delta conecta com AtlasVault.

#### V2.5

- Memory delta alimenta:
  - context pack;
  - provider router;
  - skills;
  - planejamento de dev.

#### V3 / Final

- Atlas reconhece padroes de longo prazo:
  - "nesse repo, testes de feature X validam area Y";
  - "Vitor prefere resposta sem codigo bruto";
  - "essa decisao arquitetural ja foi tomada";
  - "esse tipo de implementacao costuma falhar por causa Z".

### Contrato tecnico

Contrato completo: `memory_delta` em `Atlas_CLI_Packets_v1.md`.

### Criterio de pronto

- Sessao importante sempre gera candidatos de memoria.
- Memoria nao entra como verdade sem evidencia.
- Vitor consegue aceitar/rejeitar rapidamente.
- Atlas usa memoria com explicacao quando relevante.

---

## 9. Ponto 6 - Provider Router Mais Inteligente

### Objetivo

Fazer Atlas escolher o melhor motor para cada tarefa, com base em evidencia, nao gosto fixo.

### Sinais do router

- tipo de tarefa;
- risco;
- necessidade de edicao;
- contexto longo;
- latencia;
- falha recente;
- qualidade historica;
- custo;
- disponibilidade;
- preferencia manual;
- necessidade de review cruzado.

### Implementacao por versao

#### V1

- Provider strategy basica:
  - dev/debug tende a Codex;
  - plan/review/research tende a Claude;
  - critical pode usar conselho.
- Health snapshots.
- Fallback quando provider esta offline.

#### V1.5

- Registrar `RouterDecision`.
- Explicar decisao:

```text
Provider: codex_cli
Motivo: tarefa de codigo, repo local, Codex online, menor pain score.
Fallback: claude_cli
```

#### V2

- Router usa metricas historicas:
  - sucesso por tipo de tarefa;
  - falhas por provider;
  - latencia;
  - retrabalho;
  - resultado de quality gate.

#### V2.5

- Router faz dual-review em tarefas criticas:
  - executor;
  - reviewer diferente;
  - sintetizador Atlas.
- Permite comparacao manual:
  - `[V2.5-spec] atlas compare "essa abordagem esta correta?"`.

#### V3 / Final

- Router adaptativo:
  - escolhe por evidencia;
  - troca provider durante sessao se necessario;
  - aprende com qualidade real;
  - evita provider ruim naquele contexto;
  - mantem identidade do Atlas independente do motor.

### Contrato tecnico

Contrato completo: `router_decision` em `Atlas_CLI_Packets_v1.md`.

### Criterio de pronto

- Atlas raramente pede para Vitor escolher provider.
- Quando escolhe errado, aprende com feedback.
- Trocar provider nao quebra continuidade.
- Tarefas criticas podem ter review cruzado.

---

## 10. Ponto 7 - Instalacao, Release, Upgrade E Rollback Como Produto

### Objetivo

Fazer o Atlas CLI parecer produto de verdade no Mac, nao script de desenvolvimento.

### Regra central

`atlas bootstrap` e o unico comando recomendado para configuracao e validacao.

### Implementacao por versao

#### V1

- `atlas bootstrap`:
  - diagnostica providers;
  - escreve `.env` com backup;
  - instala launcher;
  - configura PATH;
  - roda `atlas doctor --strict` automaticamente.
- `atlas doctor --strict` existe como diagnostico interno/avancado.

#### V1.5

- Criar comandos:

- `[V1.5-spec] atlas version`;
- `atlas doctor --strict`;
- `[V1.5-spec] atlas bootstrap --repair`.

- Bootstrap deve detectar:
  - launcher quebrado;
  - symlink errado;
  - PATH ausente;
  - provider binario ausente;
  - permissao de workspace invalida.

#### V2

- Upgrade:
  - `[V2-spec] atlas update`;
  - `[V2-spec] atlas update --dry-run`.

- Rollback:
  - `[V2-spec] atlas rollback`;
  - `[V2-spec] atlas rollback --to <version>`.

- Release metadata:
  - versao;
  - commit;
  - data;
  - migrations necessarias;
  - compatibilidade.

#### V2.5

- Self-repair:
  - corrige PATH;
  - reinstala launcher;
  - revalida providers;
  - sugere comandos de reparo apenas via bootstrap.

#### V3 / Final

- Instalacao completa:
  - one command setup;
  - doctor automatico;
  - upgrade seguro;
  - rollback confiavel;
  - changelog;
  - health report.

### Criterio de pronto

- Novo Mac configura Atlas com um fluxo unico.
- Qualquer problema de setup aponta para `atlas bootstrap`.
- Upgrade nao quebra CLI sem rollback.
- Doctor final roda sempre no bootstrap, exceto com `--no-doctor`.

---

## 11. Ordem De Implementacao Recomendada

### Bloco A - Fechar V1 Profissional

1. Fortalecer `atlas dev --plan-only` e preflight.
2. Padronizar completion packet.
3. Garantir tool events minimos.
4. Garantir permission gates minimos.
5. Manter `atlas bootstrap` como comando unico recomendado.

### Bloco B - V1.5 Dev Operacional

1. Criar `DevExecutionPlan`.
2. Criar executor por etapas.
3. Integrar runtime tools no fluxo.
4. Criar trace last/show.
5. Criar memory delta candidato.

### Bloco C - V2 Produto Terminal Forte

1. TUI interativa real.
2. Permission session.
3. Tool events persistidos.
4. Checkpoint browser.
5. Test runner inteligente.

### Bloco D - V2.5 Inteligencia Acumulada

1. Router decisions persistidos.
2. Router por metricas historicas.
3. Memory review.
4. Learning loop de skills.
5. Handoff forte entre providers.

### Bloco E - V3/Final

1. Dev loop autonomo controlado.
2. Review cruzado automatico em tarefas criticas.
3. Upgrade/rollback.
4. TUI operacional completa.
5. Evals de qualidade por tarefa.
6. Produto usado diariamente sem abrir provider direto.

---

## 12. Definition Of Done Da Versao Final

O Atlas CLI so pode ser declarado final quando todos os itens abaixo forem verdadeiros:

- `atlas bootstrap --refresh-providers --strict` passa em Mac limpo configurado.
- `atlas doctor --strict` roda automaticamente ao fim do bootstrap.
- `atlas dev` completa tarefas pequenas e medias com diff, testes e completion packet.
- TUI mostra estado, diff, testes, permissao, provider e checkpoints.
- Toda escrita tem checkpoint.
- Todo shell mutavel tem trace.
- Toda permissao tem justificativa.
- Toda sessao importante gera memory delta candidato.
- Router registra provider escolhido e motivo.
- Troca de provider preserva continuidade.
- Upgrade e rollback funcionam.
- Resposta final e clara, curta e sem codigo bruto desnecessario.
- Vitor consegue usar Atlas CLI por uma semana como interface principal de desenvolvimento.

---

## 13. Metricas De Produto

| Metrica | Meta final |
|---|---|
| Uso direto de provider | Menos de 20% das tarefas de dev |
| Tarefas pequenas concluidadas pelo `atlas dev` | Mais de 80% |
| Tarefas medias concluidadas pelo `atlas dev` | Mais de 60% |
| Sessoes com trace completo | Mais de 95% |
| Escritas com checkpoint | 100% |
| Shell mutavel com permissao/trace | 100% |
| Memory deltas uteis | Mais de 50% dos candidatos aceitos |
| Provider escolhido sem correcao manual | Mais de 85% |
| Falhas com causa clara | Mais de 95% |
| Tempo para retomar sessao antiga | Menos de 2 minutos |

As metas iniciais sao deliberadamente operacionais, nao cientificas. Elas devem ser revisadas depois de 30 a 50 sessoes reais: 80% para tarefas pequenas define utilidade diaria; 60% para tarefas medias reconhece que ainda havera supervisao; 95% de traces e 100% de checkpoints sao limites de seguranca, nao aspiracao.

---

## 14. Nao Fazer Ate A Base Estar Solida

- Nao priorizar app Mac visual.
- Nao criar multi-agent livre sem contrato.
- Nao permitir danger por default.
- Nao salvar memoria sem evidencia.
- Nao transformar o Atlas em wrapper fino de provider.
- Nao esconder falhas de teste.
- Nao declarar produto final sem uso real por dias.

---

## 15. Proximo Passo Profissional

O proximo passo de implementacao deve ser:

```text
Ponto 1, V1.5: DevExecutionPlan + executor por etapas + completion packet mais forte.
```

Motivo: esse e o caminho mais direto para fazer o Atlas CLI substituir Claude Code/Codex CLI na pratica. A TUI, o router e a memoria ficam muito mais valiosos quando existe um `atlas dev` realmente operacional gerando eventos, traces e resultados.
