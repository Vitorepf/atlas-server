---
id: legacy-documentation-cleanup-report
type: engineering_knowledge
title: Legacy Documentation Cleanup Report
status: draft
category: documentation-governance
priority: 90
summary: Inventario profissional de documentacao canonica, legacy, duplicada e orfa do Atlas, sem executar limpeza.
tags:
  - atlas
  - documentation
  - cleanup
  - legacy
---

# Legacy Documentation Cleanup Report

Data da auditoria: 2026-05-05.

Escopo auditado:

- `docs/`
- `docs/engineering-knowledge-base/`
- `resolver-o-que-vale-a-pena/`
- buscas locais por vault/obsidian/backup/archive dentro do repo

Observacao de concorrencia: durante a auditoria apareceram novos arquivos e
mudancas nao produzidas por este trabalho, incluindo docs de dominios e plano de
surface/domain catalog. Eles foram lidos apenas para classificacao e nao foram
alterados.

Restricoes respeitadas:

- Nenhum doc existente foi editado.
- Nenhum arquivo runtime, PHP, migration ou config foi editado.
- Nenhum arquivo foi apagado, movido ou arquivado.
- Este relatorio e o plano pareado sao os unicos arquivos criados.

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
2. Promover de forma controlada apenas lacunas de Layer 0, Atlas Mobile,
   provider-choice, mobile gateway e telemetria.
3. Arquivar com redirect os planos executados e prompts soltos.
4. Preservar documentos constitucionais e pessoais como `human_vault_only`.
5. Tratar `obsolete_delete_candidate` como candidato futuro, nunca deletar nesta
   sessao.

## Cobertura Do Inventario

Arquivos documentais considerados nesta revisao:

| Area | Volume observado | Tratamento neste relatorio |
|---|---:|---|
| `docs/engineering-knowledge-base` | 39 docs markdown apos a chegada de docs novos | Classificados por familia: canonicos, legados internos, novos dominios e planos de integracao. |
| `docs/` fora da KB | 14 docs markdown | Classificados como canonicos externos, promocoes candidatas, merges ou arquivos com redirect. |
| `resolver-o-que-vale-a-pena/docs` | 11 docs markdown | Classificados entre P0/P1/P2, promocao, merge ou archive. |
| `resolver-o-que-vale-a-pena/provider-bootstrap` | 2 docs markdown | Classificados como projections historicas, nao fonte primaria. |
| `resolver-o-que-vale-a-pena/root-md` | 27 docs markdown | Classificados por constituicao, harness, CLI, memoria, research e backlog. |

O inventario e intencionalmente conservador: qualquer documento com possivel
decisao ainda nao promovida recebeu `promote_to_kb` ou `merge_into_existing`, nao
`obsolete_delete_candidate`.

Linhas de classificacao registradas neste relatorio, incluindo documentos,
fontes novas concorrentes e itens conceituais:

| Classe | Linhas classificadas | Leitura executiva |
|---|---:|---|
| `keep_canonical` | 36 | Base canonica e docs novos que devem ser preservados. |
| `promote_to_kb` | 13 | Lacunas reais que precisam virar KB/ADR ou runbook. |
| `merge_into_existing` | 14 | Conteudo valioso que nao deve criar novo doc mae. |
| `archive_with_redirect` | 24 | Historico util, prompts, planos e specs antigas. |
| `obsolete_delete_candidate` | 2 | Candidatos futuros, nunca delete imediato. |
| `human_vault_only` | 6 | Material humano/pessoal/research a preservar com privacy. |

Esta contagem nao e uma lista de delete; ela serve para estimar trabalho. Um
mesmo arquivo pode aparecer em contexto principal e em area de atencao especial
quando isso ajuda a sessao principal a nao perder o motivo.

## Legenda

| Classe | Significado |
|---|---|
| `keep_canonical` | Ja e fonte canonica ou suporte operacional oficialmente listado. |
| `promote_to_kb` | Tem decisoes ainda nao consolidadas e deve virar doc canonico ou ADR. |
| `merge_into_existing` | Conteudo util, mas deve entrar em doc canonico existente. |
| `archive_with_redirect` | Deve ficar preservado com cabecalho apontando substituto; nao deve mandar. |
| `obsolete_delete_candidate` | Provavel lixo historico depois de redirect e janela de quarentena. |
| `human_vault_only` | Bom para Obsidian/AtlasVault/leitura humana, nao para runtime canonico. |

## Criterios De Decisao

Use estes criterios antes de mudar a classificacao de qualquer item.

| Pergunta | Sim | Nao |
|---|---|---|
| O documento e listado por `README.md`, `START_HERE.md` ou canonical index como autoridade? | `keep_canonical` | Continue avaliando. |
| O documento contem decisao ainda sem equivalente canonico? | `promote_to_kb` ou `merge_into_existing` | Continue avaliando. |
| O documento e plano de execucao, prompt, bootstrap de provider ou spec ja implementada? | `archive_with_redirect` | Continue avaliando. |
| O documento contem conteudo pessoal, constitucional, research ou notas longas humanas? | `human_vault_only` | Continue avaliando. |
| O documento tem substituto claro, baixo risco e zero referencias vivas apos redirect? | `obsolete_delete_candidate` futuro | Nao deletar. |

Regras duras:

- `obsolete_delete_candidate` nunca e acao imediata.
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
| `docs/engineering-knowledge-base/obsidian-atlas-vault.md` | `keep_canonical` | Contrato canonico do Human Knowledge Plane. | N/A | Critico: Obsidian pode virar fonte crua indevida. | Manter. |
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
| `docs/atlas-ai-telemetry.md` | `merge_into_existing` | Operacional e possivelmente ainda util, mas compete com telemetria/performance docs e resolver telemetry plan. | `atlas-ai-master-architecture.md`, `atlas-ai-operating-system.md`, futuro doc KB de telemetry/evidence | Alto: pode conter comandos/API ativos. | Auditar contra codigo e promover resumo para KB antes de arquivar. |
| `docs/atlas-ai-aggregator-versions.md` | `merge_into_existing` | Changelog tecnico de aggregator_version; deve morar perto de telemetria canonica. | Futuro doc KB `atlas-ai-telemetry-and-evidence.md` ou `atlas-ai-telemetry.md` promovido | Medio: perde historico de rollup. | Manter ate consolidar. |
| `docs/atlas-ai-performance-reports.md` | `merge_into_existing` | Runbook pequeno de relatorios; pertence a evidence/telemetry. | Futuro doc KB de telemetry/evidence | Medio. | Mesclar e depois redirect. |
| `docs/atlas-ai-performance-engine-ops.md` | `merge_into_existing` | Operacao diaria do performance engine; deve ser KB se runtime vivo. | Futuro doc KB de telemetry/evidence ou runbook de ops | Medio/alto. | Verificar uso; mesclar. |
| `docs/atlas-mac-agent.md` | `promote_to_kb` | Doc operacional de agente Mac; se mantido, precisa entrar na KB como surface/local automation. | Novo doc KB ou secao em `atlas-cli-final-product.md` | Medio: instala/valida agente local. | Promover ou arquivar se o agente estiver morto. |
| `docs/paste-image-setup.md` | `promote_to_kb` | Guia vivo para paste de imagem em terminal; especialmente relevante para `atlas dev`. | `atlas-ai-operating-system.md` + novo/atual doc CLI multimodal | Medio/alto: operador perde setup de terminal. | Promover para KB ou referenciar no README/START_HERE. |
| `docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md` | `archive_with_redirect` | Spec implementavel de paste image; valor historico e criterio de DoD. | `docs/paste-image-setup.md` + eventual doc KB multimodal/dev REPL | Medio: detalhes de design/testes podem ser uteis. | Arquivar com redirect depois de consolidar outcome. |
| `docs/superpowers/plans/2026-05-04-atlas-dev-paste-image.md` | `obsolete_delete_candidate` | Plano de execucao task-by-task, com commits/linhas volateis. | Spec paste image + setup final | Baixo/medio; util apenas para auditoria curta. | Quarentena e futuro delete candidate apos redirect. |
| `docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md` | `archive_with_redirect` | Plano de expansao MCP parcialmente substituido por Tool Runtime/Memory docs. | `super-tool-runtime-core.md`, `programming-power-tools-catalog.md`, Memory/Open Brain docs | Medio. | Arquivar com redirect. |
| `docs/atlas-cli-5x-codex-implementation-prompt.md` | `archive_with_redirect` | Prompt de implementacao, nao fonte canonica. | `atlas-cli-5x-claude-code-plan.md`, `atlas-cli-fair-claude-benchmark.md` | Baixo/medio: historico de decisao pode ajudar. | Arquivar com redirect. |
| `docs/atlas-cli-5x-codex-safety-context-prompt.md` | `archive_with_redirect` | Prompt de safety/contexto, nao doc mae. | `atlas-cli-5x-claude-code-plan.md`, `atlas-cli-fair-claude-benchmark.md`, `START_HERE.md` | Baixo/medio. | Arquivar com redirect. |

## Docs Novos E Mudancas Concorrentes Observadas

Estes itens apareceram na arvore durante a continuidade da auditoria. Como ha
mudancas de codigo e docs mae nao produzidas por este trabalho, a recomendacao e
trata-los como output de outra sessao e nao tentar normalizar tudo agora.

| Documento | Classe | Motivo | Substituto canonico | Risco de apagar | Acao recomendada |
|---|---|---|---|---|---|
| `docs/engineering-knowledge-base/domains/finance.md` | `keep_canonical` | Especifica Finance como dominio enterprise de analise/review, com proibicao explicita de execucao de mercado. | Futuro registro no canonical index/domain catalog | Alto: safety financeiro fica implicito. | Integrar ao canonical index e registry apenas na sessao de dominio. |
| `docs/engineering-knowledge-base/domains/personal-development.md` | `keep_canonical` | Define Personal Development como dominio privado, nao clinico, sem mutacoes automaticas. | Futuro registro no canonical index/domain catalog | Alto: risco de medical/therapy drift se removido. | Integrar com Human Knowledge Plane e privacy docs. |
| `docs/engineering-knowledge-base/surface-domain-catalog-integration-plan.md` | `promote_to_kb` | Mapeia hardcodes e consumo do catalogo por CLI/API/app/mobile/MCP. | Futuro runbook de surface/domain catalog | Medio/alto. | Manter draft; quando aceito, separar decisoes permanentes de plano de PR. |

Impacto no inventario: esses docs reduzem a urgencia de promover material legado
de Finance e Personal Development cru do `resolver-o-que-vale-a-pena`, mas nao
eliminam a necessidade de preservar as fontes historicas como `human_vault_only`
ou backlog.

## `resolver-o-que-vale-a-pena/docs`

| Documento | Classe | Motivo | Substituto canonico | Risco de apagar | Acao recomendada |
|---|---|---|---|---|---|
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-domain-profile-orchestration-architecture.md` | `merge_into_existing` | P0 ja reconhecido pelo resolver audit; conceitos promovidos parcialmente. | `atlas-ai-operating-system.md`, `atlas-ai-pipeline.md`, `atlas-ai-core-vs-domain.md` | Alto: contem fraseologia fonte de Profile/Flow. | Confirmar cobertura e arquivar com redirect. |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-02-atlas-decide-final-architecture.md` | `merge_into_existing` | P0; Decide como compilador operacional ja aparece na KB, mas spec detalhada ainda pode ter lacunas. | `atlas-ai-kernel-architecture.md`, `atlas-ai-operating-system.md`, `atlas-ai-pipeline.md` | Alto. | Mesclar lacunas e depois redirect. |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-03-atlas-programming-product-architecture.md` | `merge_into_existing` | P0; define Programming/Forge/Dev como dominio. | `atlas-ai-operating-system.md`, `engineering-blueprint.md`, `super-tool-runtime-core.md` | Alto. | Mesclar lacunas sobre `programming.forge` e arquivar. |
| `resolver-o-que-vale-a-pena/docs/atlas-ai-telemetry-quality-efficiency-implementation.md` | `promote_to_kb` | P1 no resolver audit; telemetria/economia/qualidade ainda nao tem doc KB proprio. | Futuro doc KB de telemetry/evidence | Alto: pode conter schema/metricas relevantes. | Promover versao canonica enxuta. |
| `resolver-o-que-vale-a-pena/docs/atlas-ai-mobile-operating-model.md` | `promote_to_kb` | Produto mobile nao parece coberto pela KB atual. | Novo doc KB de Atlas Mobile/domain surface | Medio/alto. | Promover se mobile continua no roadmap. |
| `resolver-o-que-vale-a-pena/docs/mobile-gateway-push-inbox-implementation.md` | `promote_to_kb` | Gateway/push/inbox tem arquitetura de produto e dados nao vista na KB. | Novo doc KB mobile gateway/inbox | Alto se feature viva. | Promover apos validar codigo/schema. |
| `resolver-o-que-vale-a-pena/docs/atlas-glossary.md` | `promote_to_kb` | Layer 0/glossary esta explicitamente "a promover" no canonical index. | Novo `atlas-ai-glossary.md` na KB ou ADR Layer 0 | Alto: termos legados podem divergir. | Promover glossario canonicalizado. |
| `resolver-o-que-vale-a-pena/docs/specs/2026-05-01-provider-choice-menu-design.md` | `merge_into_existing` | Provider choice e surface UX; possivel valor historico, mas nao deve mandar sozinho. | `atlas-ai-operating-system.md`, Fair Claude docs, futuro provider policy doc | Medio. | Mesclar invariantes vivas; arquivar. |
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
| `Atlas_Documento_Mestre_v6.md` | `human_vault_only` | Layer 0/constituicao mais recente; importante, mas nao deve competir cru com KB. | Futuro doc KB Layer 0/Constitution + `atlas-ai-canonical-architecture-index.md` | Critico: apagar perde lastro constitucional. | Preservar; promover recorte canonicalizado. |
| `Atlas_Documento_Mestre_v5.md` | `archive_with_redirect` | Versao anterior da constituicao; historico. | `Atlas_Documento_Mestre_v6.md` e futuro Layer 0 KB | Medio. | Arquivar com redirect para v6. |
| `Atlas_Documento_Mestre_v3.md` | `archive_with_redirect` | Versao antiga; historico. | `Atlas_Documento_Mestre_v6.md` e futuro Layer 0 KB | Baixo/medio. | Arquivar com redirect. |
| `Atlas_AI_Documentacao_Final.md` | `merge_into_existing` | Contem definicao/leis do Atlas AI, possivel Layer 0/2. | `atlas-ai-master-architecture.md`, `atlas-ai-vision.md`, futuro Constitution | Alto. | Extrair leis ainda ausentes. |
| `Atlas_AI_Harness_v1.md` | `merge_into_existing` | Conceitos do Harness absorvidos parcialmente por Engineering Blueprint/Operating System. | `engineering-blueprint.md`, `atlas-ai-operating-system.md` | Medio/alto. | Mesclar lacunas e arquivar. |
| `Atlas_AI_Harness_Super_Tool_Runtime_Core.md` | `merge_into_existing` | P0; substituido em grande parte por `super-tool-runtime-core.md`. | `super-tool-runtime-core.md`, `programming-power-tools-catalog.md` | Alto. | Comparar lacunas; depois redirect. |
| `Atlas_Engineering_Harness_Runner_Plano_Profissional.md` | `merge_into_existing` | P1; base historica do Harness Runner. | `engineering-blueprint*.md`, `super-tool-runtime-core.md` | Alto se houver DoD nao migrado. | Mesclar gaps; arquivar. |
| `Atlas_Engineering_Blueprint_7_Itens_Plano_Implementacao.md` | `archive_with_redirect` | Ja existe copia preservada em `archive/source-material`; familia KB atual substitui. | `engineering-blueprint*.md` | Baixo/medio. | Usar copia em archive como fonte; redirect. |
| `Atlas_AI_Memory_Context_Core_Open_Brain.md` | `merge_into_existing` | P1; KB tem doc canonico com mesmo nome/conteudo evoluido. | `atlas-ai-memory-context-core-open-brain.md`, Memory docs | Alto se apagar antes de comparar hashes/lacunas. | Comparar e arquivar com redirect. |
| `Atlas_AI_Sessoes_Compactacao_Continuidade.md` | `promote_to_kb` | Continuidade/sessoes/compactacao e `atlas continue` parecem subdocumentados na KB. | `open-brain-context-injection.md`, futuro doc continuity/session state | Alto. | Promover doc ou secao canonica. |
| `Atlas_AI_Skill_System_v1.md` | `promote_to_kb` | Skills como capacidade governada nao aparecem como doc proprio na KB. | `atlas-ai-kernel-architecture.md`, futuro `atlas-ai-skill-system.md` | Medio/alto. | Promover se skill system esta no roadmap. |
| `Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md` | `promote_to_kb` | Nomenclatura/glossario/comandos toca Layer 0 e CLI UX. | Futuro ADR/glossario + `atlas-cli-final-product.md` | Alto: nomes divergentes voltam. | Promover como ADR ou glossario. |
| `Atlas_CLI_Caminho_Versao_Final.md` | `archive_with_redirect` | Plano tecnico de versao final; substituido por produto final/release checklist. | `atlas-cli-final-product.md`, `atlas-cli-release-checklist.md` | Medio. | Extrair lacunas e arquivar. |
| `Atlas_CLI_Plano_Execucao_Final.md` | `archive_with_redirect` | Plano faseado antigo. | `atlas-cli-final-product.md`, `atlas-cli-release-checklist.md` | Medio. | Arquivar com redirect. |
| `Atlas_CLI_Produto_Final_Roadmap_7_Pontos.md` | `merge_into_existing` | P1; roadmap pode ter pontos ainda nao refletidos no CLI final. | `atlas-cli-final-product.md`, `atlas-cli-5x-claude-code-plan.md` | Medio/alto. | Comparar e mesclar lacunas. |
| `Atlas_CLI_TUI_Estado_da_Arte_Plano_Implementacao.md` | `archive_with_redirect` | Plano antigo de CLI/TUI. | `atlas-cli-final-product.md` | Medio. | Arquivar apos extrair comandos/UX vivos. |
| `Atlas_CLI_Packets_v1.md` | `promote_to_kb` | Contratos de packets/eventos podem ser runtime/evidence relevantes. | `atlas-ai-kernel-architecture.md`, `super-tool-runtime-core.md` | Alto se usados por código/traces. | Verificar codigo; promover contratos vivos. |
| `Atlas_CLI_Bootstrap_Setup.md` | `obsolete_delete_candidate` | Setup/scheduler antigo e provavel instrução local volátil. | `atlas-cli-final-product.md`, release docs | Baixo/medio; pode conter comandos antigos. | Quarentena; deletar apenas apos redirect e validacao. |
| `Atlas_V1_Implementacao_Tecnica.md` | `archive_with_redirect` | Sprint V1 antigo, grande e provavelmente obsoleto. | KB atual + docs mobile se feature viva | Medio. | Preservar historico; nao usar como fonte. |
| `Atlas_AI_Plano_Implementacao_Profissional.md` | `archive_with_redirect` | Plano macro antigo substituido por Master/Operating System. | `atlas-ai-master-architecture.md`, `atlas-ai-operating-system.md` | Medio. | Arquivar com redirect. |
| `Atlas_Memoria_Semantica_Ativa_Compartilhada.md` | `human_vault_only` | Documento de memoria humana/produto amplo; nao runtime canonico cru. | `obsidian-atlas-vault.md`, Memory docs, futuro Constitution | Alto como lastro conceitual. | Preservar em vault; promover apenas recortes. |
| `Atlas_Memoria_Semantica_Ativa_Projeto_Funcional.md` | `human_vault_only` | Projeto funcional de memoria pessoal. | Memory docs + Obsidian contract | Medio/alto. | Preservar; recortar se virar produto. |
| `Atlas_Captura_Pensamento_e_Notas_Vivas.md` | `human_vault_only` | Nota conceitual de captura/notes; pertence ao Human Knowledge Plane. | `obsidian-atlas-vault.md` + futuro personal knowledge doc | Medio/alto. | Preservar em vault. |
| `Atlas_Adendo_Sensor4_Atividade_Digital.md` | `human_vault_only` | Sensor pessoal/digital; domain Personal Development futuro. | Futuro domain spec personal_development | Medio/alto; privacy sensivel. | Preservar, nao injetar em provider cru. |
| `Atlas_Gaps_Achamos_Nao_Esquecer.md` | `promote_to_kb` | P2 no resolver audit, mas contem backlog de alto valor. | Futuro backlog/domain specs; `atlas-ai-resolver-corpus-audit.md` | Alto: apagar perde ideias relevantes. | Promover como backlog governado, nao spec runtime. |
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
| Obsidian/AtlasVault como conceito | `keep_canonical` | Ja tem contrato claro: Human Knowledge Plane, nao fonte primaria operacional. | `obsidian-atlas-vault.md` | Critico se remover contrato. | Manter e usar como filtro para docs pessoais. |
| Notas pessoais/constitucionais em `resolver-o-que-vale-a-pena/root-md` | `human_vault_only` | Conteudo forte, mas exige privacy, curadoria e promocao antes de runtime. | `obsidian-atlas-vault.md`, futuro Layer 0 KB | Alto. | Preservar; promover recortes. |
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
| P0 | Promover Layer 0/glossary | `Atlas_Documento_Mestre_v6.md`, `Atlas_AI_Documentacao_Final.md`, `atlas-glossary.md` | Constitution/glossary enxutos e versionados na KB. |
| P0 | Fechar continuity/session | `Atlas_AI_Sessoes_Compactacao_Continuidade.md` | Contrato canonico para `atlas continue`, compactacao e session state. |
| P1 | Consolidar telemetry/evidence | `docs/atlas-ai-telemetry.md`, performance docs, telemetry implementation legacy | Um unico doc KB para metricas, custo, evidence e quality efficiency. |
| P1 | Integrar docs novos de domain catalog | `domains/finance.md`, `domains/personal-development.md`, `surface-domain-catalog-integration-plan.md` | Domain specs aceitos e plano surface/domain convertido em decisao/runbook. |
| P1 | Promover paste image | `docs/paste-image-setup.md` e spec/plano superpowers | Setup vivo em lugar canonico; plano vira historico. |
| P2 | Arquivar prompts e planos executados | Codex prompts, provider bootstrap, plans superpowers | Menos ruido para IAs novas. |
| P2 | Quarentena delete candidates | paste-image implementation plan, bootstrap setup | Candidatos marcados, sem delete imediato. |

## Bundles De Promocao Recomendados

Estes bundles sao fatias naturais de trabalho para PRs futuros. Cada bundle deve
terminar com substitutos claros e source material preservado.

### Bundle A - Layer 0 Constitution E Glossary

Fontes:

- `resolver-o-que-vale-a-pena/root-md/Atlas_Documento_Mestre_v6.md`
- `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Documentacao_Final.md`
- `resolver-o-que-vale-a-pena/docs/atlas-glossary.md`
- `resolver-o-que-vale-a-pena/root-md/Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md`

Destino recomendado:

- Novo doc/ADR Layer 0 na KB.
- Possivel glossario canonico separado, se o conteudo ficar grande.

Decisoes a extrair:

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

Destino recomendado:

- Novo doc KB de continuity/session state ou secao clara em Open Brain.

Decisoes a extrair:

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

Destino recomendado:

- Novo doc KB de telemetry/evidence/performance, ou promocao de
  `docs/atlas-ai-telemetry.md` para a KB.

Decisoes a extrair:

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

Destino recomendado:

- Doc KB de mobile surface/gateway/inbox, subordinado ao Operating System.

Decisoes a extrair:

- Mobile como surface, nao domain separado por acidente.
- Push/inbox como delivery/interaction layer.
- Safety/autonomy antes de elevar runtime via mobile.

Risco principal: mobile criar policy paralela ao domain catalog.

### Bundle E - CLI Multimodal E Paste Image

Fontes:

- `docs/paste-image-setup.md`
- `docs/superpowers/specs/2026-05-04-atlas-dev-paste-image-design.md`
- `docs/superpowers/plans/2026-05-04-atlas-dev-paste-image.md`

Destino recomendado:

- Doc KB pequeno de CLI multimodal ou secao em `atlas-cli-final-product.md`.

Decisoes a extrair:

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

Destino recomendado:

- Layer 4 domain specs aceitos pelo canonical index.
- Runbook ou architecture doc para surfaces consumirem domain catalog.

Decisoes a extrair:

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
| Paste image/multimodal | paste-image setup/spec/plan | Futuro doc KB de CLI multimodal ou `atlas-cli-final-product.md` | Setup vivo, plano historico. |
| Mobile/gateway/inbox | mobile operating model, push inbox implementation | Futuro doc KB mobile surface/gateway | Mobile e surface, nao domain paralelo. |
| Finance/Personal Development | new domain docs, personal gaps/sensor docs | `domains/finance.md`, `domains/personal-development.md`, futuro index Layer 4 | Personal material nao vira provider context cru. |
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

## Recomendacao Final

Nao executar delete agora.

Aplicar limpeza em ondas:

1. Promocoes P0/P1 para lacunas reais: Layer 0/glossary, telemetry/evidence,
   continuity/session, mobile, provider choice e paste image.
2. Redirects para legados ja substituidos.
3. Quarentena de planos executados e prompts.
4. Somente depois, avaliar `obsolete_delete_candidate` com busca de links,
   codigo e historico git.
