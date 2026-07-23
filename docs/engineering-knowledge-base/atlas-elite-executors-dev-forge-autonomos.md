---
id: atlas-elite-executors-dev-forge-autonomos
type: engineering_knowledge
title: Atlas Elite Executors — Dev · Forge · Autônomos
status: active
category: programming
priority: 100
summary: "Contrato canônico dos três executores de engenharia elite do Atlas. Mesma barra mundial (L0–L5). Diferença = presença humana no loop de engenharia + escala/duração + origem do trabalho — nunca ranking de qualidade. NUNCA 'Dev = fast patch' · NUNCA 'Autônomos = qualidade pior'."
tags:
  - atlas-ai
  - elite-executors
  - atlas-dev
  - atlas-forge
  - autonomos
  - defatoracao-elite
  - aaeos
capabilities:
  - elite_executor_identity
  - dev_forge_autonomos_same_bar
  - difficulty_ladder_l0_l5
  - human_out_of_loop_default_autonomos
decisions:
  - Dev · Forge · Autônomos = três executores de engenharia elite (mesma barra mundial).
  - Diferença canônica = presença humana no loop de engenharia + escala/duração + origem do trabalho — não ambição nem qualidade.
  - Os três cobrem a ladder L0–L5 (do tipográfico ao frontier); o modo não limita a dificuldade máxima.
  - Atlas Dev = operador presente na intenção; cadência de sessão; superfície atlas dev/ask.
  - Atlas Forge = operador só no planejamento/soberania; obra longa multi-packet; SDD/continuidade.
  - Autônomos = zero humano no loop de engenharia (default 24/7); cérebro atlas:brain + músculo atlas:task; commit escopado na main.
  - Proibido chamar Dev de "fast path de qualidade inferior", "produto leve" ou "Forge mini".
  - Proibido chamar Autônomos de "lixo barato", "loop morto" ou qualidade abaixo de Dev/Forge.
  - ACDE/atlas:loop:* é MVP morto; operate path vivo = atlas:brain:* / atlas:task:*.
  - AAEOS governa a organização de engenharia; os três executores são modos sob AAEOS + Shared Engineering Spine (Nucleus), não OS gêmeos.
  - Done = evidence/certification; narrativa de agente não conta.
maintenance:
  - Atualize este doc antes de mudar identidade Dev/Forge/Autônomos ou a ladder L0–L5.
  - Dual-Core, glossary, Autonomos live, AAEOS e projections AGENTS/CLAUDE devem apontar para cá como owner da identidade dos três.
related_paths:
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-autonomos-live-system.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/evidence/2026-07-22-atlas-server-god-debulk/AAEOS-NUCLEUS-AGENTIC-ERA.md
  - docs/evidence/2026-07-22-atlas-server-god-debulk/ATLAS-NUCLEUS-GOD-SOTA.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-elite-executors-dev-forge-autonomos
graph_title: Atlas Elite Executors Dev Forge Autonomos
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
owner: programming
human_name: Atlas Elite Executors
canonical_name: Atlas Elite Executors — Dev · Forge · Autônomos
technical_name: atlas-elite-executors-dev-forge-autonomos
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
allowed_changes:
  - Refinar matriz de modos, ladder L0–L5, admission e exemplos sem baixar a barra de nenhum executor.
forbidden_changes:
  - Declarar Dev como patch fácil, produto leve ou qualidade inferior.
  - Declarar Autônomos como qualidade inferior a Dev/Forge.
  - Fundir os três em um único runtime sem modos distintos.
  - Tratar ACDE/loop morto como operate path vivo.
  - Limitar Autônomos a L0–L2 por política de "só trivial".
depends_on:
  - atlas-agentic-engineering-os
  - atlas-autonomos-live-system
  - atlas-dual-core-engineering-system
  - atlas-ai-knowledge-governance-system
flows_to:
  - atlas-programming-governance-system
  - atlas-forge-operating-system
governs:
  - elite-executor-identity
  - dev-forge-autonomos-same-bar
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este doc ANTES de explicar diferença entre Dev, Forge e Autônomos.
  - Use a matriz e a ladder L0–L5; não invente "Dev = leve".
---

# Atlas Elite Executors — Dev · Forge · Autônomos

## Resumo

Contrato de identidade dos **três executores de engenharia elite** do Atlas.

```text
Mesma barra mundial nos 3.
Diferença = presença humana no loop de engenharia + escala/duração + origem do trabalho.
NUNCA "Dev = patch fácil".
NUNCA "Autônomos = qualidade pior".
```

AAEOS (Agentic Engineering OS) **governa** a organização de engenharia.  
Dev · Forge · Autônomos são **modos de executor** sob esse governo + a Shared Engineering Spine (context · policy · decide · delivery · evidence · learning).  
Não são três cérebros paralelos e não são ranking de ambição.

Owner de detalhe Autônomos vivo: `atlas-autonomos-live-system.md`.  
Owner de boundary Dev↔Forge: `atlas-dual-core-engineering-system.md`.  
Visão AAEOS era agentica: `docs/evidence/2026-07-22-atlas-server-god-debulk/AAEOS-NUCLEUS-AGENTIC-ERA.md`.

## Lei pétrea (Defatoração Elite)

1. **bar(Dev) = bar(Forge) = bar(Autônomos)** em excelência de engenharia.  
2. O que muda é **quem está no loop**, **quanto tempo/escopo**, **de onde vem o trabalho**.  
3. Os três sobem a **ladder L0–L5**; o modo **não** é teto de dificuldade.  
4. **Done = evidence/certification**, não narrativa de agente.  
5. ACDE / `atlas:loop:*` = morto; vivo = `atlas:brain:*` / `atlas:task:*`.  

## Matriz canônica

| | **Dev** | **Forge** | **Autônomos** |
|---|---|---|---|
| **Humano no loop de eng** | Presente na **intenção** (dirige rumo vivo) | Só **plano / soberania** de risco | **Zero** (default 24/7 da era agentica) |
| **Escala / duração** | Sessão (minutos–horas) | Obra longa multi-packet (horas–dias+) | Fila contínua 24/7 |
| **Origem do trabalho** | “faz X” ao vivo / prompt / paste | Spec-mãe / Obra / packets | `atlas:brain:next` → `atlas:brain:seed` → `atlas:task next` |
| **Superfície típica** | `atlas dev` / ask / conversation programming | Forge Continuum / obra CLI / Code Forge surface | brain + task serving + scoped commit |
| **Dificuldade** | **L0–L5** | **L0–L5** | **L0–L5** |
| **Unidade típica** | task / WorkOrder / session run | Obra / Work Packet | task packet de serving |
| **Overhead de processo** | Proporcional (Operator Rebate: UX ágil, **não** barra menor) | Mais SDD, continuidade, multiagente | Seed-gate + leases + evidence; sem pair humano |
| **Não é** | Forge mini · patch-only · qualidade low | Gerente do Dev · “só multiagente cosmético” | Loop ACDE morto · qualidade inferior |

## Ladder de dificuldade (compartilhada)

| Nível | Exemplos | Quem pode |
|---|---|---|
| **L0** | typo, rename honesty, whitespace | Dev · Forge · Autônomos |
| **L1** | bug local + teste | Dev · Forge · Autônomos |
| **L2** | feature bounded 1 módulo | Dev · Forge · Autônomos |
| **L3** | cross-module + migration | Dev · Forge · Autônomos |
| **L4** | obra multi-dia multi-agent | Dev (escala→Forge) · Forge · Autônomos |
| **L5** | frontier (kernel, correctness hard, security, distributed) | Dev · Forge · Autônomos quando admission + evidence permitem |

**Invariante:** Autônomos **pode e deve** fazer L5 quando policy e prova permitem.  
Proibido política informal “Autônomos só L0–L2”.

## O que unifica os três (Shared Spine)

Um pipeline de engenharia — não três cópias:

```text
Intent/Domain → Context → Policy → Decide → ProviderPipe
  → Tools → Delivery → Gate → Evidence → Learning
```

Dev/Forge/Autônomos = **intake + cadência + presença humana + scheduling**.  
Delivery/evidence/learning devem convergir no mesmo Nucleus (ver `ATLAS-NUCLEUS-GOD-SOTA.md` N9/N10/N11/N12).

## Escolha de modo (roteamento)

```text
intenção interativa humana?     → DEV
obra longa multi-packet / SDD? → FORGE
fila / night / self-evolve?    → AUTÔNOMOS  (default out-of-loop)
```

Empates: Mission Control / AAEOS AdmissionPolicy (risk, L*, budget, SLA).  
Escalonamento Dev→Forge: packet auditável (ver Dual-Core).  
Forge não “rebaixa” para Dev como identidade; pode despachar tática curta sem subordinar identidade.

## Humano fora do loop (era agentica)

| Papel humano | Quando |
|---|---|
| **Intenção viva (Dev)** | Pairing de rumo, não microgerenciar cada diff |
| **Plano / soberania (Forge)** | Objetivos de obra e risco no planejamento |
| **Zero no loop (Autônomos)** | Default 24/7 de evolução e fila |
| **Halt-sovereign** | Só risco irreversível / legal / $ / wipe / objetivo de negócio ambíguo |

Verificação soberana sob demanda (terminal-first moat) ≠ “humano no loop de engenharia a cada passo”.

## Vocabulário

| Use | Não use |
|---|---|
| executor Dev / elite Dev | fast patch, produto leve, Mini Forge |
| executor Forge / obra | Dev pesado (como identidade) |
| Autônomos (brain+task) | Loop/ACDE como sistema vivo |
| mesma barra / L0–L5 | Dev < Forge < Autônomos em qualidade |
| Operator Rebate (UX Dev) | “barra de qualidade menor no Dev” |
| fast lane (legado = fluxo Dev) | “qualidade fast-path inferior” |

Termo histórico **“fast path”** / **“fast lane”** = sinônimo legado do **fluxo Dev** (operador presente, overhead proporcional). **Não** significa qualidade inferior. Preferir “executor Dev”.

## Anti-padrões

1. Tratar Dev como só typo/patch.  
2. Tratar Autônomos como farm de lixo.  
3. Três pipelines de evidence/provider.  
4. Fundir Dev e Forge num runtime único.  
5. Chamar Loop/ACDE de Autônomos vivo.  
6. Exigir review humano em todo L1 auto-com-prova.  
7. Limitar Autônomos a L0–L2.  

## Ponte para outros docs

| Doc | Papel |
|---|---|
| Este | **Owner** da identidade dos 3 executores |
| `atlas-dual-core-engineering-system.md` | Boundary Dev↔Forge, escalation |
| `atlas-autonomos-live-system.md` | Brain+task, keep-list, switches |
| `atlas-agentic-engineering-os.md` | OS organizacional pai |
| `atlas-canonical-glossary-and-naming.md` | Nomes curtos; aponta para cá |
| `atlas-programming-governance-system.md` | Gates universais; Dev projeta, não relaxa lei |

## Confirmação

Se uma IA ou doc disser “Dev é o caminho leve de qualidade menor” ou “Autônomos não faz engenharia difícil”, **este contrato vence**: mesma barra, modos diferentes, L0–L5 nos três.
