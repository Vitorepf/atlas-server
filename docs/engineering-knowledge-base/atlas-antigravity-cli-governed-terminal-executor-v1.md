---
id: atlas-antigravity-cli-governed-terminal-executor-v1
type: engineering_knowledge
title: Atlas Antigravity CLI Governed Terminal Executor v1
status: active
category: programming-forge
priority: 97
implementation_state: reference_only_sdk_first_no_cli_driver
summary: Contrato canonico para classificar o Antigravity CLI como referencia operacional, discovery e auditoria do ecossistema, sem implementar wrapper ou driver CLI no Atlas. A integracao certa para autonomia, medicao e aprendizado do Atlas e o Antigravity SDK governado.
tags:
  - atlas
  - antigravity
  - cli
  - agy
  - terminal
  - provider-harness
  - antifragile
capabilities:
  - antigravity_cli_governed_terminal_executor
  - terminal_harness_absorption
  - forge_cli_executor_candidate
  - provider_harness_rivals_probe
  - atlas_sovereign_cli_antifragility
decisions:
  - Antigravity CLI deve ser tratado como surface terminal de um provider-harness, nao como Atlas, nao como provider direto e nao como fonte canonica.
  - Atlas nao deve investir em wrapper ou driver CLI enquanto houver caminho SDK programavel; o CLI fica fora do hot path.
  - O comando `agy` pode ser usado para discovery e auditoria do comportamento Antigravity, mas nao como runtime Atlas.
  - `--dangerously-skip-permissions` e proibido para qualquer fluxo Atlas, benchmark governado ou workspace canonico.
  - `--sandbox` deve ser o default operacional para experimentos em workspace Atlas, salvo excecao humana registrada.
  - Modelo escolhido em `/model` ou selector do CLI e observacao operacional; autoridade de roteamento continua em Atlas Decide.
maintenance:
  - Atualizar quando `agy --help`, docs oficiais do CLI, sandbox, permissions, plugins, MCP, subagents ou modelos mudarem.
  - Atualizar se o SDK deixar de ser viavel e houver decisao explicita para reavaliar CLI.
  - Manter abaixo de 520 linhas; este doc e referencia, nao runbook de implementacao.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/atlas-antigravity-sdk-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-ai-operator-review-approval-gates.md
  - docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md
external_references:
  - https://www.antigravity.google/product/antigravity-cli
  - https://antigravity.google/docs/cli-overview
  - https://antigravity.google/docs/cli-getting-started
  - https://antigravity.google/docs/cli-using
  - https://antigravity.google/docs/cli-features
  - https://antigravity.google/docs/models
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-antigravity-cli-governed-terminal-executor-v1
graph_title: Atlas Antigravity CLI Governed Terminal Executor v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-governed-provider-invocation-v1
graph_status: active
graph_source: repo
human_name: Atlas Antigravity CLI Governed Terminal Executor v1
canonical_name: Atlas Antigravity CLI Governed Terminal Executor v1
technical_name: atlas-antigravity-cli-governed-terminal-executor-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
allowed_changes:
  - Refinar contrato, flags, riscos e diferenca entre CLI e SDK.
  - Registrar evidencias novas do CLI que afetem a estrategia SDK-first.
  - Atualizar evidencias de `agy --help` e docs oficiais.
forbidden_changes:
  - Declarar Antigravity CLI como substituto de Atlas Dev, Atlas Forge, Codex CLI, Claude CLI, Gemini CLI ou Atlas provider router.
  - Criar wrapper, driver, automacao, CI ou hot path Atlas baseado em `agy` sem decisao explicita de supersede do SDK-first.
  - Usar `agy` como automacao governada sem Work Packet, Decision Receipt, sandbox, allowlist, receipts e Evidence.
  - Permitir `--dangerously-skip-permissions` em workspace Atlas, CI, benchmark ou experimentos canonicos.
  - Permitir que `/model`, settings do CLI ou subagents decidam provider/model, escopo, sucesso, policy, memory write ou completion claim.
  - Importar plugins, MCPs ou hooks do Antigravity para Atlas sem review, sandbox e AP quando afetar runtime.
depends_on:
  - atlas-ai-provider-evolution-intelligence
  - atlas-forge-governed-provider-invocation-v1
  - atlas-forge-real-provider-drivers-v1
  - atlas-antigravity-sdk-governed-executor-v1
  - atlas-programming-forge-flow
flows_to:
  - atlas-forge-rivals-provider-arena-corpus-v1
  - atlas-code-forge-operator-cockpit-v1
  - programming-professional-completion-audit
unlocks:
  - antigravity-cli-ecosystem-reference
  - antigravity-cli-risk-classification
  - antigravity-sdk-first-implementation-focus
governs:
  - antigravity-cli-usage
  - agy-terminal-experiments
  - provider-harness-cli-candidates
evidence:
  - docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
  - local: /Users/vitorepf/.local/bin/agy --help
  - https://antigravity.google/docs/cli-features
  - https://antigravity.google/docs/models
evidence_refs:
  - symbol: AtlasAntigravityCliGovernedTerminalExecutorService
  - command: atlas:aaeos:antigravity-cli-governed-terminal-executor
  - test: AtlasAntigravityCliGovernedTerminalExecutorTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
  - "php artisan atlas:ai:runtime-boundary --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - cli
  - forge
  - antigravity
  - governed-executor
ai_entrypoints:
  - Leia este doc antes de sugerir `agy` em qualquer workspace Atlas.
  - Para implementacao, redirecione para Antigravity SDK governado.
  - Trate CLI como referencia externa e ferramenta fora do hot path; nunca como autoridade.
ai_usage_notes:
  - A resposta correta para "usar Antigravity CLI no Atlas" e "nao implementar CLI; usar SDK-first se a meta for autonomia".
  - Se o CLI escolher Claude, Gemini ou GPT-OSS, isso nao torna o CLI autoridade de roteamento; Atlas Decide continua sendo o roteador soberano.
quality_gates:
  - no-runtime-driver-before-ap
  - sandbox-default
  - dangerous-skip-permissions-forbidden
  - atlas-decide-authority-preserved
  - atlas-memory-authority-preserved
  - work-packet-scope-locked
  - evidence-ledger-complete
  - rivals-measured-before-promotion
  - fallback-provider-path-green
failure_modes:
  - Operador usa Antigravity CLI como canal principal e pula Atlas Dev/Forge.
  - `agy` executa comandos ou edita arquivos fora do Work Packet.
  - `/model` ou selector do CLI vira policy de modelo do Atlas.
  - Subagents do CLI executam tarefas sem approvals, logs ou scope guard Atlas.
  - Plugin/MCP/hook do Antigravity altera ambiente sem governanca Atlas.
observability_signals:
  - antigravity_cli_present
  - agy_help_hash
  - agy_version
  - agy_flags_seen
  - antigravity_cli_sandbox_requested
  - antigravity_cli_permissions_mode
  - antigravity_cli_model_observed
  - work_packet_id
  - decision_receipt_id
  - stdout_hash
  - diff_hash
  - fallback_used
next_actions:
  - Nao implementar wrapper ou driver CLI.
  - Usar este doc para impedir desvio de escopo quando o SDK for implementado.
  - Concentrar Provider Release Envelope, AP, tests e Rivals no Antigravity SDK.
---
# Atlas Antigravity CLI Governed Terminal Executor v1

## Resumo

Este documento define como o Atlas deve entender o Antigravity CLI (`agy`) sem
transforma-lo em runtime Atlas. A estrategia canonica e SDK-first.

A tese permitida e:

```text
Atlas aprende com o ecossistema Antigravity CLI
mas implementa autonomia via Antigravity SDK governado
```

A tese proibida e:

```text
Antigravity CLI vira canal soberano, decide modelo, edita livremente e declara
completion sem Atlas.
```

O CLI e a superficie terminal leve do Antigravity. Pelos docs oficiais, ele
compartilha o mesmo harness agentic do Antigravity, suporta multi-step, multi-file
editing, tool calling, historico, settings compartilhados, subagents, plugins,
MCP, skills, hooks, sandbox e slash commands como `/model`, `/permissions`,
`/agents`, `/mcp`, `/skills`, `/config` e `/resume`.

Para o Atlas, isso e valioso como sinal de ecossistema. Nao e caminho de
implementacao principal.

## Papel no Atlas

O Antigravity CLI fica abaixo de Atlas Dev, Atlas Forge, Atlas Memory, Atlas SDD,
Atlas Decide, Provider Evolution, Rivals, Evidence Ledger e Completion Gate.

Ele pode ajudar em tres cenarios fora do hot path:

1. Exploration humana de uma feature externa recem-lancada.
2. Auditoria de comportamento, flags, modelos e permissoes do Antigravity.
3. Evidencia auxiliar para orientar a integracao SDK-first.

Ele nao deve ser usado como:

- substituto do `atlas dev`;
- substituto do `atlas forge`;
- roteador de modelo;
- memoria de longo prazo;
- gerador de completion claim;
- mecanismo canonico de evidence;
- canal unico de programacao.

## Onde Se Encaixa

```text
Layer -1 Thesis / Multiplicador / Canal Soberano
-> Provider Evolution Intelligence
-> Programming Forge Flow
-> Atlas Forge Governed Provider Invocation
-> Real Provider Drivers
-> Antigravity SDK Governed Executor Candidate
-> Antigravity CLI Reference / Discovery Boundary (este doc)
-> Rivals / Evidence / Promotion Review do SDK
```

O CLI e mais proximo de uma surface operacional terminal do que de uma API.
O SDK e o candidato programavel; o CLI fica como referencia e limite para nao
desviar trabalho para um caminho que o Atlas nao deve automatizar.

## O Que Antigravity CLI E Para O Atlas

| Camada | Exemplo | Autoridade |
|---|---|---|
| Modelo | Gemini, Claude, GPT-OSS | Motor cognitivo observado |
| Harness | Antigravity CLI / `agy` | Referencia terminal, nao runtime Atlas |
| Atlas | Memory, SDD, Decide, Gates, Evidence | Soberano |

Antigravity CLI nao e "Gemini CLI". Ele pode usar modelos selecionaveis, mas a
selecao local no CLI nao substitui Atlas Decide. Se o CLI mostrar Gemini,
Claude ou GPT-OSS, registre como `model_observed`, nao como policy Atlas.

## Contratos

| Contrato | Regra |
|---|---|
| Soberania | Atlas decide tarefa, escopo, gates e sucesso |
| Terminal | `agy` pode existir fora do hot path, nao como arquitetura paralela |
| Sandbox | `--sandbox` e default para experimento Atlas |
| Permissoes | `/permissions` deve ficar restritivo; approvals humanos contam |
| Perigo | `--dangerously-skip-permissions` e proibido |
| Modelo | `/model` e selector sao observacao, nao roteamento soberano |
| Subagents | `/agents` nao entram no Agent Control Plane Atlas |
| Plugins/MCP | exigem review antes de tocar workspace Atlas |
| Evidence | stdout, diff, logs e decisions devem virar hashes/receipts Atlas |

## Fluxo

Fluxo permitido de referencia:

```text
1. Atlas observa docs/flags/modelos/permissoes do CLI.
2. Atlas registra riscos e capacidades que afetam a estrategia SDK-first.
3. Atlas implementa e mede via SDK governado, nao via `agy`.
```

Fluxo proibido:

```text
Atlas driver -> agy --print --sandbox -> stdout/diff -> receipts -> gates
```

Esse fluxo nao deve ser implementado enquanto o SDK existir como caminho
programavel. Automatizar CLI tende a criar debt e fragilidade desnecessarios.

## Comandos E Flags

Estado local observado em `agy --help`:

| Comando/flag | Uso Atlas |
|---|---|
| `--print`, `--prompt`, `-p` | referencia de capacidade; nao usar como driver Atlas |
| `--prompt-interactive`, `-i` | fora do hot path Atlas |
| `--continue`, `-c` | nao usar para Work Packet Atlas |
| `--conversation` | auditoria manual externa, nao memoria Atlas |
| `--add-dir` | proibido ampliar scope sem atualizar Work Packet |
| `--sandbox` | recomendado/default para workspace Atlas |
| `--print-timeout` | referencia; wrapper CLI nao e rota canonica |
| `--log-file` | permitido se logs forem redigidos antes de Evidence/UI |
| `--dangerously-skip-permissions` | proibido |
| `plugin` / `plugins` | somente com review e policy |
| `install` / `update` / `changelog` | humano-operado, nunca durante driver runtime |

Slash commands relevantes:

| Slash command | Regra Atlas |
|---|---|
| `/model` | observar e registrar; nao decide policy |
| `/permissions` | manter restritivo |
| `/agents` / `/tasks` | monitorar subagents; scope nao expande sozinho |
| `/mcp` / `/skills` | proibido adicionar capacidade sem review |
| `/resume` / `/rewind` | permitido para auditoria humana |
| `/logout` | permitido para encerrar sessao/credenciais |

## Exemplos

Permitido como discovery externo:

```text
agy --sandbox --prompt-interactive
```

sem transformar a sessao em evidence ou completion do Atlas.

Proibido:

```text
agy --dangerously-skip-permissions --add-dir /Users/vitorepf/develop
```

Proibido:

```text
Abrir `agy`, colar a missao inteira do Atlas, aceitar todas as edits e declarar
pronto sem tests, Evidence, scope guard e review.
```

## Regras Para IA

1. Nunca diga que Antigravity CLI substitui Atlas.
2. Nunca diga que Antigravity CLI substitui Gemini CLI, Claude CLI ou Codex CLI
   dentro do Atlas sem Rivals e Provider Evolution.
3. Nunca sugira `--dangerously-skip-permissions`.
4. Nunca use `--add-dir` para burlar allowed files.
5. Nunca aceite `/model` como autoridade de roteamento.
6. Nunca deixe subagent do CLI decidir escopo, arquivos, model policy ou sucesso.
7. Nunca instale plugin/MCP/hook sem review.
8. Nunca envie memoria canonica, secrets, Evidence Ledger completo ou prompts
   internos para o CLI.
9. Nunca transforme conversa retomada por `--continue` em novo Work Packet sem
   novo receipt.
10. Nunca chame ganho de "multiplicador" sem benchmark e evidence.

## Escopo De Implementacao

Fase unica - Referencia e boundary:

- Documentar `agy --help` e docs oficiais.
- Manter proibicao de wrapper/driver CLI.
- Concentrar implementacao, tests e Rivals no SDK.
- Usar achados do CLI apenas para configurar riscos do SDK.

## Modelo De Dados Minimo

| Schema | Campos obrigatorios |
|---|---|
| `atlas.antigravity_cli_reference.v1` | `binary_path`, `present`, `help_hash`, `flags_seen`, `sandbox_supported`, `dangerous_flags`, `models_observed`, `implementation_allowed=false` |

Campos novos nao podem abrir runtime CLI.

## Sandbox E Permissoes

Baseline:

- iniciar com `--sandbox`;
- usar `/permissions` em modo restritivo;
- aprovar comandos individualmente;
- negar acesso fora do Work Packet;
- bloquear rede livre quando o benchmark nao exigir;
- bloquear secrets e tokens no prompt;
- bloquear plugin/MCP/hook sem review;
- registrar logs redigidos;
- encerrar credenciais com `/logout` quando apropriado.

O Atlas deve considerar qualquer execucao sem sandbox como evento de risco, nao
como baseline de producao.

## Criterios De Multiplicador

Antigravity CLI nao e medido como multiplicador de runtime. Ele so ajuda se
melhorar a decisao SDK-first:

- revelar capacidades que o SDK deve expor;
- revelar riscos de permissions, plugins, MCP, subagents e modelos;
- ajudar a formular corpus de Rivals para o SDK;
- impedir que o Atlas perca tempo implementando terminal automation fragil.

Se o ganho vier de pular gates, aceitar permissao perigosa ou enviar contexto
bruto, nao e multiplicador. E bypass.

## Proibicoes Criticas

- Proibido usar Antigravity CLI como canal unico de programacao Atlas.
- Proibido rodar `agy` com permissao perigosa em workspace canonico.
- Proibido permitir que settings do CLI sobrescrevam policy Atlas.
- Proibido instalar plugins/MCPs do CLI como dependencia oculta do Atlas.
- Proibido tratar conversas salvas do CLI como memoria Atlas.
- Proibido usar subagents do CLI como Agent Control Plane paralelo.
- Proibido criar wrapper/driver CLI enquanto a rota SDK-first estiver ativa.
- Proibido promover output sem tests, scope guard, Evidence e review.

## Como Qualquer IA Deve Implementar

Ao receber pedido de implementacao Antigravity:

```text
1. Rodar session-bootstrap e place-feature.
2. Ler este doc e o doc do SDK.
3. Nao criar wrapper/driver CLI.
4. Criar Provider Release Envelope para SDK.
5. Implementar medicao, tests, Rivals e driver SDK governado.
```

## Riscos

O risco principal e desperdicarmos energia implementando automacao terminal
quando o SDK oferece a rota correta para autonomia, teste, contratos e medicao.

Mitigacao:

```text
Atlas scopes.
Atlas decides.
SDK executes.
Atlas verifies.
Atlas records.
Atlas learns.
```

## Dependencias

- `atlas-canonical-glossary-and-naming.md` para nomes canonicos.
- `atlas-ai-provider-evolution-intelligence.md` para absorcao antifragil.
- `atlas-antigravity-sdk-governed-executor-v1.md` como rota de implementacao.
- `atlas-forge-governed-provider-invocation-v1.md` para invocacao governada.
- `atlas-forge-real-provider-drivers-v1.md` para padroes de drivers.
- `atlas-programming-forge-flow.md` para Obra, SDD e completion.

## Evidencias

Evidencia atual:

- `agy --help` local mostra `--print`, `--prompt-interactive`, `--continue`,
  `--conversation`, `--add-dir`, `--sandbox`, `--dangerously-skip-permissions`,
  `install`, `plugin`, `update` e `changelog`.
- Docs oficiais descrevem CLI como TUI leve do Antigravity com mesmo harness
  agentic, settings compartilhados e foco em terminal.
- Docs oficiais descrevem sandbox, permissions, slash commands, subagents,
  plugins, MCP, skills e hooks.
- Docs oficiais de modelos indicam selector com Gemini, Claude e GPT-OSS.

Evidencia ausente:

- Decisao de supersede que torne CLI mais adequado que SDK.
- Necessidade tecnica real de terminal automation.

Enquanto isso faltar, Antigravity CLI permanece
`reference_only_sdk_first_no_cli_driver`.

## Proximas Acoes

1. Nao implementar CLI.
2. Usar CLI apenas como referencia de capacidades e riscos.
3. Implementar a integracao real pelo Antigravity SDK.
4. Fazer Atlas Decide aprender performance via SDK, Rivals, tests e Evidence.
5. Reavaliar CLI somente se o SDK bloquear uma capacidade essencial.
