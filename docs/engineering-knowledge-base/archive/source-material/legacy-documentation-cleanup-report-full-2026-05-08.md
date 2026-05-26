---
id: legacy-documentation-cleanup-report
type: engineering_knowledge
title: Legacy Documentation Cleanup Report
status: source_material
category: documentation-governance
priority: 90
summary: Inventario e registro profissional da limpeza de documentacao canonica, legacy, duplicada e orfa do Atlas, com promocoes canonicas, redirects e pendencias residuais.
tags:
  - atlas
  - documentation
  - cleanup
  - legacy
capabilities:
  - legacy_documentation_inventory
  - documentation_archive_governance
  - source_material_promotion
decisions:
  - Este relatorio registra o inventario e as promocoes de limpeza documental ja executadas.
  - Docs legacy nao devem competir com README, START_HERE, Canonical Architecture Index ou Documentation OS.
maintenance:
  - Atualizar quando novas ondas de limpeza promoverem, arquivarem ou redirecionarem documentos.
  - Nao usar como fonte unica para arquitetura ativa; usar como auditoria historica.
related_paths:
  - docs/engineering-knowledge-base/legacy-documentation-cleanup-plan.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/archive/README.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
---

# Legacy Documentation Cleanup Report

Data da auditoria: 2026-05-05.

Escopo auditado:

- `docs/`
- `docs/engineering-knowledge-base/`
- `resolver-o-que-vale-a-pena/`
- buscas locais por vault/obsidian/backup/archive dentro do repo

Observacao de concorrencia: durante a auditoria apareceram arquivos e mudancas
nao produzidas por este trabalho, incluindo docs de dominios, docs mae e codigo.
Este trabalho editou apenas documentacao dentro do escopo permitido e nao
reverteu alteracoes concorrentes.

Restricoes respeitadas:

- Docs existentes foram editados apenas para headers, redirects, navegacao,
  relatorio e promocao canonica controlada.
- Nenhum arquivo runtime, PHP, migration ou config foi editado.
- Nenhum arquivo documental foi apagado ou movido.
- Novos docs canonicos pequenos foram criados na KB para substituir lacunas
  antes marcadas como futuras.

## Sumario Executivo

O Atlas ja tem uma Knowledge Base canonica madura em
`docs/engineering-knowledge-base`, com `README.md`, `START_HERE.md`,
`atlas-ai-canonical-architecture-index.md` e familias ativas de arquitetura,
memoria, engineering blueprint, tool runtime e power tools.

O maior risco documental nao e ausencia de conteudo; e excesso de documentos
bons competindo por autoridade. A pasta `resolver-o-que-vale-a-pena` contem
material de alto valor, mas misturado com planos executados, prompts de provider,
rascunhos de produto, documentos constitucionais e specs antigas. Ela deve virar
corpus historico governado, nao fonte direta de decisao.

Recomendacao principal:

1. Manter a KB como fonte primaria.
2. Manter as promocoes executadas como docs pequenos, subordinados ao indice,
   sem copiar documentos mestres longos.
3. Manter redirects nos planos executados e prompts soltos.
4. Preservar documentos constitucionais e pessoais como `human_vault_only`.
5. Tratar potenciais deletes como quarentena documental, nunca deletar nesta
   sessao.

## Cobertura Do Inventario

Arquivos documentais considerados nesta revisao:

| Area | Volume observado | Tratamento neste relatorio |
|---|---:|---|
| `docs/engineering-knowledge-base` | 54 docs markdown apos a chegada de docs novos | Classificados por familia: canonicos, legados internos, novos dominios, arquivo documental e planos de integracao. |
| `docs/` fora da KB | 12 docs markdown | Classificados como canonicos externos, promocoes candidatas, merges ou arquivos com redirect. |
| `resolver-o-que-vale-a-pena/docs` | 11 docs markdown | Classificados entre P0/P1/P2, promocao, merge ou archive. |
| `resolver-o-que-vale-a-pena/provider-bootstrap` | 2 docs markdown | Classificados como projections historicas, nao fonte primaria. |
| `resolver-o-que-vale-a-pena/root-md` | 26 docs markdown | Classificados por constituicao, harness, CLI, memoria, research e backlog. |

O inventario inicial era intencionalmente conservador: qualquer documento com
possivel decisao ainda nao promovida recebeu `promote_to_kb` ou
`merge_into_existing`, nao classificacao de delete. As tabelas de execucao
mais abaixo registram quais desses itens ja foram fechados como
`superseded_source_material`, `archived`, `archived_quarantine`,
`archived_projection` ou `human_vault_only`.

Linhas de classificacao inicial registradas neste relatorio, incluindo
documentos, fontes novas concorrentes e itens conceituais:

| Classe | Linhas classificadas | Leitura executiva |
|---|---:|---|
| `keep_canonical` | 36 | Base canonica e docs novos que devem ser preservados. |
| `promote_to_kb` | 13 | Lacunas reais que precisam virar KB/ADR ou runbook. |
| `merge_into_existing` | 14 | Conteudo valioso que nao deve criar novo doc mae. |
| `archive_with_redirect` | 24 | Historico util, prompts, planos e specs antigas. |
| delete candidate historico | 2 | Candidatos futuros, convertidos para quarentena nesta onda. |
| `human_vault_only` | 6 | Material humano/pessoal/research a preservar com privacy. |

Esta contagem nao e uma lista de delete; ela serve para estimar trabalho. Um
mesmo arquivo pode aparecer em contexto principal e em area de atencao especial
quando isso ajuda a sessao principal a nao perder o motivo.

## Snapshot Atual Dos Headers

Estado atual dos headers `Cleanup status` apos as ondas de limpeza de
2026-05-05:

| Cleanup status | Arquivos | Significado operacional |
|---|---:|---|
| `superseded_source_material` | 26 | Fonte historica util com substituto canonico declarado. |
| `archived` | 14 | Plano, prompt, versao ou relatorio antigo preservado por rastreabilidade. |
| `archived_projection` | 2 | Projection de provider/agente, nunca fonte primaria. |
| `archived_quarantine` | 2 | Delete candidate preservado; delete exige auditoria e aprovacao humana. |
| `human_vault_only` | 6 | Material humano/pessoal/research preservado, nao runtime cru. |

Nao ha headers de pendencia residual restantes nesta onda. As classificacoes
iniciais acima continuam no relatorio como trilha de auditoria, mas o estado
operacional atual e o header do arquivo.

## Legenda

| Classe | Significado |
|---|---|
| `keep_canonical` | Ja e fonte canonica ou suporte operacional oficialmente listado. |
| `promote_to_kb` | Tem decisoes ainda nao consolidadas e deve virar doc canonico ou ADR. |
| `merge_into_existing` | Conteudo util que precisava diff semantico; se ja fechado, a tabela de execucao abaixo substitui esta classificacao inicial. |
| `archive_with_redirect` | Deve ficar preservado com cabecalho apontando substituto; nao deve mandar. |
| delete candidate historico | Classificacao inicial possivel; nesta onda os candidatos concretos foram rebaixados para `archived_quarantine`, sem delete. |
| `human_vault_only` | Bom para Obsidian/AtlasVault/leitura humana, nao para runtime canonico. |

## Criterios De Decisao

Use estes criterios antes de mudar a classificacao de qualquer item.

| Pergunta | Sim | Nao |
|---|---|---|
| O documento e listado por `README.md`, `START_HERE.md` ou canonical index como autoridade? | `keep_canonical` | Continue avaliando. |
| O documento contem decisao ainda sem equivalente canonico? | `promote_to_kb` ou `merge_into_existing` | Continue avaliando. |
| O documento e plano de execucao, prompt, bootstrap de provider ou spec ja implementada? | `archive_with_redirect` | Continue avaliando. |
| O documento contem conteudo pessoal, constitucional, research ou notas longas humanas? | `human_vault_only` | Continue avaliando. |
| O documento tem substituto claro, baixo risco e zero referencias vivas apos redirect? | `archived_quarantine` ate revisao de delete | Nao deletar. |

Regras duras:

- Delete candidate nunca e acao imediata; use `archived_quarantine` ate uma
  revisao separada provar que o arquivo nao tem links, valor historico ou valor
  de implementacao.
- `human_vault_only` nao significa "sem valor"; muitas vezes e o material mais
  valioso, so nao deve virar runtime cru.
- `merge_into_existing` exige diff semantico contra o substituto antes de
  arquivar.
- `promote_to_kb` deve virar doc pequeno, governado e provider-safe, nao copia
  integral do legado.

## Matriz Executiva Por Acao

| Acao | Onde aparece mais | Responsavel ideal | Pre-condicao | Saida segura |
|---|---|---|---|---|
| Manter canonico | KB ativa e docs CLI 5x | Dono da arquitetura/area | Confirmar que esta no indice ou README | Nenhuma alteracao ou apenas indexacao futura. |
| Promover para KB | Layer 0, glossary, telemetry, continuity, mobile, paste image | Dono da area + reviewer de docs | Fonte legacy lida, redigida e sintetizada | Novo doc ou ADR com source material. |
| Mesclar em existente | P0 resolver, harness, CLI roadmap, memory core | Dono do doc canonico | Diff semantico contra doc atual | Pequeno patch no doc canonico + redirect no legado. |
| Arquivar com redirect | Prompts, planos executados, specs antigas, provider bootstrap | Dono de docs/governance | Substituto claro definido | Cabecalho de redirect, corpo preservado. |
| Quarentena/delete candidate | Planos task-by-task e setup local antigo | Dono humano | Redirect aplicado + busca de links limpa + aprovacao humana | Marcacao futura; delete so em PR separado. |
| Human vault only | Constituicao, memoria pessoal, research, sensores pessoais | Dono humano + privacy reviewer | Privacy/redaction revisadas | Preservacao e promocao apenas de excertos. |

## Docs Canonicos A Manter

| Documento | Classe | Motivo | Substituto canonico | Risco de apagar | Acao recomendada |
|---|---|---|---|---|---|
| `docs/engineering-knowledge-base/README.md` | `keep_canonical` | Fonte primaria da KB e lista docs canonicos. | N/A | Critico: quebra onboarding e sync conceitual. | Manter. |
| `docs/engineering-knowledge-base/START_HERE.md` | `keep_canonical` | Ordem oficial de leitura para humanos/IAs. | N/A | Critico: novas sessoes perdem mapa. | Manter. |
| `docs/engineering-knowledge-base/adr/0001-engineering-knowledge-source-of-truth.md` | `keep_canonical` | ADR da fonte de verdade. | N/A | Alto: perde decisao de governanca. | Manter. |
| `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md` | `keep_canonical` | Resolve hierarquia Layer 0-4. | N/A | Critico: docs voltam a competir. | Manter. |
| `docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md` | `keep_canonical` | Mother spec executavel de runtime, receipts, ledger e manifests. | N/A | Critico: contratos runtime perdem autoridade. | Manter. |
| `docs/engineering-knowledge-base/atlas-ai-master-architecture.md` | `keep_canonical` | Arquitetura-mae enterprise e planes. | N/A | Critico: roadmap/produto perdem raiz. | Manter. |
| `docs/engineering-knowledge-base/atlas-ai-vision.md` | `keep_canonical` | Resumo fundador curto. | N/A | Alto: perde norte compacto. | Manter. |
| `docs/engineering-knowledge-base/atlas-ai-pipeline.md` | `keep_canonical` | Pipeline unico do Atlas AI. | N/A | Alto: fluxos duplicam. | Manter. |
| `docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md` | `keep_canonical` | Fronteira Core/Profile/Domain/Surface. | N/A | Alto: risco de features presas em comandos. | Manter. |
| `docs/engineering-knowledge-base/atlas-ai-operating-system.md` | `keep_canonical` | Governanca macro de dominios, surfaces, dev/forge e anti-duplicacao. | N/A | Critico: Atlas Dev/Forge/Decide voltam a competir. | Manter. |
| `docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md` | `keep_canonical` | Auditoria oficial inicial do corpus resolver. | N/A | Alto: perde ponte para material legado. | Manter e usar junto deste relatorio. |
| `docs/engineering-knowledge-base/atlas-ai-architecture-audit.md` | `keep_canonical` | Diagnostico de consolidacao da arquitetura Atlas AI. | N/A | Medio/alto: perde racional historico. | Manter. |
| `docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md` | `keep_canonical` | Documento mestre de memoria/contexto/Open Brain. | N/A | Critico: Memory Core perde contrato. | Manter. |
| `docs/engineering-knowledge-base/open-brain-context-injection.md` | `keep_canonical` | Define injecao automatica de contexto em dev/continue/chat. | N/A | Critico: risco de contexto inseguro. | Manter. |
| `docs/engineering-knowledge-base/obsidian-atlas-vault.md` | `keep_canonical` | Contrato canonico da Human Knowledge Surface / Personal Knowledge Workspace. | N/A | Critico: Obsidian pode virar fonte crua indevida. | Manter. |
| `docs/engineering-knowledge-base/memory-core-runbook.md` | `keep_canonical` | Operacao diaria de memoria e sync. | N/A | Alto. | Manter. |
| `docs/engineering-knowledge-base/memory-core-contracts.md` | `keep_canonical` | Contratos de tabelas, refs, API e CLI. | N/A | Alto. | Manter. |
| `docs/engineering-knowledge-base/memory-core-security-privacy.md` | `keep_canonical` | Politica de privacy/redaction/provider-safety. | N/A | Critico. | Manter. |
| `docs/engineering-knowledge-base/memory-core-failure-modes.md` | `keep_canonical` | Diagnostico de falhas por camada. | N/A | Medio. | Manter. |
| `docs/engineering-knowledge-base/memory-core-maturity-dod.md` | `keep_canonical` | Maturidade e DoD da memoria. | N/A | Medio. | Manter. |
| `docs/engineering-knowledge-base/code-intelligence.md` | `keep_canonical` | Indice de codigo e doc links. | N/A | Alto. | Manter. |
| `docs/engineering-knowledge-base/engineering-blueprint.md` | `keep_canonical` | Produto final do Engineering Blueprint System. | N/A | Critico para harness. | Manter. |
| `docs/engineering-knowledge-base/engineering-blueprint-contracts.md` | `keep_canonical` | Schemas e invariantes do blueprint. | N/A | Alto. | Manter. |
| `docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md` | `keep_canonical` | Gates de QA/review/Postgres. | N/A | Alto. | Manter. |
| `docs/engineering-knowledge-base/engineering-blueprint-runbook.md` | `keep_canonical` | Operacao app/CLI/API do blueprint. | N/A | Alto. | Manter. |
| `docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md` | `keep_canonical` | Estado real e DoD dos 7 itens. | N/A | Medio/alto. | Manter. |
| `docs/engineering-knowledge-base/super-tool-runtime-core.md` | `keep_canonical` | Fonte canonica do Tool Runtime. | N/A | Critico para super tools. | Manter. |
| `docs/engineering-knowledge-base/programming-power-tools-catalog.md` | `keep_canonical` | Catalogo de ferramentas, tiers e autoridade. | N/A | Alto. | Manter. |
| `docs/engineering-knowledge-base/domains/finance.md` | `keep_canonical` | Novo domain spec isolado para Finance; ja declara charter, flows, gates e proibicoes. | N/A | Alto se o dominio for aceito: perde safety contract financeiro. | Manter como canonico condicional; integrar no indice apenas quando a sessao principal aceitar o domain. |
| `docs/engineering-knowledge-base/domains/personal-development.md` | `keep_canonical` | Novo domain spec isolado para Personal Development; privado por default e nao clinico. | N/A | Alto: perde limites de safety/medical e privacidade. | Manter como canonico condicional; integrar no indice apos aceite. |
| `docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md` | `promote_to_kb` | Plano novo e relevante para surfaces consumirem catalogo de dominios; ainda esta `draft`. | `atlas-ai-operating-system.md`, `atlas-ai-kernel-architecture.md`, domain catalog docs futuros | Medio/alto: apaga mapa de integracao app/mobile/MCP. | Manter como plano ativo; depois converter decisoes aceitas em runbook/architecture doc. |
| `docs/atlas-cli-final-product.md` | `keep_canonical` | Doc operacional canonico fora da KB, listado no README. | N/A | Alto para CLI. | Manter; opcionalmente espelhar indice na KB futuramente. |
| `docs/atlas-cli-5x-claude-code-plan.md` | `keep_canonical` | Plano 5x referenciado pela KB. | N/A | Alto para estrategia benchmark. | Manter. |
| `docs/atlas-cli-fair-claude-benchmark.md` | `keep_canonical` | Protocolo justo contra Claude Code. | N/A | Critico para evitar benchmark contaminado. | Manter. |
| `docs/atlas-cli-release-checklist.md` | `keep_canonical` | Checklist de release e gates. | N/A | Alto. | Manter. |

## Legados Ja Marcados Dentro Da KB

| Documento | Classe | Motivo | Substituto canonico | Risco de apagar | Acao recomendada |
|---|---|---|---|---|---|
| `docs/engineering-knowledge-base/architecture.md` | `archive_with_redirect` | Ja esta `deprecated`; README lista substitutos. | `README.md`, `START_HERE.md`, `code-intelligence.md`, `atlas-ai-canonical-architecture-index.md` | Medio: links antigos podem quebrar. | Manter com redirect; nao usar como fonte. |
| `docs/engineering-knowledge-base/context-pack.md` | `archive_with_redirect` | Ja esta `deprecated`; conteudo absorvido por Open Brain/Memory/Code Intelligence. | `open-brain-context-injection.md`, `memory-core-contracts.md`, `code-intelligence.md`, `atlas-ai-pipeline.md` | Medio. | Manter com redirect. |
| `docs/engineering-knowledge-base/capability-matrix.md` | `archive_with_redirect` | Ja esta `deprecated`; matriz substituida por DoD/tool docs. | `engineering-blueprint-maturity-dod.md`, `super-tool-runtime-core.md`, `programming-power-tools-catalog.md` | Baixo/medio. | Manter com redirect. |
| `docs/engineering-knowledge-base/maintenance-playbook.md` | `archive_with_redirect` | Ja esta `deprecated`; playbooks modernos existem. | `START_HERE.md`, `engineering-blueprint-runbook.md`, `memory-core-runbook.md` | Medio. | Manter com redirect. |
| `docs/engineering-knowledge-base/mcp-tools-contract.md` | `archive_with_redirect` | Ja esta `archived`; contrato MCP antigo foi absorvido por Memory/Open Brain. | `atlas-ai-memory-context-core-open-brain.md`, `open-brain-context-injection.md`, `memory-core-contracts.md` | Baixo/medio. | Manter como historico com redirect. |
| `docs/engineering-knowledge-base/mcp-tools-rollout-report.md` | `archive_with_redirect` | Ja esta `archived`; relatorio de rollout, nao spec viva. | Tests/commands MCP + docs Memory/Open Brain | Baixo. | Manter como historico com redirect. |
| `docs/engineering-knowledge-base/archive/source-material/atlas-engineering-blueprint-7-itens-2026-05-01.md` | `archive_with_redirect` | Ja esta em `archive/source-material`; fonte preservada. | `engineering-blueprint*.md` | Baixo se redirect existir; medio sem ele. | Manter no archive. |

## Docs Em `docs/` Fora Da KB

| Documento | Classe | Motivo | Substituto canonico | Risco de apagar | Acao recomendada |
|---|---|---|---|---|---|
| `docs/atlas-ai-telemetry.md` | `archive_with_redirect` | Operacional e util, mas autoridade de arquitetura/evidence foi consolidada na KB. | `atlas-ai-telemetry-evidence-performance.md`, `atlas-ai-kernel-architecture.md`, `atlas-ai-operating-system.md` | Alto: pode conter comandos/API ativos. | Manter como runbook/source material com redirect. |
| `docs/atlas-ai-aggregator-versions.md` | `archive_with_redirect` | Changelog tecnico preservado; governanca de comparabilidade esta na KB. | `atlas-ai-telemetry-evidence-performance.md` | Medio: perde historico de rollup. | Manter como changelog/source material. |
| `docs/atlas-ai-performance-reports.md` | `archive_with_redirect` | Runbook de relatorios preservado; contrato canonico esta na KB. | `atlas-ai-telemetry-evidence-performance.md` | Medio. | Manter como runbook/source material. |
| `docs/atlas-ai-performance-engine-ops.md` | `archive_with_redirect` | Operacao diaria preservada; contrato canonico esta na KB. | `atlas-ai-telemetry-evidence-performance.md` | Medio/alto. | Manter como runbook/source material. |
| `docs/atlas-mac-agent.md` | `archive_with_redirect` | Runbook operacional de agente Mac; arquitetura local surface foi promovida. | `atlas-local-agent-surface.md`, `atlas-ai-mobile-surface-gateway.md` | Medio: instala/valida agente local. | Manter como runbook com redirect. |
| `docs/paste-image-setup.md` | `archive_with_redirect` | Guia vivo para paste de imagem em terminal; autoridade arquitetural foi promovida. | `atlas-ai-cli-multimodal.md`, `atlas-cli-final-product.md` | Medio/alto: operador perde setup de terminal. | Manter como runbook/setup. |
| `docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md` | `archive_with_redirect` | Spec historica de paste image; valor de source material e criterio de DoD. | `atlas-ai-cli-multimodal.md`, `docs/paste-image-setup.md` | Medio: detalhes de design/testes podem ser uteis. | Manter como source material com redirect. |
| `docs/superpowers/plans/2026-05-04-atlas-dev-paste-image.md` | `archived_quarantine` | Plano de execucao task-by-task, com commits/linhas volateis. | Spec paste image + setup final + `atlas-ai-cli-multimodal.md` | Baixo/medio; util apenas para auditoria curta. | Quarentena; delete futuro so apos link audit, `git log --follow` e aprovacao humana. |
| `docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md` | `archive_with_redirect` | Plano de expansao MCP parcialmente substituido por Tool Runtime/Memory docs. | `super-tool-runtime-core.md`, `programming-power-tools-catalog.md`, Memory/Open Brain docs | Medio. | Arquivar com redirect. |
| `docs/atlas-cli-5x-codex-implementation-prompt.md` | `archive_with_redirect` | Prompt de implementacao, nao fonte canonica. | `atlas-cli-5x-claude-code-plan.md`, `atlas-cli-fair-claude-benchmark.md` | Baixo/medio: historico de decisao pode ajudar. | Arquivar com redirect. |
| `docs/atlas-cli-5x-codex-safety-context-prompt.md` | `archive_with_redirect` | Prompt de safety/contexto, nao doc mae. | `atlas-cli-5x-claude-code-plan.md`, `atlas-cli-fair-claude-benchmark.md`, `START_HERE.md` | Baixo/medio. | Arquivar com redirect. |

## Docs Novos E Mudancas Concorrentes Observadas

Estes itens apareceram na arvore durante a continuidade da auditoria. Como ha
mudancas de codigo e docs mae nao produzidas por este trabalho, a recomendacao e
trata-los como output de outra sessao e nao tentar normalizar tudo agora.

| Documento | Classe | Motivo | Substituto canonico | Risco de apagar | Acao recomendada |
|---|---|---|---|---|---|
| `docs/engineering-knowledge-base/domains/finance.md` | `keep_canonical` | Especifica Finance como dominio enterprise de analise/review, com proibicao explicita de execucao de mercado. | `atlas-ai-canonical-architecture-index.md`, `domains/README.md` | Alto: safety financeiro fica implicito. | Manter como Layer 4 implemented/ready. |
| `docs/engineering-knowledge-base/domains/personal-development.md` | `keep_canonical` | Define Personal Development como dominio privado, nao clinico, sem mutacoes automaticas. | `atlas-ai-canonical-architecture-index.md`, `domains/README.md`, `atlas-ai-governed-backlog.md` | Alto: risco de medical/therapy drift se removido. | Manter como Layer 4 implemented/ready. |
| `docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md` | `keep_canonical` | Mapeia hardcodes e consumo do catalogo por CLI/API/app/mobile/MCP. | `atlas-ai-mobile-surface-gateway.md`, `atlas-local-agent-surface.md`, `atlas-ai-operating-system.md` | Medio/alto. | Manter como plano ativo ate virar runbook permanente. |

Impacto no inventario: esses docs reduzem a urgencia de promover material legado
de Finance e Personal Development cru do `resolver-o-que-vale-a-pena`, mas nao
eliminam a necessidade de preservar as fontes historicas como `human_vault_only`
ou backlog.

## `resolver-o-que-vale-a-pena/docs`

| Documento | Classe | Motivo | Substituto canonico | Risco de apagar | Acao recomendada |
|---|---|---|---|---|---|
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-domain-profile-orchestration-architecture.md` | `superseded_source_material` | P0 ja reconhecido pelo resolver audit; conceitos promovidos para Operating System/Pipeline/Core-vs-Domain. | `atlas-ai-operating-system.md`, `atlas-ai-pipeline.md`, `atlas-ai-core-vs-domain.md` | Alto: contem fraseologia fonte de Profile/Flow. | Preservar como source material; lacunas futuras exigem diff semantico. |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-02-atlas-decide-final-architecture.md` | `superseded_source_material` | P0 Decide; contratos vivos ficam no Kernel/OS/Pipeline. | `atlas-ai-kernel-architecture.md`, `atlas-ai-operating-system.md`, `atlas-ai-pipeline.md` | Alto. | Preservar como source material; nao usar como autoridade direta. |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-programming-product-architecture.md` | `superseded_source_material` | P0 Programming/Forge/Dev; domain spec e runtime docs mandam. | `domains/programming.md`, `atlas-ai-operating-system.md`, `engineering-blueprint.md`, `super-tool-runtime-core.md` | Alto. | Preservar como source material; backlog so via doc governado. |
| `resolver-o-que-vale-a-pena/docs/atlas-ai-telemetry-quality-efficiency-implementation.md` | `archive_with_redirect` | P1 no resolver audit; telemetria/economia/qualidade foram sintetizadas na KB. | `atlas-ai-telemetry-evidence-performance.md` | Alto: pode conter schema/metricas relevantes. | Preservar como source material. |
| `resolver-o-que-vale-a-pena/docs/atlas-ai-mobile-operating-model.md` | `archive_with_redirect` | Produto mobile foi promovido como surface/gateway, nao domain paralelo. | `atlas-ai-mobile-surface-gateway.md` | Medio/alto. | Preservar como source material. |
| `resolver-o-que-vale-a-pena/docs/mobile-gateway-push-inbox-implementation.md` | `archive_with_redirect` | Gateway/push/inbox foram sintetizados na KB. | `atlas-ai-mobile-surface-gateway.md` | Alto se feature viva. | Preservar como source material/runbook historico. |
| `resolver-o-que-vale-a-pena/docs/atlas-glossary.md` | `archive_with_redirect` | Layer 0/glossary foi promovido em forma enxuta. | `atlas-ai-layer-0-glossary.md` | Alto: termos legados podem divergir. | Preservar como source material. |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-01-provider-choice-menu-design.md` | `superseded_source_material` | Provider choice e surface UX sao source material; policy atual fica no Kernel/OS/Fair Claude docs. | `atlas-ai-operating-system.md`, `atlas-ai-kernel-architecture.md`, Fair Claude docs | Medio. | Revisar edge cases apenas se diff semantico provar lacuna. |
| `resolver-o-que-vale-a-pena/docs/plans/2026-05-01-provider-choice-app.md` | `archive_with_redirect` | Plano app executavel, linhas e tarefas volateis. | Provider policy/Decide docs canonicos | Medio. | Arquivar com redirect apos extrair lacunas. |
| `resolver-o-que-vale-a-pena/docs/plans/2026-05-01-provider-choice-cli.md` | `archive_with_redirect` | Plano CLI executavel. | Provider policy/Decide/Fair Claude docs | Medio. | Arquivar com redirect. |
| `resolver-o-que-vale-a-pena/docs/plans/2026-05-01-provider-choice-on-limit.md` | `archive_with_redirect` | Plano de rate-limit/auth choice; pode conter edge cases. | Provider policy/Decide docs | Medio. | Extrair edge cases e arquivar. |

## `resolver-o-que-vale-a-pena/provider-bootstrap`

| Documento | Classe | Motivo | Substituto canonico | Risco de apagar | Acao recomendada |
|---|---|---|---|---|---|
| `resolver-o-que-vale-a-pena/provider-bootstrap/CLAUDE.md` | `archive_with_redirect` | Projection para provider, nao fonte primaria; START_HERE diz para nao tratar como fonte. | `START_HERE.md`, `README.md`, docs canonicos | Medio: providers antigos podem depender. | Preservar como projection historica; redirect. |
| `resolver-o-que-vale-a-pena/provider-bootstrap/AGENTS.md` | `archive_with_redirect` | Projection para agentes, nao doc mae. | `START_HERE.md`, `README.md` | Medio. | Preservar como projection historica; redirect. |

## `resolver-o-que-vale-a-pena/root-md`

| Documento | Classe | Motivo | Substituto canonico | Risco de apagar | Acao recomendada |
|---|---|---|---|---|---|
| `Atlas_Documento_Mestre_v6.md` | `human_vault_only` | Layer 0/constituicao mais recente; importante, mas nao deve competir cru com KB. | `atlas-ai-layer-0-glossary.md` + `atlas-ai-canonical-architecture-index.md` | Critico: apagar perde lastro constitucional. | Preservar; promover apenas recortes futuros revisados. |
| `Atlas_Documento_Mestre_v5.md` | `archive_with_redirect` | Versao anterior da constituicao; historico. | `Atlas_Documento_Mestre_v6.md` + `atlas-ai-layer-0-glossary.md` | Medio. | Arquivar com redirect para v6/Layer 0. |
| `Atlas_Documento_Mestre_v3.md` | `archive_with_redirect` | Versao antiga; historico. | `Atlas_Documento_Mestre_v6.md` + `atlas-ai-layer-0-glossary.md` | Baixo/medio. | Arquivar com redirect para v6/Layer 0. |
| `Atlas_AI_Documentacao_Final.md` | `archive_with_redirect` | Definicao/leis do Atlas AI foram sintetizadas no Layer 0. | `atlas-ai-layer-0-glossary.md`, `atlas-ai-master-architecture.md`, `atlas-ai-vision.md` | Alto. | Preservar como source material. |
| `Atlas_AI_Harness_v1.md` | `superseded_source_material` | Conceitos do Harness absorvidos por Engineering Blueprint/Operating System/Skills/Continuity. | `engineering-blueprint.md`, `atlas-ai-operating-system.md`, `atlas-ai-skill-system.md`, `atlas-ai-continuity-session-state.md` | Medio/alto. | Preservar como rationale historico. |
| `Atlas_AI_Harness_Super_Tool_Runtime_Core.md` | `superseded_source_material` | P0 Runtime legado substituido pela familia Tool Runtime/Power Tools/Packets/Evidence. | `super-tool-runtime-core.md`, `programming-power-tools-catalog.md`, `atlas-ai-runtime-packets.md`, `atlas-ai-telemetry-evidence-performance.md` | Alto. | Preservar como rationale/sensores historicos. |
| `Atlas_Engineering_Harness_Runner_Plano_Profissional.md` | `superseded_source_material` | P1 Harness Runner substituido pela familia Engineering Blueprint/runtime/evidence. | `engineering-blueprint*.md`, `super-tool-runtime-core.md`, `atlas-ai-telemetry-evidence-performance.md` | Alto se houver DoD nao migrado. | Preservar rollout historico; lacunas futuras so por diff. |
| `Atlas_Engineering_Blueprint_7_Itens_Plano_Implementacao.md` | `archive_with_redirect` | Ja existe copia preservada em `archive/source-material`; familia KB atual substitui. | `engineering-blueprint*.md` | Baixo/medio. | Usar copia em archive como fonte; redirect. |
| `Atlas_AI_Memory_Context_Core_Open_Brain.md` | `superseded_source_material` | P1; KB tem doc canonico com mesmo nome/conteudo evoluido e familia memory atual. | `atlas-ai-memory-context-core-open-brain.md`, Memory docs, `atlas-ai-continuity-session-state.md` | Alto se apagar antes de comparar hashes/lacunas. | Preservar cronologia/backlog, nao autoridade direta. |
| `Atlas_AI_Sessoes_Compactacao_Continuidade.md` | `archive_with_redirect` | Continuidade/session state foram promovidos na KB. | `atlas-ai-continuity-session-state.md`, `open-brain-context-injection.md` | Alto. | Preservar como source material. |
| `Atlas_AI_Skill_System_v1.md` | `archive_with_redirect` | Skills como capacidade governada foram promovidas em doc enxuto. | `atlas-ai-skill-system.md`, `atlas-ai-kernel-architecture.md` | Medio/alto. | Preservar como source material. |
| `Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md` | `archive_with_redirect` | Nomenclatura/glossario foram promovidos; CLI produto segue fora da KB. | `atlas-ai-layer-0-glossary.md`, `atlas-cli-final-product.md` | Alto: nomes divergentes voltam. | Preservar como source material. |
| `Atlas_CLI_Caminho_Versao_Final.md` | `archive_with_redirect` | Plano tecnico de versao final; substituido por produto final/release checklist. | `atlas-cli-final-product.md`, `atlas-cli-release-checklist.md` | Medio. | Extrair lacunas e arquivar. |
| `Atlas_CLI_Plano_Execucao_Final.md` | `archive_with_redirect` | Plano faseado antigo. | `atlas-cli-final-product.md`, `atlas-cli-release-checklist.md` | Medio. | Arquivar com redirect. |
| `Atlas_CLI_Produto_Final_Roadmap_7_Pontos.md` | `superseded_source_material` | P1; roadmap preservado e substituido por CLI product/5x/multimodal/backlog. | `atlas-cli-final-product.md`, `atlas-cli-5x-claude-code-plan.md`, `atlas-ai-cli-multimodal.md`, `atlas-ai-governed-backlog.md` | Medio/alto. | Lacunas futuras entram pelo governed backlog. |
| `Atlas_CLI_TUI_Estado_da_Arte_Plano_Implementacao.md` | `archive_with_redirect` | Plano antigo de CLI/TUI. | `atlas-cli-final-product.md` | Medio. | Arquivar apos extrair comandos/UX vivos. |
| `Atlas_CLI_Packets_v1.md` | `archive_with_redirect` | Packets/eventos foram mapeados para contratos kernel/runtime atuais. | `atlas-ai-runtime-packets.md`, `atlas-ai-kernel-architecture.md`, `super-tool-runtime-core.md` | Alto se usados por código/traces. | Preservar como source material. |
| `Atlas_CLI_Bootstrap_Setup.md` | `archived_quarantine` | Setup/scheduler antigo e provavel instrucao local volatil. | `atlas-cli-final-product.md`, release docs | Baixo/medio; pode conter comandos antigos. | Quarentena; deletar apenas apos redirect, link audit, `git log --follow` e validacao humana. |
| `Atlas_V1_Implementacao_Tecnica.md` | `archive_with_redirect` | Sprint V1 antigo, grande e provavelmente obsoleto. | KB atual + docs mobile se feature viva | Medio. | Preservar historico; nao usar como fonte. |
| `Atlas_AI_Plano_Implementacao_Profissional.md` | `archive_with_redirect` | Plano macro antigo substituido por Master/Operating System. | `atlas-ai-master-architecture.md`, `atlas-ai-operating-system.md` | Medio. | Arquivar com redirect. |
| `Atlas_Memoria_Semantica_Ativa_Compartilhada.md` | `human_vault_only` | Documento de memoria humana/produto amplo; nao runtime canonico cru. | `obsidian-atlas-vault.md`, Memory docs, futuro Constitution | Alto como lastro conceitual. | Preservar em vault; promover apenas recortes. |
| `Atlas_Memoria_Semantica_Ativa_Projeto_Funcional.md` | `human_vault_only` | Projeto funcional de memoria pessoal. | Memory docs + Obsidian contract | Medio/alto. | Preservar; recortar se virar produto. |
| `Atlas_Captura_Pensamento_e_Notas_Vivas.md` | `human_vault_only` | Nota conceitual de captura/notes; pertence a Human Knowledge Surface / Personal Knowledge Workspace. | `obsidian-atlas-vault.md`, `atlas-ai-governed-backlog.md` | Medio/alto. | Preservar em vault/source material. |
| `Atlas_Adendo_Sensor4_Atividade_Digital.md` | `human_vault_only` | Sensor pessoal/digital; pertence a Personal Development com privacy forte. | `domains/personal-development.md`, `obsidian-atlas-vault.md`, `memory-core-security-privacy.md` | Medio/alto; privacy sensivel. | Preservar, nao injetar em provider cru. |
| `Atlas_Gaps_Achamos_Nao_Esquecer.md` | `archive_with_redirect` | P2 no resolver audit; backlog de alto valor agora tem governanca. | `atlas-ai-governed-backlog.md`, `domains/personal-development.md`, `atlas-ai-resolver-corpus-audit.md` | Alto: apagar perde ideias relevantes. | Preservar como source material. |
| `Atlas_Concorrente_Hermes_Agent.md` | `human_vault_only` | Pesquisa concorrencial extensa; nao fonte operacional direta. | `programming-power-tools-catalog.md`, strategy docs | Medio. | Preservar como research note. |

## AtlasVault / Obsidian

Resultado da busca local:

- Nao foi encontrada uma pasta documental `AtlasVault` ou `.obsidian` dentro do
  repo auditado.
- Foram encontrados arquivos e services runtime com nome Vault/AtlasVault, mas
  eles estao fora do escopo de edicao e nao foram alterados.
- O doc canonico existente e `docs/engineering-knowledge-base/obsidian-atlas-vault.md`.

Classificacao:

| Item | Classe | Motivo | Substituto canonico | Risco de apagar | Acao recomendada |
|---|---|---|---|---|---|
| Obsidian/AtlasVault como conceito | `keep_canonical` | Ja tem contrato claro: Human Knowledge Surface / Personal Knowledge Workspace, nao fonte operacional primaria. | `obsidian-atlas-vault.md` | Critico se remover contrato. | Manter e usar como filtro para docs pessoais. |
| Notas pessoais/constitucionais em `resolver-o-que-vale-a-pena/root-md` | `human_vault_only` | Conteudo forte, mas exige privacy, curadoria e promocao antes de runtime. | `obsidian-atlas-vault.md`, `atlas-ai-layer-0-glossary.md`, `atlas-ai-governed-backlog.md` | Alto. | Preservar; promover apenas recortes revisados. |
| `.env.atlas-backup-*` encontrados na raiz | Fora de escopo documental | Backups de ambiente, possivelmente sensiveis; nao ler, nao indexar como doc. | N/A | Critico se expor segredos. | Ignorar neste inventario; tratar por politica de secrets separada. |

## Areas De Atencao Especial

### Tool Runtime E Super Tools

Autoridade atual:

- `docs/engineering-knowledge-base/super-tool-runtime-core.md`
- `docs/engineering-knowledge-base/programming-power-tools-catalog.md`

Legados relevantes:

- `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Harness_Super_Tool_Runtime_Core.md`
- `docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md`

Recomendacao: comparar lacunas e redirecionar os legados. Nao criar outro doc
de tools fora da KB.

### Atlas Dev / Forge / Programming

Autoridade atual:

- `atlas-ai-operating-system.md`
- `engineering-blueprint*.md`
- `super-tool-runtime-core.md`
- `atlas-cli-final-product.md`

Legados relevantes:

- `resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-programming-product-architecture.md`
- planos CLI/TUI em `root-md`

Recomendacao: preservar `atlas dev` e `atlas forge` como intensidades do mesmo
domain `programming`, nunca como docs mae concorrentes.

### Architecture Mother Docs

Autoridade atual:

- `atlas-ai-canonical-architecture-index.md`
- `atlas-ai-kernel-architecture.md`
- `atlas-ai-master-architecture.md`

Lacuna: Layer 0 Constitution/glossary ainda esta parcial e precisa promocao
controlada de `Atlas_Documento_Mestre_v6.md`, `Atlas_AI_Documentacao_Final.md`
e `atlas-glossary.md`.

Observacao adicional: os novos domain specs de Finance e Personal Development
parecem preencher parte do Layer 4. Eles nao substituem Layer 0; devem ser
indexados abaixo da hierarquia existente, nao como nova arquitetura-mae.

### Fair Claude Benchmark

Autoridade atual:

- `docs/atlas-cli-fair-claude-benchmark.md`
- `docs/atlas-cli-5x-claude-code-plan.md`

Legados/prompts:

- `docs/atlas-cli-5x-codex-implementation-prompt.md`
- `docs/atlas-cli-5x-codex-safety-context-prompt.md`

Recomendacao: manter protocolo justo como doc canonico e arquivar prompts com
redirect. Qualquer benchmark deve declarar trilha Fair Claude vs Atlas
Supercharged.

### Paste Image Docs

Autoridade candidata:

- `docs/paste-image-setup.md`

Legados:

- `docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md`
- `docs/superpowers/plans/2026-05-04-atlas-dev-paste-image.md`

Recomendacao: promover `paste-image-setup.md` para a KB ou referencia-lo em
START/README/CLI docs. Arquivar spec/plano apos registrar outcome e fallback por
terminal.

## Riscos Gerais De Limpeza

| Risco | Severidade | Mitigacao |
|---|---|---|
| Apagar documento com decisao ainda nao promovida. | Alta | Fazer diff semantico contra substituto antes de qualquer delete. |
| Quebrar links usados por providers, scripts ou docs. | Media | Redirect por pelo menos uma release. |
| Promover notas pessoais sem privacy/redaction. | Alta | Seguir `obsidian-atlas-vault.md` e `memory-core-security-privacy.md`. |
| Criar novo doc mae concorrente. | Alta | Atualizar canonical index antes de promover nova autoridade. |
| Misturar Fair Claude com Atlas Supercharged. | Alta | Preservar protocolo separado. |
| Deixar planos task-by-task mandarem em runtime atual. | Media | Arquivar com redirect para docs canonicos. |
| Integrar docs novos concorrentes sem revisar dirty worktree. | Alta | Tratar docs novos como mudancas de outra sessao; revisar diffs antes de editar qualquer doc mae. |

## Prioridade De Acao

| Prioridade | Acao | Documentos | Resultado esperado |
|---|---|---|---|
| P0 | Promover Layer 0/glossary | `Atlas_Documento_Mestre_v6.md`, `Atlas_AI_Documentacao_Final.md`, `atlas-glossary.md` | Executado: `atlas-ai-layer-0-glossary.md`. |
| P0 | Fechar continuity/session | `Atlas_AI_Sessoes_Compactacao_Continuidade.md` | Executado: `atlas-ai-continuity-session-state.md`. |
| P1 | Consolidar telemetry/evidence | `docs/atlas-ai-telemetry.md`, performance docs, telemetry implementation legacy | Executado: `atlas-ai-telemetry-evidence-performance.md`. |
| P1 | Integrar docs novos de domain catalog | `domains/finance.md`, `domains/personal-development.md`, `surface-domain-catalog-integration-plan.md` | Parcialmente executado: domain specs estao no index; plano surface-domain segue ativo. |
| P1 | Promover paste image | `docs/paste-image-setup.md` e spec/plano superpowers | Executado: `atlas-ai-cli-multimodal.md`; setup segue runbook. |
| P2 | Arquivar prompts e planos executados | Codex prompts, provider bootstrap, plans superpowers | Executado via headers/redirects; sem delete. |
| P2 | Quarentena delete candidates | paste-image implementation plan, bootstrap setup | Executado via `archived_quarantine`; sem delete. |

## Bundles De Promocao Recomendados

Estes bundles eram as fatias naturais de promocao. A maior parte foi executada
em 2026-05-05; ficam aqui como trilha de auditoria e mapa de pendencias reais.

### Bundle A - Layer 0 Constitution E Glossary

Fontes:

- `resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v6.md`
- `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Documentacao_Final.md`
- `resolver-o-que-vale-a-pena/docs/atlas-glossary.md`
- `resolver-o-que-vale-a-pena/root-md/Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md`

Destino executado:

- `atlas-ai-layer-0-glossary.md`.

Decisoes promovidas:

- Identidade do Atlas e Atlas AI.
- Termos canonicos vs termos legados.
- Fronteira entre provider, executor, surface, domain, flow e runtime.
- Leis/principios que realmente devem orientar codigo e docs.

Risco principal: copiar constituicao longa para a KB sem curadoria. O destino
deve ser curto, versionado e operacional.

### Bundle B - Continuity, Sessions E Compactacao

Fontes:

- `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Sessoes_Compactacao_Continuidade.md`
- `docs/engineering-knowledge-base/open-brain-context-injection.md`
- `docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md`

Destino executado:

- `atlas-ai-continuity-session-state.md`.

Decisoes promovidas:

- Quando continuar thread vs nova sessao.
- Como compactacao preserva intent, evidence e memory refs.
- Como `atlas continue` deve montar contexto provider-safe.

Risco principal: misturar session UX com armazenamento operacional sem privacy.

### Bundle C - Telemetry, Evidence E Quality Efficiency

Fontes:

- `docs/atlas-ai-telemetry.md`
- `docs/atlas-ai-aggregator-versions.md`
- `docs/atlas-ai-performance-reports.md`
- `docs/atlas-ai-performance-engine-ops.md`
- `resolver-o-que-vale-a-pena/docs/atlas-ai-telemetry-quality-efficiency-implementation.md`

Destino executado:

- `atlas-ai-telemetry-evidence-performance.md`.

Decisoes promovidas:

- Eventos e outcomes canonicos.
- `aggregator_version` e regras de rollup.
- Relatorios operacionais e health gates.
- Separacao entre metricas de qualidade, custo, eficiencia e evidence ledger.

Risco principal: apagar runbook ainda usado por comandos ou dashboards.

### Bundle D - Atlas Mobile, Gateway, Push E Inbox

Fontes:

- `resolver-o-que-vale-a-pena/docs/atlas-ai-mobile-operating-model.md`
- `resolver-o-que-vale-a-pena/docs/mobile-gateway-push-inbox-implementation.md`
- `docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md`

Destino executado:

- `atlas-ai-mobile-surface-gateway.md`.

Decisoes promovidas:

- Mobile como surface, nao domain separado por acidente.
- Push/inbox como delivery/interaction layer.
- Safety/autonomy antes de elevar runtime via mobile.

Risco principal: mobile criar policy paralela ao domain catalog.

### Bundle E - CLI Multimodal E Paste Image

Fontes:

- `docs/paste-image-setup.md`
- `docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md`
- `docs/superpowers/plans/2026-05-04-atlas-dev-paste-image.md`

Destino executado:

- `atlas-ai-cli-multimodal.md`.

Decisoes promovidas:

- Setup por terminal.
- Comportamento esperado de `atlas dev`/`atlas chat`.
- Fallbacks e limites por terminal.
- Status da spec/plano como historico.

Risco principal: plano task-by-task antigo continuar parecendo instrucao viva.

### Bundle F - Domain Specs E Surface Catalog

Fontes:

- `docs/engineering-knowledge-base/domains/finance.md`
- `docs/engineering-knowledge-base/domains/personal-development.md`
- `docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md`
- `resolver-o-que-vale-a-pena/root-md/Atlas_Gaps_Achamos_Nao_Esquecer.md`
- `resolver-o-que-vale-a-pena/root-md/Atlas_Adendo_Sensor4_Atividade_Digital.md`

Destino atual:

- Layer 4 domain specs aceitos pelo canonical index.
- `atlas-ai-governed-backlog.md` para backlog/personal source material.
- `surface-domain-catalog-integration-plan.md` segue como plano ativo ate virar
  runbook permanente.

Decisoes promovidas ou preservadas:

- Finance e review-only, sem market execution.
- Personal Development e privado, nao clinico e sem mutacao automatica.
- Surface picker deve separar domain/flow, provider, executor preference e
  safety/autonomy.

Risco principal: transformar material pessoal/sensivel em contexto de provider.

## Ordem De Menor Risco

Se a sessao principal quiser reduzir risco operacional, aplicar nesta ordem:

1. Redirects em docs ja marcados `deprecated`/`archived` dentro da KB, porque os
   substitutos ja estao declarados.
2. Arquivo de prompts e provider bootstrap, porque nao deveriam ser fonte
   primaria.
3. Promocao de paste image, porque e operacional, pequena e bem delimitada.
4. Consolidacao de telemetry/evidence, porque pode tocar contratos e comandos.
5. Promocao de Layer 0/glossary, porque afeta linguagem e autoridade.
6. Normalizacao de Finance/Personal Development, porque envolve safety,
   privacidade e docs concorrentes de outra sessao.
7. Qualquer delete candidate, apenas em PR separado.

## Sinais De Bloqueio

Interrompa a limpeza e peça revisao humana se encontrar:

- Documento legacy citado por migration, service, command, route ou teste como
  fonte operacional.
- Conteudo pessoal/sensivel sem classificacao de privacy.
- Conflito entre `atlas-ai-kernel-architecture.md` e `atlas-ai-master-architecture.md`.
- Benchmark Fair Claude misturado com Codex/Gemini/Atlas Decide.
- Doc novo de dominio alterado na mesma worktree por outra pessoa.
- Qualquer `.env`, backup de ambiente ou arquivo com credenciais entrando no
  inventario.

## Mapa De Substitutos Por Familia

Use este mapa quando um documento antigo parecer "verdadeiro" demais para ser
arquivado. Ele mostra onde a autoridade deve morar.

| Familia legacy | Fontes comuns | Autoridade/substituto | Observacao |
|---|---|---|---|
| Architecture mother docs | `Atlas_Documento_Mestre_v*`, `Atlas_AI_Documentacao_Final.md` | Futuro Layer 0 KB + `atlas-ai-canonical-architecture-index.md`, `atlas-ai-kernel-architecture.md`, `atlas-ai-master-architecture.md` | Nao copiar documento mestre inteiro. |
| Domain/Profile/Decide | resolver specs de Domain Profile, Decide, Programming | `atlas-ai-operating-system.md`, `atlas-ai-pipeline.md`, `atlas-ai-core-vs-domain.md`, `atlas-ai-kernel-architecture.md` | P0 ja parcialmente promovido. |
| Tool Runtime/Super Tools | `Atlas_AI_Harness_Super_Tool_Runtime_Core.md`, MCP expansion plan | `super-tool-runtime-core.md`, `programming-power-tools-catalog.md` | Tools pertencem ao Core, nao ao Forge sozinho. |
| Engineering Harness/Blueprint | harness runner plan, blueprint 7 itens | `engineering-blueprint*.md` | Preservar fonte historica, mas usar familia KB. |
| Memory/Open Brain | memory context core legacy, memoria semantica docs | `atlas-ai-memory-context-core-open-brain.md`, `memory-core-*.md`, `open-brain-context-injection.md` | Human notes exigem privacy/redaction. |
| CLI produto/final | CLI roadmap/plans/TUI docs | `atlas-cli-final-product.md`, `atlas-cli-release-checklist.md`, Fair Claude docs | Separar produto vivo de plano antigo. |
| Benchmark 5x/Fair Claude | Codex prompts e safety prompts | `atlas-cli-5x-claude-code-plan.md`, `atlas-cli-fair-claude-benchmark.md` | Prompt nao manda no protocolo. |
| Paste image/multimodal | paste-image setup/spec/plan | `atlas-ai-cli-multimodal.md`, `atlas-cli-final-product.md` | Setup vivo, plano historico. |
| Mobile/gateway/inbox | mobile operating model, push inbox implementation | `atlas-ai-mobile-surface-gateway.md` | Mobile e surface, nao domain paralelo. |
| Finance/Personal Development | new domain docs, personal gaps/sensor docs | `domains/finance.md`, `domains/personal-development.md`, `atlas-ai-governed-backlog.md` | Personal material nao vira provider context cru. |
| Provider bootstrap | `CLAUDE.md`, `AGENTS.md` | `START_HERE.md`, README e docs canonicos | Projection historica, nao fonte primaria. |

## O Que Nao Fazer

| Anti-acao | Por que e perigoso | Alternativa segura |
|---|---|---|
| Deletar `resolver-o-que-vale-a-pena` em lote. | O corpus contem P0/P1 e Layer 0 ainda nao promovidos. | Arquivar por familias com redirect. |
| Promover `Atlas_Documento_Mestre_v6.md` inteiro. | Cria doc mae gigante e pouco operacional. | Extrair Layer 0 enxuto e preservar source. |
| Usar Obsidian/AtlasVault como runtime source direto. | Pode vazar privacy e bypassar review. | Usar `obsidian-atlas-vault.md` e Memory privacy policy. |
| Tratar prompt de Codex/Claude como politica. | Prompts sao projections/contexto, nao contrato canonico. | Converter decisoes em KB/ADR. |
| Misturar Fair Claude com Atlas Supercharged. | Invalida benchmark. | Declarar trilha em todo artifact. |
| Mover arquivo antigo sem redirect. | Quebra links e contexto historico. | Header de redirect primeiro; move/delete so depois. |
| Atualizar docs mae junto com domain registry runtime. | Mistura governanca com implementacao e dificulta rollback. | PR documental separado. |
| Copiar conteudo pessoal para domain specs. | Risco de privacy/medical/financial unsafe context. | Sintetizar, redigir e citar source material. |

## Limpeza Executada Em 2026-05-05

Esta rodada executou apenas organizacao documental: headers de status, redirects
conceituais e navegacao. Nenhum arquivo foi deletado. Nenhum corpo legado foi
removido. Nenhum codigo/runtime foi alterado por esta limpeza.

| Path | Acao | Motivo | Doc canonica substituta |
|---|---|---|---|
| `docs/atlas-ai-telemetry.md` | superseded_source_material header | Telemetria operacional viva, mas arquitetura/evidence devem morar na KB. | `atlas-ai-telemetry-evidence-performance.md` + Kernel + Operating System |
| `docs/atlas-ai-aggregator-versions.md` | superseded_source_material header | Changelog util de aggregator, parte da familia telemetry/evidence. | `atlas-ai-telemetry-evidence-performance.md` |
| `docs/atlas-ai-performance-reports.md` | superseded_source_material header | Runbook util de reports, mas nao doc mae. | `atlas-ai-telemetry-evidence-performance.md` |
| `docs/atlas-ai-performance-engine-ops.md` | superseded_source_material header | Ops util do engine, consolidado sob telemetry/evidence. | `atlas-ai-telemetry-evidence-performance.md` |
| `docs/atlas-mac-agent.md` | superseded_source_material header | Agente local e surface/automation, nao arquitetura-mae. | `atlas-local-agent-surface.md` + Operating System |
| `docs/paste-image-setup.md` | superseded_source_material header | Setup vivo de operador; arquitetura esta no doc CLI multimodal. | `atlas-ai-cli-multimodal.md` + atlas-cli-final-product.md |
| `docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md` | superseded_source_material header | Spec historica util, substituida por setup/produto CLI. | paste-image-setup.md + atlas-cli-final-product.md |
| `docs/superpowers/plans/2026-05-04-atlas-dev-paste-image.md` | archived_quarantine header | Plano task-by-task volátil; delete futuro exige auditoria. | paste-image spec/setup |
| `docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md` | archived header | Plano MCP antigo; authority atual e Tool Runtime/Open Brain. | super-tool-runtime-core.md + programming-power-tools-catalog.md + open-brain-context-injection.md |
| `docs/atlas-cli-5x-codex-implementation-prompt.md` | archived header | Prompt de provider, nao protocolo. | atlas-cli-5x-claude-code-plan.md + atlas-cli-fair-claude-benchmark.md |
| `docs/atlas-cli-5x-codex-safety-context-prompt.md` | archived header | Prompt de provider, nao safety policy canonica. | Fair Claude docs + START_HERE.md |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-domain-profile-orchestration-architecture.md` | superseded_source_material header | P0 ja promovido parcialmente; impede competir com KB. | Operating System + Pipeline + Core-vs-Domain + Canonical Index |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-02-atlas-decide-final-architecture.md` | superseded_source_material header | P0 Decide; contratos vivos estao no Kernel/OS/Pipeline. | Kernel Architecture + Operating System + Pipeline |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-programming-product-architecture.md` | superseded_source_material header | P0 Programming; domain spec e runtime docs mandam agora. | domains/programming.md + Operating System + Engineering Blueprint + Super Tool Runtime |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-01-provider-choice-menu-design.md` | superseded_source_material header | Spec UX/policy historica, nao provider policy atual. | Kernel Architecture + Operating System + Fair Claude docs |
| `resolver-o-que-vale-a-pena/docs/plans/2026-05-01-provider-choice-app.md` | archived header | Plano app executado/volatil. | Kernel Architecture + Operating System |
| `resolver-o-que-vale-a-pena/docs/plans/2026-05-01-provider-choice-cli.md` | archived header | Plano CLI executado/volatil. | Kernel Architecture + Operating System |
| `resolver-o-que-vale-a-pena/docs/plans/2026-05-01-provider-choice-on-limit.md` | archived header | Plano de rate-limit historico; preservar edge cases. | Kernel Architecture + Operating System |
| `resolver-o-que-vale-a-pena/docs/atlas-ai-telemetry-quality-efficiency-implementation.md` | superseded_source_material header | Fonte de qualidade/custo ainda util. | `atlas-ai-telemetry-evidence-performance.md` + Kernel |
| `resolver-o-que-vale-a-pena/docs/atlas-ai-mobile-operating-model.md` | superseded_source_material header | Mobile e surface, nao dominio paralelo. | `atlas-ai-mobile-surface-gateway.md` + Operating System |
| `resolver-o-que-vale-a-pena/docs/mobile-gateway-push-inbox-implementation.md` | superseded_source_material header | Gateway/inbox mobile recebeu sintese canonica. | `atlas-ai-mobile-surface-gateway.md` |
| `resolver-o-que-vale-a-pena/docs/atlas-glossary.md` | superseded_source_material header | Layer 0/glossary foi promovido em forma controlada. | `atlas-ai-layer-0-glossary.md` + Canonical Index |
| `resolver-o-que-vale-a-pena/provider-bootstrap/CLAUDE.md` | archived_projection header | Projection gerada para provider, nao source of truth. | START_HERE.md + README.md + KB canonica |
| `resolver-o-que-vale-a-pena/provider-bootstrap/AGENTS.md` | archived_projection header | Projection gerada para agentes, nao source of truth. | START_HERE.md + README.md + KB canonica |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v6.md` | human_vault_only header | Constitucional/humano; promover somente excertos redigidos. | `atlas-ai-layer-0-glossary.md` + Canonical Index |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v5.md` | archived header | Versao antiga do documento mestre. | Atlas_Documento_Mestre_v6.md + `atlas-ai-layer-0-glossary.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v3.md` | archived header | Versao antiga do documento mestre. | Atlas_Documento_Mestre_v6.md + `atlas-ai-layer-0-glossary.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Documentacao_Final.md` | superseded_source_material header | Leis/identidade extraidas em forma enxuta. | `atlas-ai-layer-0-glossary.md` + Master Architecture + Vision |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Harness_v1.md` | superseded_source_material header | Harness antigo preservado como rationale; Blueprint/OS/Skills/Continuity mandam agora. | Engineering Blueprint + Operating System + Skill System + Continuity |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Harness_Super_Tool_Runtime_Core.md` | superseded_source_material header | P0 Super Tool Runtime reconciliado com runtime/tooling/evidence docs. | super-tool-runtime-core.md + programming-power-tools-catalog.md + Runtime Packets + Telemetry/Evidence |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Engineering_Harness_Runner_Plano_Profissional.md` | superseded_source_material header | Plano runner preservado para rollout historico; autoridade atual e Blueprint/runtime/evidence. | engineering-blueprint*.md + super-tool-runtime-core.md + Telemetry/Evidence |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Engineering_Blueprint_7_Itens_Plano_Implementacao.md` | archived header | Plano historico; familia Blueprint substitui. | engineering-blueprint*.md |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Memory_Context_Core_Open_Brain.md` | superseded_source_material header | Memory/Open Brain legado preservado para cronologia/backlog; KB memory family manda. | atlas-ai-memory-context-core-open-brain.md + memory/open-brain docs + Continuity |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Sessoes_Compactacao_Continuidade.md` | superseded_source_material header | Continuidade/session state foi promovido. | `atlas-ai-continuity-session-state.md` + open-brain-context-injection.md |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Skill_System_v1.md` | superseded_source_material header | Skills como capacidade governada foram promovidas. | `atlas-ai-skill-system.md` + Kernel Architecture |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md` | superseded_source_material header | Nomenclatura/CLI recebeu promocao seletiva. | `atlas-ai-layer-0-glossary.md` + atlas-cli-final-product.md |
| `resolver-o-que-vale-a-pena/root-md/Atlas_CLI_Caminho_Versao_Final.md` | archived header | Plano CLI antigo. | atlas-cli-final-product.md + release checklist |
| `resolver-o-que-vale-a-pena/root-md/Atlas_CLI_Plano_Execucao_Final.md` | archived header | Plano CLI antigo. | atlas-cli-final-product.md + release checklist |
| `resolver-o-que-vale-a-pena/root-md/Atlas_CLI_Produto_Final_Roadmap_7_Pontos.md` | superseded_source_material header | Roadmap CLI preservado; produto/5x governam e lacunas novas passam pelo governed backlog. | atlas-cli-final-product.md + 5x Claude plan + CLI Multimodal + Governed Backlog |
| `resolver-o-que-vale-a-pena/root-md/Atlas_CLI_TUI_Estado_da_Arte_Plano_Implementacao.md` | archived header | Plano TUI antigo. | atlas-cli-final-product.md |
| `resolver-o-que-vale-a-pena/root-md/Atlas_CLI_Packets_v1.md` | superseded_source_material header | Packets foram mapeados para contrato runtime/evidence atual. | `atlas-ai-runtime-packets.md` + Kernel Architecture + Super Tool Runtime |
| `resolver-o-que-vale-a-pena/root-md/Atlas_CLI_Bootstrap_Setup.md` | archived_quarantine header | Setup local antigo; delete futuro separado. | atlas-cli-final-product.md + release checklist |
| `resolver-o-que-vale-a-pena/root-md/Atlas_V1_Implementacao_Tecnica.md` | archived header | V1 tecnico historico. | Master Architecture + Operating System |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Plano_Implementacao_Profissional.md` | archived header | Plano macro antigo. | Master Architecture + Operating System |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Memoria_Semantica_Ativa_Compartilhada.md` | human_vault_only header | Memoria humana/pessoal; privacy gate. | Obsidian AtlasVault + Memory Core + Security/Privacy |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Memoria_Semantica_Ativa_Projeto_Funcional.md` | human_vault_only header | Memoria humana/pessoal; privacy gate. | Obsidian AtlasVault + Memory Core + Security/Privacy |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Captura_Pensamento_e_Notas_Vivas.md` | human_vault_only header | Human Knowledge Surface source. | Obsidian AtlasVault + `atlas-ai-governed-backlog.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Adendo_Sensor4_Atividade_Digital.md` | human_vault_only header | Sensor/digital activity sensivel. | domains/personal-development.md + Obsidian + Privacy |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Gaps_Achamos_Nao_Esquecer.md` | superseded_source_material header | Backlog valioso, nao spec runtime crua. | `atlas-ai-governed-backlog.md` + domains/personal-development.md + resolver audit |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Concorrente_Hermes_Agent.md` | human_vault_only header | Research concorrencial humano. | programming-power-tools-catalog.md + research note futuro, se necessario |
| `docs/engineering-knowledge-base/README.md` | navigation_update | Adicionou plano/relatorio de cleanup e familias legadas ao mapa de leitura. | KB canonica |
| `docs/engineering-knowledge-base/START_HERE.md` | navigation_update | Incluiu cleanup report na ordem de leitura antes do Operating System. | KB canonica |
| `docs/engineering-knowledge-base/archive/README.md` | archive_registry_created | Criou regra explicita para arquivo/source material sem mover docs grandes nem apagar conhecimento util. | Cleanup Report + Resolver Corpus Audit + KB canonica |
| `resolver-o-que-vale-a-pena/docs/mobile-gateway-push-inbox-implementation.md` | authority_warning_added | Corpo legado continha claim historico de fonte de verdade; header agora bloqueia leitura como autoridade. | `atlas-ai-mobile-surface-gateway.md` |
| `resolver-o-que-vale-a-pena/docs/atlas-ai-telemetry-quality-efficiency-implementation.md` | authority_warning_added | Checklist legado pedia aprovar como fonte de verdade; header agora marca obsoleto. | `atlas-ai-telemetry-evidence-performance.md` |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-domain-profile-orchestration-architecture.md` | authority_warning_added | Draft legado usa linguagem canonical; header aponta autoridade atual. | Operating System + Pipeline + Core-vs-Domain + Canonical Index |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-programming-product-architecture.md` | authority_warning_added | Draft legado usa linguagem canonical; header aponta autoridade atual. | Programming domain + Operating System + Engineering Blueprint + Super Tool Runtime |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-02-atlas-decide-final-architecture.md` | authority_warning_added | Spec Decide chama a si mesma de canonica; header agora aponta Kernel/OS/Pipeline como autoridade atual. | Kernel + Operating System + Pipeline |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Memory_Context_Core_Open_Brain.md` | authority_warning_added | Corpo legado contem claims de fonte de verdade; header agora limita leitura a source material. | Memory Core + Contracts + Open Brain Context + Continuity |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Skill_System_v1.md` | authority_warning_added | Corpo legado usa linguagem canonical; header agora aponta Skill System + Kernel. | `atlas-ai-skill-system.md` + Kernel |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Engineering_Harness_Runner_Plano_Profissional.md` | authority_warning_added | Plano de rollout contem source-of-truth historico; header agora aponta Engineering Blueprint + runtime/evidence. | Engineering Blueprint family + Super Tool Runtime + Telemetry/Evidence |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v6.md` | authority_warning_added | Documento constitucional deve ser preservado sem competir com o indice vivo. | Layer 0 Glossary + Canonical Index |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Memoria_Semantica_Ativa_Compartilhada.md` | authority_warning_added | Memoria humana contem claims de fonte de verdade; header reforca privacy/source-material boundary. | Obsidian AtlasVault + Memory Core + Security/Privacy |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Memoria_Semantica_Ativa_Projeto_Funcional.md` | authority_warning_added | Memoria humana contem claims de fonte de verdade; header reforca privacy/source-material boundary. | Obsidian AtlasVault + Memory Core + Security/Privacy |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Concorrente_Hermes_Agent.md` | authority_warning_added | Research competitivo usa exemplos de source of truth; header impede leitura como arquitetura Atlas. | Programming Power Tools Catalog + Governed Backlog |

## Promocoes Canonicas Executadas Em 2026-05-05

Esta segunda rodada fechou as maiores referencias a substitutos canonicos ainda
inexistentes criando docs pequenos, subordinados ao indice existente e com source
material preservado. A arquitetura-mae nao foi reescrita; cada promocao apenas
colocou lacunas P0/P1 em docs governados.

| Path | Acao | Motivo | Doc canonica substituta |
|---|---|---|---|
| `docs/engineering-knowledge-base/atlas-ai-layer-0-glossary.md` | canonical_promotion_created | Layer 0/glossary estava parcial e espalhado entre Documento Mestre, glossario antigo e ADR de nomenclatura. | N/A; novo doc canonico de Layer 0 |
| `docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md` | canonical_promotion_created | Continuidade, compactacao e handoff precisavam contrato pequeno alem de Open Brain injection. | N/A; novo doc canonico de continuity/session |
| `docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md` | canonical_promotion_created | Telemetry, aggregator_version, health, reports, custo e evidence estavam em docs soltos fora da KB. | N/A; novo doc canonico de telemetry/evidence/performance |
| `docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md` | canonical_promotion_created | Mobile gateway, push, inbox e domain catalog precisavam ficar como surface governada, nao domain paralelo. | N/A; novo doc canonico de mobile surface |
| `docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md` | canonical_promotion_created | Paste image/setup precisava autoridade canonica pequena para input multimodal CLI. | N/A; novo doc canonico CLI multimodal |
| `docs/engineering-knowledge-base/atlas-ai-skill-system.md` | canonical_promotion_created | Skills estavam como especificacao legada longa, sem contrato canonico pequeno na KB. | N/A; novo doc canonico de skill system |
| `docs/engineering-knowledge-base/atlas-ai-runtime-packets.md` | canonical_promotion_created | Packets legados precisavam mapear para envelopes, receipts, ledger e tool runtime atuais. | N/A; novo doc canonico de runtime packets |
| `docs/engineering-knowledge-base/atlas-local-agent-surface.md` | canonical_promotion_created | Mac Agent precisava ser classificado como local surface/automation, nao arquitetura-mae. | N/A; novo doc canonico de local agent surface |
| `docs/engineering-knowledge-base/atlas-ai-governed-backlog.md` | canonical_promotion_created | Backlog legado de alto ROI precisava governanca sem virar roadmap automatico. | N/A; novo doc canonico de governed backlog |
| `docs/engineering-knowledge-base/archive/README.md` | archive_registry_created | Arquivo documental precisava regra operacional propria para preservar legados em paths originais sem disputar autoridade. | Cleanup Report + Resolver Corpus Audit |
| `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md` | navigation_update | Registrou novos docs por autoridade e atualizou Layer 0 de parcial para ativo enxuto. | KB canonica |
| `docs/engineering-knowledge-base/README.md` | navigation_update | Incluiu os docs promovidos e o arquivo documental na lista de docs canonicos principais. | KB canonica |
| `docs/engineering-knowledge-base/START_HERE.md` | navigation_update | Inseriu os docs promovidos e o arquivo documental na ordem de leitura. | KB canonica |
| `resolver-o-que-vale-a-pena/docs/atlas-glossary.md` | superseded_source_material header | Termos revisados agora estao no Layer 0 canonico. | `atlas-ai-layer-0-glossary.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v6.md` | canonical replacement update | Continua human_vault_only, mas agora aponta para Layer 0 canonico real. | `atlas-ai-layer-0-glossary.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Documentacao_Final.md` | superseded_source_material header | Leis/identidade provider-safe foram sintetizadas no Layer 0 canonico. | `atlas-ai-layer-0-glossary.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md` | superseded_source_material header | Nomenclatura e termos canonicos foram promovidos. | `atlas-ai-layer-0-glossary.md` + `../atlas-cli-final-product.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Sessoes_Compactacao_Continuidade.md` | superseded_source_material header | Continuidade/session state foram promovidos. | `atlas-ai-continuity-session-state.md` |
| `docs/atlas-ai-telemetry.md` | superseded_source_material header | Runbook operacional preservado; autoridade consolidada agora esta na KB. | `atlas-ai-telemetry-evidence-performance.md` |
| `docs/atlas-ai-aggregator-versions.md` | superseded_source_material header | Changelog preservado; governanca de aggregator_version consolidada. | `atlas-ai-telemetry-evidence-performance.md` |
| `docs/atlas-ai-performance-reports.md` | superseded_source_material header | Runbook preservado; contrato de reports consolidado. | `atlas-ai-telemetry-evidence-performance.md` |
| `docs/atlas-ai-performance-engine-ops.md` | superseded_source_material header | Ops preservado; ordem/quality gates consolidados. | `atlas-ai-telemetry-evidence-performance.md` |
| `resolver-o-que-vale-a-pena/docs/atlas-ai-telemetry-quality-efficiency-implementation.md` | superseded_source_material header | Quality/cost/economics foram sintetizados na KB. | `atlas-ai-telemetry-evidence-performance.md` |
| `resolver-o-que-vale-a-pena/docs/atlas-ai-mobile-operating-model.md` | superseded_source_material header | Mobile operating model foi promovido como surface gateway. | `atlas-ai-mobile-surface-gateway.md` |
| `resolver-o-que-vale-a-pena/docs/mobile-gateway-push-inbox-implementation.md` | superseded_source_material header | Gateway/push/inbox foram promovidos em contrato canonico pequeno. | `atlas-ai-mobile-surface-gateway.md` |
| `docs/paste-image-setup.md` | superseded_source_material header | Setup preservado; autoridade arquitetural de multimodal esta na KB. | `atlas-ai-cli-multimodal.md` |
| `docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md` | canonical replacement update | Spec historica agora aponta para doc CLI multimodal real. | `atlas-ai-cli-multimodal.md` + `../paste-image-setup.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Skill_System_v1.md` | superseded_source_material header | Contrato de skills foi promovido em forma pequena e provider-neutral. | `atlas-ai-skill-system.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_CLI_Packets_v1.md` | superseded_source_material header | Packets foram mapeados para contratos atuais. | `atlas-ai-runtime-packets.md` |
| `docs/atlas-mac-agent.md` | superseded_source_material header | Runbook operacional preservado; autoridade de arquitetura local surface foi promovida. | `atlas-local-agent-surface.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Gaps_Achamos_Nao_Esquecer.md` | superseded_source_material header | Backlog legado agora tem governanca propria e nao manda no runtime. | `atlas-ai-governed-backlog.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v5.md` | canonical replacement update | Versao antiga agora aponta para Layer 0 canonico real. | `Atlas_Documento_Mestre_v6.md` + `atlas-ai-layer-0-glossary.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v3.md` | canonical replacement update | Versao antiga agora aponta para Layer 0 canonico real. | `Atlas_Documento_Mestre_v6.md` + `atlas-ai-layer-0-glossary.md` |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Harness_v1.md` | superseded_source_material header | Harness antigo fechado como fonte historica; nao compete com Blueprint/OS. | Engineering Blueprint + Operating System + Skill System + Continuity |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Harness_Super_Tool_Runtime_Core.md` | superseded_source_material header | Super Tool Runtime legado reconciliado com tooling/runtime/evidence canonicos. | super-tool-runtime-core.md + programming-power-tools-catalog.md + Runtime Packets + Telemetry/Evidence |
| `resolver-o-que-vale-a-pena/root-md/Atlas_Engineering_Harness_Runner_Plano_Profissional.md` | superseded_source_material header | Plano runner preservado como rollout source; DoD e operacao atuais estao na KB. | Engineering Blueprint family + Super Tool Runtime + Telemetry/Evidence |
| `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Memory_Context_Core_Open_Brain.md` | superseded_source_material header | Memory/Open Brain legado fechado como source material; autoridade esta na familia memory. | Memory Core + Memory Contracts + Open Brain Injection + Continuity |
| `resolver-o-que-vale-a-pena/root-md/Atlas_CLI_Produto_Final_Roadmap_7_Pontos.md` | superseded_source_material header | Roadmap CLI preservado; docs de produto/5x governam execucao e backlog governa lacunas. | atlas-cli-final-product.md + atlas-cli-5x-claude-code-plan.md + Governed Backlog |

## Recomendacao Final

Nao executar delete agora.

Aplicar limpeza em ondas:

1. Promocoes restantes de menor prioridade: provider-choice edge cases e
   pequenas lacunas futuras descobertas por diff semantico, apenas se houver
   lacuna real.
2. Redirects para legados ja substituidos.
3. Quarentena de planos executados e prompts.
4. Somente depois, avaliar arquivos em `archived_quarantine` com busca de
   links, codigo e historico git.
