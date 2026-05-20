---
title: Atlas Forge Continuum OS Session Handoff 2026-05-16 · Parte 1
status: source_material
owner: atlas-code
updated_at: 2026-05-16
canonical: false
line_limit: 300
source_parent: docs/engineering-knowledge-base/atlas-forge-continuum-os-session-handoff-2026-05-16.md
note: source_material split from frozen handoff; evidence only, not a canonical module doc.
---
# Atlas Forge Continuum OS Session Handoff 2026-05-16 · Parte 1

## Resumo

Recorte de material de sessão: 1. Identidade da sessão ate 2. Meta do Rivals.

## Fonte

Arquivo pai: `docs/engineering-knowledge-base/atlas-forge-continuum-os-session-handoff-2026-05-16.md`.

## Conteudo Extraido

## 1. Identidade da sessão

Nome da sessão: **Atlas Forge Continuum OS**.

Missão principal: transformar o Atlas Code/Forge em um sistema de programação assistida por IA governado, auditável, local-first, forte o suficiente para executar Obras reais com provider real quando autorizado, e mensurável contra rivais externos por baterias justas.

O Forge Continuum OS dentro do Atlas é o eixo operacional que conecta:

- intenção humana;
- Obra;
- Work Item;
- intake;
- contexto;
- plano;
- topology/provider decision;
- execução governada;
- review/repair;
- evidência;
- completion gate;
- Rivals/evaluation;
- aprendizado posterior para Atlas Decide.

Problema resolvido: antes, o Atlas tinha muitos blocos fortes isolados, mas o operador humano ficava perdido e a arquitetura podia parecer “read-model certificado” sem provar execução real. O Forge Continuum OS cria uma cadeia única com receipts, blockers, audit, replay, UI e evidência, para que cada avanço seja verificável e não dependa de confiança verbal em uma IA.

Problema de produto: o humano precisa saber, a qualquer momento, “o que está acontecendo”, “por que parou”, “qual é o próximo botão seguro”, “se gastou token”, “se provider externo foi chamado”, “se pode aprovar”, “onde está a evidência”, e “se o Atlas está de fato melhorando em relação a Claude/Codex/Gemini/provider puro”.

## 2. Meta do Rivals

Neste contexto, **Rivals** é o harness de avaliação comparativa do Atlas Forge contra outros competidores ou contra outros modos do próprio Atlas. Ele não é um sistema de roteamento de provider. Ele não escolhe modelo certo. Ele mede desempenho real e produz evidência consultiva.

Sistemas/produtos/comportamentos a comparar:

- Atlas Forge vs Claude Code;
- Atlas Forge vs Codex;
- Atlas Forge vs Gemini;
- Claude Code vs Codex;
- Sonnet vs Opus;
- Atlas Forge modo justo vs Atlas Forge modo poder total;
- Atlas Forge usando Sonnet vs Atlas Forge usando Codex;
- category batteries: frontend/UI, backend/lógica, bugfix realista, refactor, test design, arquitetura, integração/performance, planejamento;
- prompt modes: `spec-perfect`, `human-normal`, `messy-real`, `enterprise-change`.

Critérios de comparação:

- resultado funcional;
- aderência ao escopo;
- qualidade de patch;
- testes e evidência;
- governança;
- custo;
- tempo;
- dirty workspace before/after;
- replay;
- completion safety;
- confiança da bateria;
- performance por categoria;
- performance por dificuldade L1-L5.

Métricas de sucesso:

- bateria real com provider real, não apenas local fake;
- run id persistido;
- 40 casos release executados;
- 8 categorias com 5 dificuldades cada;
- evidência por caso: manifest, scorecard, atlas/rival receipts, atlas/rival patches, atlas/rival test logs, workspace hashes, difficulty band;
- replay válido;
- matrix evidence lock OK;
- no hard failures de harness;
- no suspicious cases não triados;
- score determinístico;
- report v3/vNext legível;
- claim externo continua bloqueado por design até aprovação humana;
- `provider_performance_signal` advisory-only, sem alterar topology.

O que precisa existir para dizer que o Atlas venceu ou chegou no nível esperado:

- score do Atlas maior que rival por margem acima do tie threshold em bateria confiável;
- ou vitória por categoria/dificuldade claramente explicada;
- nenhum caso inválido contaminando o resultado;
- replay e evidence pack íntegros;
- custo/tempo medidos;
- conclusão humana possível: “Atlas foi melhor em X, pior em Y, empatado em Z”;
- sem usar Rivals como substituto do Atlas Decide.

Regra central de fronteira:

```text
Rivals emits measured evidence; Atlas Decide decides model routing.
```

Flags obrigatórias em qualquer projection Rivals -> Decide:

```json
{
  "advisory_only": true,
  "should_update_provider_topology": false,
  "never_changes_atlas_decide_topology": true,
  "owner_of_model_routing": "atlas_decide",
  "routing_effect": "none"
}
```
