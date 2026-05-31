---
id: atlas-aaeos-evolution-backlog-index
title: AAEOS Evolution Backlog — Index (what the loop implements)
doc_schema: atlas_canonical_module_doc.v1
status: proposal
authority_class: backlog
summary: Index of the decompose-ready N×M leap backlogs generated for the autonomous loop. Each linked doc holds atomic single-decision new-class pure-logic slices (one class, one method, zero deps, no I/O, paired meaningful test) that the loop implements without problems. All slices were mined from canonical AAEOS docs/code by multi-agent workflows and adversarially filtered against scaffold/duplicate/complexity. Run each doc through plan-execution; the slices are dependency-free roots, parallel-safe.
owner: operator (Vitor)
risk_level: R1
forbidden_changes:
  - Do NOT treat as canonical authority. authority_class=backlog / status=proposal.
  - This is an index; the slice specs live in the linked docs.
---

## 1. Proposito

Mapa unico das listas que o loop autonomo do AAEOS implementa. Cada doc abaixo e decompose-ready (planner=sliced, allowed_files derivado, zero colisao com arquivos existentes). Cada fatia cria UMA classe nova de pura-logica (um metodo, zero dependencias, sem I/O) que COMPUTA uma decisao real dos inputs, com teste pareado de asercao significativa — atomica o bastante para o loop one-shot.

## 2. Backlogs (o loop le estes)

| Doc | Slices loop-ready | Areas / multiplicador N×M |
| --- | --- | --- |
| `atlas-aaeos-high-value-evolution-backlog.md` | 27 (S49–S75) | memoria, contexto, specs, qualidade, compactacao, departamentos, cognicao/imune, implementation-state |
| `atlas-aaeos-forge-dev-leap-backlog.md` | 17 (S101–S117) | Forge, Dev runtime, Evidence, Provider-routing, Gates, Self-Construction, Mission (+8 deferidos secao 11) |
| `atlas-aaeos-cognitive-plane-leap-backlog.md` | 33 (S126–S158) | ACOS AUCRI/ACQCG/APCR/AEMOR, Compounding-L7, AWIS, Surfaces, Open Brain, Decision-Receipt |
| `atlas-aaeos-reliability-testos-leap-backlog.md` | 20 (S161–S180) | reliability/repair, Test-OS, integration/merge, SDD phase-gates, error-evidence (+1 deferido) |

**Total loop-ready: 97 slices atomicos** (+ ~9 deferidos para decomposicao mais fina, em secoes 11).

## 3. Como o loop consome

Cada doc, via Pilar 1 Plan Execution (provedor confiavel; novo-arquivo = caminho que entrega):

```
php artisan atlas:plan-execution:run --doc=docs/engineering-knowledge-base/<doc>.md --provider=minimax_m27_cli --execute
```

As fatias sao raizes sem dependencias — podem ser executadas em qualquer ordem / em paralelo (fleet). Cada doc esta ordenada por multiplicador (maiores primeiro) ou simplest-first conforme o caso.

## 4. Disciplina

- Toda fatia cria classe NOVA — nunca edita codigo existente (o runtime do loop e evoluido separadamente).
- Pura: zero deps de construtor, sem I/O. Computa dos inputs via regras reais; nunca dado estatico (scaffold proibido).
- Teste pareado com asercoes computadas significativas (nao shape-only).
- Cada fatia traça a uma linha real de doc/codigo (src=...).
