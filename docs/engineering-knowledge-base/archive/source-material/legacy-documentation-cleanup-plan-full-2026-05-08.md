---
id: legacy-documentation-cleanup-plan
type: engineering_knowledge
title: Legacy Documentation Cleanup Plan
status: active
category: documentation-governance
priority: 90
summary: Plano seguro e estado atual das ondas de promocao, merge, arquivo e eventual remocao de documentacao legacy do Atlas.
tags:
  - atlas
  - documentation
  - cleanup
  - plan
capabilities:
  - legacy_documentation_cleanup
  - documentation_archive_governance
  - source_material_promotion
decisions:
  - Limpeza documental deve proteger runtime e docs mae.
  - Source material historico deve ser promovido, arquivado ou redirecionado sem competir com docs canonicos.
maintenance:
  - Usar este plano apenas para ondas futuras de limpeza, sempre conferindo worktree concorrente.
  - Nao apagar documentos sem busca de referencias e aprovacao humana.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-report.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/archive/README.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
---

# Legacy Documentation Cleanup Plan

Este plano descreve como aplicar e continuar a limpeza documental. As promocoes
prioritarias ja foram executadas em 2026-05-05; as fases restantes devem focar
em merges finos, redirects, quarentena e delete candidates com aprovacao humana.

Nota de concorrencia: a worktree pode conter alteracoes de outras sessoes,
incluindo novos docs de dominios, docs mae modificados, migrations e codigo.
Antes de aplicar qualquer fase, a sessao principal deve separar o diff de
limpeza documental do diff de implementacao runtime.

## Regras De Seguranca

1. Nao apagar nenhum arquivo na primeira rodada.
2. Nao mover docs sem redirect.
3. Nao editar docs mae sem revisar `atlas-ai-canonical-architecture-index.md`.
4. Nao promover nota de Obsidian/AtlasVault sem privacy/redaction/review.
5. Nao tratar `CLAUDE.md`, `AGENTS.md`, prompt ou plano task-by-task como fonte primaria.
6. Antes de qualquer delete futuro, rodar busca por links, referencias em codigo e historico git.
7. Nao integrar docs novos de dominio ao indice canonico sem revisar o diff de quem os criou.
8. Nao transformar plano de PR em doc permanente sem separar decisao estavel de tarefa executavel.

## Invariantes De Implementacao

Estes invariantes devem valer em todos os PRs de limpeza:

| Invariante | Como verificar |
|---|---|
| Runtime intacto | `git diff --name-only` nao deve incluir `app/`, `config/`, `database/`, `routes/`, `bootstrap/` ou `tests/`, exceto se a sessao explicitamente mudar de escopo. |
| Docs mae protegidos | `atlas-ai-canonical-architecture-index.md`, `atlas-ai-kernel-architecture.md` e `atlas-ai-master-architecture.md` so mudam em PRs de promocao aceitos. |
| Redirect antes de archive/delete | Todo doc arquivado aponta substituto canonico antes de qualquer move/delete. |
| Privacy antes de promocao | Conteudo de vault/human notes passa por redaction e source policy. |
| Fair benchmark isolado | Nenhum texto novo mistura Fair Claude Strict com Atlas Supercharged. |
| Worktree concorrente revisada | Mudancas de outras sessoes sao identificadas antes de editar os mesmos arquivos. |

## Ordem Recomendada

### Fase 0 - Congelar Autoridade

Objetivo: impedir que docs concorrentes mandem antes da limpeza.

Acoes:

- Confirmar que `README.md`, `START_HERE.md` e `atlas-ai-canonical-architecture-index.md` continuam sendo a entrada oficial.
- Nao criar novo doc mae sem declarar layer e autoridade.
- Marcar na sessao principal que `resolver-o-que-vale-a-pena` e corpus historico governado.
- Listar arquivos alterados por outras sessoes e decidir se a limpeza roda antes ou depois deles.

Saida esperada:

- Lista curta de docs canonicos obrigatorios.
- Nenhuma edicao runtime.

Gate de aceite:

- `git status --short` foi capturado na descricao do PR.
- A sessao declara explicitamente quais arquivos de docs mae serao tocados.
- O plano da sessao aponta para uma unica fase, ou justifica misturar fases.

### Fase 1 - Promocoes Prioritarias

Promover apenas lacunas reais:

| Prioridade | Fonte | Destino recomendado | Motivo |
|---|---|---|---|
| P0 | `resolver-o-que-vale-a-pena/docs/atlas-glossary.md` | `atlas-ai-layer-0-glossary.md` | Executado em 2026-05-05. |
| P0 | `Atlas_Documento_Mestre_v6.md` + `Atlas_AI_Documentacao_Final.md` | `atlas-ai-layer-0-glossary.md` | Executado em forma enxuta; fonte longa permanece human_vault_only/source material. |
| P0 | `Atlas_AI_Sessoes_Compactacao_Continuidade.md` | `atlas-ai-continuity-session-state.md` | Executado em 2026-05-05. |
| P1 | `atlas-ai-telemetry-quality-efficiency-implementation.md` + `docs/atlas-ai-telemetry.md` | `atlas-ai-telemetry-evidence-performance.md` | Executado em 2026-05-05. |
| P1 | `docs/paste-image-setup.md` | `atlas-ai-cli-multimodal.md` | Executado em 2026-05-05; setup segue como runbook. |
| P1 | `atlas-ai-mobile-operating-model.md` + `mobile-gateway-push-inbox-implementation.md` | `atlas-ai-mobile-surface-gateway.md` | Executado em 2026-05-05. |
| P2 | `Atlas_CLI_Packets_v1.md` | `atlas-ai-runtime-packets.md` | Executado em 2026-05-05. |
| P2 | `Atlas_AI_Skill_System_v1.md` | `atlas-ai-skill-system.md` | Executado em 2026-05-05. |
| P2 | `Atlas_Gaps_Achamos_Nao_Esquecer.md` | `atlas-ai-governed-backlog.md` | Executado em 2026-05-05. |
| P2 | `docs/atlas-mac-agent.md` | `atlas-local-agent-surface.md` | Executado em 2026-05-05. |

Promocoes condicionais ja iniciadas por outra sessao:

| Prioridade | Fonte | Destino recomendado | Motivo |
|---|---|---|---|
| P1 | `docs/engineering-knowledge-base/domains/finance.md` | Canonical index Layer 4 depois de aceite | Domain spec novo e safety-critical. |
| P1 | `docs/engineering-knowledge-base/domains/personal-development.md` | Canonical index Layer 4 depois de aceite | Domain spec novo, privado e nao clinico. |
| P1 | `docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md` | Runbook/architecture doc apos separar decisoes de plano | Mapeia integracao das surfaces com domain catalog. |

Definition of Done de cada promocao:

- Novo conteudo tem frontmatter KB.
- O doc declara autoridade e substitutos.
- Conteudo sensivel/pessoal foi redigido.
- O doc promovido nao copia plano inteiro; sintetiza decisoes.
- Links para fontes legacy ficam em secao "source material".

Gate de aceite:

- O novo doc cabe em uma ordem de leitura existente ou declara onde deve entrar.
- O source material permanece preservado ate pelo menos uma release.
- Um reviewer consegue responder "qual doc manda?" sem abrir o legado.

### Fase 2 - Mesclar P0 Ja Parcialmente Promovidos

Comparar e fechar lacunas entre legados P0 e docs atuais:

| Fonte legacy | Doc atual | Decisao |
|---|---|---|
| `2026-05-03-atlas-domain-profile-orchestration-architecture.md` | `atlas-ai-operating-system.md`, `atlas-ai-pipeline.md`, `atlas-ai-core-vs-domain.md` | Confirmar cobertura de Domain/Flow/Profile. |
| `2026-05-02-atlas-decide-final-architecture.md` | `atlas-ai-kernel-architecture.md`, `atlas-ai-operating-system.md` | Confirmar receipt, fallback, policy e evidence contract. |
| `2026-05-03-atlas-programming-product-architecture.md` | `domains/programming.md`, `atlas-ai-operating-system.md`, `engineering-blueprint.md` | Confirmar `programming.dev/forge/qa/security/refactor`. |
| `Atlas_AI_Harness_Super_Tool_Runtime_Core.md` | `super-tool-runtime-core.md`, `programming-power-tools-catalog.md` | Confirmar registry, policy, executor, normalizer, evidence e learning loop. |
| `Atlas_Engineering_Harness_Runner_Plano_Profissional.md` | `engineering-blueprint*.md` | Confirmar DoD e gaps de harness runner. |

Saida esperada:

- Pequeno patch nos docs canonicos, se lacunas reais existirem.
- Caso contrario, apenas redirect nos legados.

Gate de aceite:

- Cada merge lista explicitamente "lacunas migradas" ou "nenhuma lacuna".
- A tabela de substitutos do legado aponta para o doc canonico exato.
- Nao ha frase nova declarando autoridade que contradiga Kernel/Master/Index.

### Fase 2.5 - Normalizar Docs Novos De Dominio

Objetivo: absorver docs de dominio criados por outra sessao sem baguncar a
governanca da KB.

Acoes:

- Revisar `domains/finance.md` contra `atlas-ai-master-architecture.md`,
  `atlas-ai-operating-system.md`, `memory-core-security-privacy.md` e o registry
  de dominios.
- Revisar `domains/personal-development.md` contra `obsidian-atlas-vault.md`,
  `memory-core-security-privacy.md` e a policy de dados sensiveis.
- Confirmar se `surface-domain-catalog-integration-plan.md` e plano temporario
  ou deve virar runbook permanente.
- Atualizar `atlas-ai-canonical-architecture-index.md` apenas se a sessao
  principal aceitar esses docs como Layer 4.
- Evitar copiar material de `resolver-o-que-vale-a-pena/root-md` diretamente
  para esses docs sem redaction.

Definition of Done:

- Cada domain spec declara status, authority, source material e safety boundary.
- Cada domain spec tem substitutos/relacionados claros.
- O integration plan nao duplica o Kernel nem o Operating System.
- Nenhum doc pessoal vira provider context sem policy.

Gate de aceite:

- Finance continua review-only e sem execucao de mercado.
- Personal Development continua privado, nao clinico e sem mutacao automatica.
- Surface/domain catalog separa provider, executor preference, runtime e safety.
- Docs de dominio entram como Layer 4, nao como nova arquitetura-mae.

### Fase 3 - Redirects E Arquivo

Para cada `archive_with_redirect`:

1. Adicionar cabecalho curto no arquivo antigo:

```md
> Status: archived/deprecated.
> Canonical replacement: `...`.
> Preserved for historical context; do not use as source of truth.
```

2. Nao remover corpo na primeira rodada.
3. Atualizar indice/lista de docs legados na KB se necessario.
4. Rodar busca de links apos a alteracao.

Arquivos prioritarios para redirect:

- `docs/engineering-knowledge-base/architecture.md`
- `docs/engineering-knowledge-base/context-pack.md`
- `docs/engineering-knowledge-base/capability-matrix.md`
- `docs/engineering-knowledge-base/maintenance-playbook.md`
- `docs/engineering-knowledge-base/mcp-tools-contract.md`
- `docs/engineering-knowledge-base/mcp-tools-rollout-report.md`
- `docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md`
- `docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md`
- `docs/atlas-cli-5x-codex-implementation-prompt.md`
- `docs/atlas-cli-5x-codex-safety-context-prompt.md`
- provider bootstrap `CLAUDE.md`/`AGENTS.md`
- planos antigos em `resolver-o-que-vale-a-pena/root-md`

Template recomendado de redirect, sem remover conteudo:

```md
> Status: archived.
> Canonical replacement: `docs/engineering-knowledge-base/...`.
> Cleanup note: preserved for history and link compatibility. Do not use as source of truth.
```

Para docs com conteudo sensivel ou pessoal:

```md
> Status: human_vault_only.
> Canonical operational replacement: `docs/engineering-knowledge-base/...`.
> Privacy note: do not inject this note directly into provider context. Promote only reviewed excerpts.
```

Gate de aceite:

- Redirect preserva link antigo e aponta substituto claro.
- Corpo antigo permanece intacto na primeira rodada.
- Busca por referencias foi rodada depois do redirect.
- README/START_HERE so mudam se necessario para navegacao.

### Fase 4 - Quarentena De Delete Candidates

Arquivos inicialmente candidatos:

- `docs/superpowers/plans/2026-05-04-atlas-dev-paste-image.md`
- `resolver-o-que-vale-a-pena/root-md/Atlas_CLI_Bootstrap_Setup.md`

Antes de deletar em uma sessao futura:

```bash
rg -n "2026-05-04-atlas-dev-paste-image|Atlas_CLI_Bootstrap_Setup|atlas-cli-bootstrap" .
git log --follow -- <arquivo>
```

Criterios para delete seguro:

- Existe redirect por pelo menos uma release.
- Nenhuma referencia em docs/codigo/scripts.
- Conteudo foi promovido ou declarado obsoleto.
- O operador humano aprovou explicitamente.

Gate de aceite:

- Delete candidate fica em PR separado.
- PR contem resultado de `rg` e `git log --follow`.
- O PR prova que redirect existiu antes do delete.

### Fase 5 - AtlasVault / Human Knowledge Surface

Acoes:

- Nao mover notas pessoais para runtime.
- Criar, se necessario, uma lista de notas `human_vault_only`.
- Para cada nota pessoal/constitucional, extrair apenas decisoes provider-safe.
- Preservar pesquisa concorrencial como research note.

Docs nesta trilha:

- `Atlas_Documento_Mestre_v6.md`
- `Atlas_Memoria_Semantica_Ativa_Compartilhada.md`
- `Atlas_Memoria_Semantica_Ativa_Projeto_Funcional.md`
- `Atlas_Captura_Pensamento_e_Notas_Vivas.md`
- `Atlas_Adendo_Sensor4_Atividade_Digital.md`
- `Atlas_Concorrente_Hermes_Agent.md`

Gate de aceite:

- Nenhum trecho pessoal e copiado integralmente para KB operacional.
- Promocoes citam a fonte legacy sem expor detalhes sensiveis.
- O reviewer de privacy consegue bloquear provider projection se necessario.

## Plano De Rollback

Se uma promocao ou redirect criar confusao:

1. Reverter apenas o PR documental afetado, nunca usar reset amplo.
2. Restaurar o cabecalho anterior do doc legacy, se ele foi alterado.
3. Manter o source material preservado ate nova decisao.
4. Registrar a causa no plano de limpeza antes de tentar novamente.

Se um doc canonico ficar contraditorio:

1. O canonical index decide temporariamente qual documento manda.
2. Abrir PR pequeno para resolver a contradicao.
3. Nao aplicar redirects adicionais ate a contradicao fechar.

## Comandos De Auditoria Recomendados

Somente leitura:

```bash
rg -n "resolver-o-que-vale-a-pena|superpowers/plans|superpowers/specs|Atlas_Documento_Mestre|paste-image|Fair Claude|CLAUDE.md|AGENTS.md" docs app config database routes tests
find docs resolver-o-que-vale-a-pena -type f -name "*.md" -print | sort
git status --short
```

Para checar se um arquivo ainda e referenciado:

```bash
rg -n "nome-do-arquivo|slug-do-doc|titulo principal" docs app config database routes tests scripts
```

Para revisar somente o escopo documental do PR:

```bash
git diff --name-only -- docs resolver-o-que-vale-a-pena
git diff --stat -- docs resolver-o-que-vale-a-pena
```

Depois de editar docs canonicos em uma sessao futura:

```bash
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
```

Nao rodar esses comandos se a sessao estiver limitada a inventario sem tocar docs
mae, como esta.

## Checklist Para A Sessao Principal

- [ ] Ler este plano e o relatorio pareado.
- [ ] Escolher uma fase por vez.
- [ ] Criar branch ou commit antes de mexer em docs existentes.
- [ ] Separar mudancas documentais de mudancas runtime ja presentes na worktree.
- [ ] Promover Layer 0/glossary antes de arquivar documentos constitucionais.
- [ ] Promover telemetry/evidence antes de arquivar docs de performance.
- [ ] Promover paste-image setup antes de arquivar spec/plano.
- [ ] Revisar Finance/Personal Development/surface-domain plan antes de atualizar indices canonicos.
- [ ] Adicionar redirects, nunca apagar direto.
- [ ] Rodar busca de referencias.
- [ ] Rodar sync/index apenas depois de alteracoes canonicas.
- [ ] Validar com humano antes de qualquer delete.

## Sequencia Profissional Recomendada Para O Proximo PR

1. PR futuro `docs-governance-domains`: aceitar ou ajustar Finance, Personal
   Development e surface-domain catalog.
2. PR futuro `docs-governance-provider-choice`: revisar edge cases vivos dos
   planos provider-choice ja marcados como source material, sem criar policy
   paralela.
3. PR futuro `docs-governance-redirects`: revisar redirects nos legados ja
   substituidos, usando `archive/README.md` como regra operacional.
4. PR futuro `docs-governance-quarantine`: revisar delete candidates, sem
   apagar, com busca de links e aprovacao humana.

## Backlog Operacional

| ID | PR sugerido | Escopo | Arquivos fonte | Entrega | Risco |
|---|---|---|---|---|---|
| DOCGOV-01 | `docs-governance-layer0` | Constitution, glossary e nomenclatura | `Atlas_Documento_Mestre_v6.md`, `Atlas_AI_Documentacao_Final.md`, `atlas-glossary.md`, `Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md` | Executado: `atlas-ai-layer-0-glossary.md` | Alto |
| DOCGOV-02 | `docs-governance-continuity` | Sessions, compactacao e `atlas continue` | `Atlas_AI_Sessoes_Compactacao_Continuidade.md`, Open Brain docs | Executado: `atlas-ai-continuity-session-state.md` | Alto |
| DOCGOV-03 | `docs-governance-telemetry` | Telemetry, performance, aggregator versions e quality efficiency | `docs/atlas-ai-telemetry.md`, performance docs, telemetry implementation legacy | Executado: `atlas-ai-telemetry-evidence-performance.md` | Alto |
| DOCGOV-04 | `docs-governance-mobile` | Atlas Mobile, gateway, push e inbox | mobile operating model, mobile gateway/push/inbox plan | Executado: `atlas-ai-mobile-surface-gateway.md` | Medio/alto |
| DOCGOV-05 | `docs-governance-domains` | Finance, Personal Development e surface/domain catalog | `domains/*.md`, surface-domain plan, selected human-vault sources | Domain specs aceitos e plano surface/domain estabilizado | Alto |
| DOCGOV-06 | `docs-governance-cli-multimodal` | Paste image e CLI multimodal | paste-image setup/spec/plan | Executado: `atlas-ai-cli-multimodal.md` + redirects | Medio |
| DOCGOV-07 | `docs-governance-resolver-p0` | Resolver P0 ja parcialmente promovidos | Domain Profile, Decide, Programming, Super Tool Runtime legados | Executado como source material governado; lacunas futuras so por diff semantico comprovado | Alto |
| DOCGOV-08 | `docs-governance-redirects-kb` | Legados internos da KB ja deprecated/archived | `architecture.md`, `context-pack.md`, `capability-matrix.md`, etc. | Redirect headers | Baixo |
| DOCGOV-09 | `docs-governance-provider-prompts` | Provider bootstrap e Codex prompts | `CLAUDE.md`, `AGENTS.md`, Codex prompt docs | Redirects e source status | Medio |
| DOCGOV-10 | `docs-governance-quarantine` | Delete candidates sem apagar | paste-image implementation plan, CLI bootstrap setup | Marcacao/quarentena e evidencia de links | Medio |
| DOCGOV-11 | `docs-governance-skills-packets-backlog` | Skills, runtime packets, Mac Agent e governed backlog | skill system, packets, Mac Agent, gaps | Executado: quatro docs canonicos pequenos + redirects | Medio |

## Estimativa De Esforco

| Bundle | Esforco | Motivo |
|---|---|---|
| Layer 0/glossary | Grande | Requer curadoria humana e evita criar doc mae gigante. |
| Continuity/session | Medio | Escopo tecnico delimitado, mas toca memoria/provider context. |
| Telemetry/evidence | Grande | Pode haver comandos, schemas e dashboards vivos. |
| Mobile/gateway | Medio/grande | Precisa validar se feature esta viva e separar surface de domain. |
| Domains/surface catalog | Grande | Worktree concorrente e safety de Finance/Personal Development. |
| CLI multimodal/paste image | Pequeno/medio | Conteudo bem delimitado; risco principal e plano antigo parecer vivo. |
| Resolver P0 merges | Grande | Exige diff semantico contra docs mae. |
| Redirects KB | Pequeno | Substitutos ja estao declarados. |
| Provider prompts | Executado/pequeno | Projections, nao fonte primaria. |
| Quarentena | Executado/pequeno | Nao deleta, so prepara evidencia. |

## Ordem De Dependencias

```text
DOCGOV-01 Layer 0/glossary
  -> DOCGOV-05 domains/surface catalog

DOCGOV-02 continuity/session
  -> DOCGOV-03 telemetry/evidence

DOCGOV-06 CLI multimodal
  -> DOCGOV-10 quarantine

DOCGOV-07 resolver P0 merges
  -> DOCGOV-08 redirects KB
  -> DOCGOV-09 provider prompts
```

Pode executar em paralelo se os PRs nao tocarem os mesmos docs canonicos:

- `DOCGOV-06` e `DOCGOV-08`.
- `DOCGOV-09` e `DOCGOV-10`.
- `DOCGOV-04` depois de definir se mobile ainda e prioridade ativa.

Evitar paralelo:

- `DOCGOV-01` com qualquer edicao no canonical index.
- `DOCGOV-05` com mudancas runtime de domain registry.
- `DOCGOV-07` com edicoes simultaneas em Kernel/Master/Operating System.

## Formato De PR Recomendado

Cada PR de limpeza deve incluir:

```md
## Escopo

- Fase:
- Docs fonte:
- Docs canonicos alterados:
- Docs legacy com redirect:

## Decisoes

- Promovido:
- Mesclado:
- Arquivado:
- Mantido como human_vault_only:

## Evidencia

- Busca de referencias:
- Sync/index rodado:
- Riscos restantes:

## Fora de escopo

- Runtime:
- Deletes:
- Docs mae nao relacionados:
```

Regra de revisao: se o PR nao consegue preencher "Docs canonicos alterados" e
"Docs legacy com redirect", ele provavelmente mistura descoberta com limpeza e
deve voltar para fase de inventario.

## Checklist De Handoff

Antes de entregar este trabalho para uma sessao de aplicacao:

- [ ] Confirmar que os dois arquivos de inventario estao commitados ou
  explicitamente anexados ao PR.
- [ ] Confirmar se os docs novos de Finance/Personal Development pertencem ao
  mesmo branch de trabalho ou a outra sessao.
- [ ] Escolher apenas um `DOCGOV-*` para comecar.
- [ ] Registrar no PR quais docs mae podem ser editados.
- [ ] Registrar quais arquivos legacy serao apenas lidos.
- [ ] Registrar que deletes estao fora de escopo.
- [ ] Rodar busca de referencias antes e depois de redirect.
- [ ] Se houver promocao de human/vault material, registrar privacy reviewer.
- [ ] Se houver alteracao canonica, planejar sync/index no final.

## Evidencia Minima Por Tipo De Mudanca

| Tipo | Evidencia minima |
|---|---|
| Promocao para KB | Source material listado, doc destino com frontmatter, substituto/autoridade declarado. |
| Merge em existente | Lista de lacunas migradas, diff pequeno no doc canonico, legado preservado. |
| Archive redirect | Header de redirect, substituto canonico, busca de referencias. |
| Human vault only | Privacy note, fonte preservada, nenhum provider context direto. |
| Delete candidate | Redirect previo, `rg` limpo, `git log --follow`, aprovacao humana. |
| Domain spec | Safety boundary, autonomy, forbidden actions, relation to Layer 4. |
| Benchmark doc | Trilha declarada: Fair Claude ou Atlas Supercharged. |

## Comandos De Sanidade Final

Executar ao fim de cada PR documental:

```bash
git diff --name-only
git diff --check
rg -n "source of truth|fonte de verdade|canonical|canonico|mae" docs/engineering-knowledge-base docs/*.md resolver-o-que-vale-a-pena
rg -n "CLAUDE.md|AGENTS.md|Atlas_Documento_Mestre|resolver-o-que-vale-a-pena" docs/engineering-knowledge-base
```

Interpretacao:

- `git diff --name-only` deve mostrar apenas docs esperados para aquele PR.
- `git diff --check` nao deve acusar whitespace.
- A busca por "source of truth" deve confirmar que nenhuma fonte legacy voltou a
  disputar autoridade.
- A busca por nomes legacy dentro da KB deve aparecer apenas como source
  material, redirect ou inventario.

## Resultado Esperado Apos A Limpeza

Estado final desejado:

- KB com autoridade clara e sem docs mae concorrentes.
- `resolver-o-que-vale-a-pena` preservado como corpus historico, com redirects.
- Prompts e planos task-by-task fora do caminho canonico.
- AtlasVault/Obsidian respeitado como Human Knowledge Surface / Personal Knowledge Workspace.
- Fair Claude separado de Atlas Supercharged.
- Paste image documentado como capacidade viva de `atlas dev`.
- Tool Runtime e Power Tools com uma unica fonte canonica.
