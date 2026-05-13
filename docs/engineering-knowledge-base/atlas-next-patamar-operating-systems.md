---
id: atlas-next-patamar-operating-systems
type: engineering_knowledge
title: Atlas Next Patamar Operating Systems
status: future
category: architecture
priority: 100
summary: Manifesto canonico que fixa a hierarquia Sovereign OS, Epistemic OS e Cartographic Knowledge OS como patamares obrigatorios para o Atlas se autoevoluir por IA sem perder verdade, direcao ou legibilidade humana.
tags:
  - atlas
  - next-patamar
  - sovereign-os
  - epistemic-os
  - cartographic-os
  - self-evolution
capabilities:
  - next_patamar_operating_systems
  - sovereign_operating_system
  - epistemic_operating_system
  - cartographic_knowledge_os
  - ai_self_evolution_governance
decisions:
  - Sovereign OS, Epistemic OS e Cartographic Knowledge OS formam um conjunto indivisivel para o proximo patamar do Atlas.
  - Sovereign OS governa direcao; Epistemic OS governa verdade; Cartographic Knowledge OS torna ambos navegaveis.
  - Nenhuma autoevolucao enterprise deve ser considerada completa sem os tres patamares operando juntos.
  - Este manifesto deve aparecer nos pontos de entrada para que IAs futuras nao tratem esses sistemas como docs laterais.
maintenance:
  - Atualizar quando qualquer um dos tres sistemas mudar Definition of Done, ordem, autoridade ou relacao.
  - Manter este doc como indice; detalhes vivem nos docs filhos.
  - Rodar docs-health, sync e index-code depois de alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-sovereign-operating-system.md
  - docs/engineering-knowledge-base/atlas-epistemic-operating-system.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/START_HERE.md
  - docs/engineering-knowledge-base/README.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-next-patamar-operating-systems
graph_title: Atlas Next Patamar Operating Systems
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-ai-canonical-architecture-index
graph_status: future
graph_source: repo
owner: architecture
repo_paths:
  - docs/engineering-knowledge-base/atlas-next-patamar-operating-systems.md
allowed_changes:
  - Atualizar hierarquia, ordem de implementacao, gates e Definition of Done do conjunto.
forbidden_changes:
  - Remover qualquer um dos tres patamares do conjunto sem substituto canonico.
  - Declarar o proximo patamar completo sem Sovereign, Epistemic e Cartographic integrados.
depends_on:
  - atlas-ai-canonical-architecture-index
  - atlas-ai-knowledge-governance-system
flows_to:
  - atlas-sovereign-operating-system
  - atlas-epistemic-operating-system
  - atlas-cartographic-knowledge-os
  - atlas-ai-self-construction-os
unlocks:
  - governed-ai-self-evolution
  - human-readable-autonomous-atlas
  - purpose-aware-truth-aware-cartographic-atlas
governs:
  - next-patamar-architecture
  - ai-self-evolution-governance
evidence:
  - docs/engineering-knowledge-base/atlas-next-patamar-operating-systems.md
  - docs/engineering-knowledge-base/atlas-sovereign-operating-system.md
  - docs/engineering-knowledge-base/atlas-epistemic-operating-system.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - next-patamar
  - sovereign
  - epistemic
  - cartographic
ai_entrypoints:
  - Leia este manifesto antes de planejar os patamares Sovereign, Epistemic ou Cartographic.
  - Use este doc para checar se a evolucao proposta preserva direcao, verdade e legibilidade visual.
ai_usage_notes:
  - Se uma implementacao toca autoevolucao por IA, ela deve declarar qual dos tres patamares governa a decisao.
quality_gates:
  - php artisan atlas:engineering:knowledge docs-health --json
  - future: atlas sovereign evaluate --json
  - future: atlas epistemic health --json
  - future: atlas cartography graph validate --json
failure_modes:
  - Implementar Epistemic OS sem Sovereign OS e permitir verdade sem direcao.
  - Implementar Cartografia sem Epistemic OS e mostrar visual bonito sem confianca.
  - Implementar Sovereign OS sem Cartografia e deixar humano sem visao operacional.
  - Autoevolucao por IA ignorar qualquer um dos tres gates.
observability_signals:
  - docs-health status ok
  - future: sovereign gate status
  - future: epistemic health status
  - future: cartography completeness status
next_actions:
  - Criar APs de implementacao faseada para Sovereign, Epistemic e Cartographic em ordem canonica.
---
# Atlas Next Patamar Operating Systems

## Resumo

Este manifesto fixa os tres sistemas que o Atlas precisa para chegar ao proximo
patamar de autoevolucao por IA sem perder direcao, verdade ou legibilidade
humana.

```text
Sovereign OS    = decide direcao, proposito, autonomia e limites.
Epistemic OS    = decide verdade, confianca, evidencia, drift e permissao.
Cartographic OS = torna direcao e verdade navegaveis por humano e IA.
```

Os tres sao um conjunto. Implementar apenas um cria um Atlas desequilibrado.

## Papel no Atlas

O Atlas e construido, corrigido, gerenciado e organizado por IA. Por isso, a
camada documental precisa evoluir para sistemas operacionais de governanca:

- sem Sovereign OS, a IA pode construir algo verdadeiro na direcao errada;
- sem Epistemic OS, a IA pode construir com base em contexto falso ou stale;
- sem Cartographic Knowledge OS, o humano nao enxerga a cidade que a IA esta
  construindo.

## Onde Se Encaixa

```text
Layer -1  Thesis / Multiplicador / Antifragilidade
Layer 0.45 Sovereign OS
Layer 0.55 Epistemic OS
Layer 0.56 Cartographic Knowledge OS
Layer 0.8  Self-Construction OS
Layer 1+   Kernel / Runtime / Domains / Surfaces / Obras / Forge
```

## Contratos

1. Toda autoevolucao estrutural deve declarar seu gate soberano.
2. Toda permissao de escrita por IA deve ter base epistemica.
3. Toda area importante deve ser visivel ou marcada como lacuna na Cartografia.
4. Todo score precisa ser explicavel e auditavel.
5. Toda mudanca de autonomia, identidade, proposito ou self-modification exige
   Sovereign OS ou review humano enquanto ele nao existir.
6. Toda implementacao deve gerar evidence e atualizar read models.
7. Nenhum provider, agente ou runtime pode ampliar escopo por fora desses gates.

## Ordem Canonica

1. Epistemic OS MVP: Truth Graph, Evidence Binder, Confidence simples e drift basico.
2. Sovereign OS Phase 0: Constitution Kernel, Sovereign Decision Receipt e Human Sovereignty Gate.
3. Cartographic Knowledge OS MVP: Visual nodes, typed edges, semantic zoom e source inspector.
4. Epistemic OS Enterprise: Contradiction Register, Maturity Gates, Agent Permission Matrix e API.
5. Sovereign OS Enterprise: Autonomy Boundary, Priority Engine, Risk Model e Self-Modification Governor.
6. Cartographic OS Enterprise: Gear views, epistemic/sovereign overlays, completeness auditor e replay.

## Matriz De Conclusao

| Sistema | MVP pronto quando | Enterprise pronto quando |
|---|---|---|
| Sovereign OS | bloqueia mudanca critica sem Vitor e emite receipt soberano | governa prioridade, autonomia, risco, Obras e self-modification |
| Epistemic OS | responde confianca/freshness/evidence por capability | controla drift, contradicao, maturidade e permissoes de IA |
| Cartographic OS | mostra grafo real com zoom e source path | mostra engrenagens, overlays, replay e auditoria de completude |

## Fluxo

```text
Proposta de evolucao por IA
  -> Sovereign OS decide se deve existir e com quais limites
  -> Epistemic OS verifica verdade, evidencia, confianca e permissao
  -> Self-Construction OS executa pacote governado
  -> Cartographic Knowledge OS mostra estado, fluxo, risco e lacunas
  -> Evidence Ledger registra decisao, execucao e aprendizado
```

## Regras para IA

1. Se a pergunta e "devo fazer?", consulte Sovereign OS.
2. Se a pergunta e "isso e confiavel?", consulte Epistemic OS.
3. Se a pergunta e "onde fica e como funciona?", consulte Cartographic OS.
4. Se qualquer sistema ainda e `future`, aja pelo fallback conservador:
   proposta, AP, human review, docs-health e evidence.

## Escopo de Implementacao

Este manifesto nao implementa runtime. Ele define o pacote de arquitetura que
deve governar APs, specs, agents, Cartografia e Self-Construction.

## Dependencias

- `atlas-sovereign-operating-system`
- `atlas-epistemic-operating-system`
- `atlas-cartographic-knowledge-os`
- `atlas-ai-self-construction-os`
- `atlas-ai-knowledge-governance-system`

## Evidencias

Evidencia atual: docs canonicos e links no index/START_HERE/README.
Evidencia futura: APs, commands, services, tests, ledger, APIs e Cartografia.

## Riscos

- Tratar este manifesto como aspiracional e nao como contrato.
- Implementar visual antes de verdade.
- Implementar verdade antes de direcao.
- Dar autonomia a agentes antes de gates soberanos.

## Exemplos

Uma IA quer criar auto-refactor autonomo.

```text
Epistemic OS: verifica se contexto/codigo/testes estao confiaveis.
Sovereign OS: decide se autonomia e aceitavel e em qual limite.
Cartographic OS: mostra no mapa onde essa capacidade vive e o que toca.
Self-Construction OS: executa pacote se os gates permitirem.
```

## Proximas Acoes

1. Criar AP para Epistemic OS MVP.
2. Criar AP para Constitution Kernel e Sovereign Decision Receipt.
3. Criar AP para Visual Node + Semantic Zoom da Cartografia.
4. Adicionar overlays epistemico e soberano na Cartografia quando APIs existirem.
