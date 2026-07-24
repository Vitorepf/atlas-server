---
id: atlas-full-pass-hygiene-areas
type: engineering_knowledge
title: Atlas Full-Pass Hygiene — Áreas de atuação (lista curta canônica)
status: active
category: programming
priority: 100
summary: "Lista curta das áreas em que um agente (sobretudo Autônomos) deve atuar numa limpeza full-pass: refatoração, defatoração, otimização, reaproveitamento, padronização, arquitetura e correlatas. Cadência = semanal / mensal / pós-obra grande. Full-pass = percorrer TODAS as áreas, não só uma. Operate path = atlas:brain + atlas:task. Anti-Goodhart. Mesma barra elite L0–L5."
tags:
  - autonomos
  - hygiene
  - full-pass
  - refactor
  - defatoracao
  - optimization
  - reuse
  - standardization
  - architecture
  - aaeos
  - quality
  - petreo
capabilities:
  - full_pass_hygiene_areas
  - periodic_code_quality_sweep
  - autonomos_hygiene_mandate
  - agent_action_area_catalog
decisions:
  - "Full-pass hygiene = percorrer a lista curta INTEIRA (todas as áreas), não cherry-pick de uma frente."
  - "Cadências canônicas: SEMANAL (delta do hot path), MENSAL (corpus amplo), PÓS-OBRA-GRANDE (obrigatório na zona tocada + amostragem irmãos)."
  - "Autônomos executa full-pass de forma autônoma via standing mandate + brain origin + task muscle; operador só soberania/H1–H7."
  - "Área ≠ tarefa. Área = papel de atuação. Dentro de cada área o agente origin a tasks concretas com prova."
  - "Defatoração ≠ refatoração: defator colapsa camadas/OS/modos gêmeos; refator reorganiza forma preservando comportamento."
  - "Done de full-pass = scoreboard por área (done|partial|n/a|blocked) + evidence + gates do pacote; narrativa de agente não conta."
  - "Anti-Goodhart: LOC↓, file-count↓, pass++, coverage teatro NÃO fecham área. Fecha capability real + proof."
  - "Proibido feature de produto dentro de full-pass hygiene (exceto se a 'feature' for tooling de hygiene com mandate)."
  - "Proibido delete por prefixo; keep-lists vivas; 0 refs vivas = prova de morte; teste-espelho não é vida."
  - "Ordem de execução preferida: prova baseline → eliminate/quarantine → split/density → rehome/ownership → fuse/reuse → padronização → lógica/contratos → arquitetura → otimização medida → docs/DX → gates finais."
  - "Repos: atlas-server + atlas-native (+ atlas-app legado se ainda tocado). Mesma lista; vocabulário de superfície adapta (PHP vs Swift)."
maintenance:
  - Atualizar a lista curta só com decisão de operador; não inflar para 100 itens de checklist tático.
  - Manter alinhado a elite-executors, autonomos live, self-evolution quality loop, nucleus GOD-SOTA, god-debulk laws.
  - Se o brain originar hygiene, o packet DEVE citar area_ids desta lista.
related_paths:
  - docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
  - docs/engineering-knowledge-base/atlas-autonomos-live-system.md
  - docs/engineering-knowledge-base/atlas-autonomos-self-evolution-quality-loop.md
  - docs/engineering-knowledge-base/atlas-agent-qos-excellence-ceiling.md
  - docs/evidence/2026-07-22-atlas-server-god-debulk/ATLAS-NUCLEUS-GOD-SOTA.md
  - docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-COMPLETE.md
  - docs/superpowers/plans/2026-07-23-nucleo-essencial-MASTER.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-full-pass-hygiene-areas
graph_title: Full-Pass Hygiene Areas
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-autonomos-live-system
graph_status: active
graph_source: repo
owner: programming
canonical_source: docs/engineering-knowledge-base/atlas-full-pass-hygiene-areas.md
allowed_changes:
  - Refinar done_criteria e ordem de pass com evidência de runtime.
  - Ajustar cadência se o operador redefinir ritmo.
forbidden_changes:
  - Transformar este doc em checklist tático de 200 linhas de tarefas.
  - Exigir operador técnico no loop de hygiene (só soberania).
  - Fechar full-pass por proxy (LOC, vanity pass, file-count).
  - Reviver ACDE/atlas:loop:* como executor do full-pass.
depends_on:
  - atlas-elite-executors-dev-forge-autonomos
  - atlas-autonomos-live-system
  - atlas-autonomos-self-evolution-quality-loop
governs:
  - periodic_hygiene_full_pass
  - autonomos_hygiene_area_selection
  - post_large_implementation_cleanup
---

# Atlas Full-Pass Hygiene — Áreas de atuação (lista curta)

## 0. O que isto é (e o que não é)

**Isto é** o catálogo canônico das **áreas de atuação** de um agente quando a missão é:

> Depois de 1 semana / 1 mês / uma implementação grande — **passar uma limpa**: otimizar ao máximo, refatorar, defatorar, simplificar, reaproveitar, padronizar, estruturar. **Tudo o que está na lista curta.**

**Isto não é** um inventário de mil micro-tarefas.  
Área = **papel**. Task = **trabalho originado dentro do papel**, com prova.

**Executor default:** Autônomos (`atlas:brain:*` → `atlas:task:*`), mesma barra elite L0–L5 que Dev/Forge.  
**Humano:** soberania / mandate / H1–H7 — não revisor técnico de cada rename.

---

## 1. Intuito (pétreo)

1. Qualidade de código e de sistema **compõe sozinha** no tempo.  
2. Full-pass = **todas as áreas** da lista curta são visitadas (status `done` | `partial` | `n/a` | `blocked` — nunca “esquecida”).  
3. `n/a` e `blocked` exigem **motivo + evidência** (não desculpa vazia).  
4. Sem feature de produto no full-pass (salvo tooling de hygiene autorizado).  
5. Comportamento preservado por default; mudança de comportamento = **não** é “só hygiene” (vira change com mandate).

---

## 2. Lista curta canônica (30 áreas)

Use os `area_id` em packets, LEDGER e DEBTS.

### 2.1 Núcleo de forma e identidade

| # | area_id | Nome | Atuação do agente | Done mínimo da área |
|---:|---|---|---|---|
| 1 | `refactor` | **Refatoração** | Reorganiza forma sem mudar comportamento (split, extract, rename, thin CLI/HTTP). | Diffs escopados + testes/golden do pacote verdes. |
| 2 | `defactor` | **Defatoração** | Colapsa camadas/OS/modos/gêmeos em um spine e uma barra (anti twin-OS). | 0 capability com 2+ entrypoints públicos não-alias na zona; ownership único. |
| 3 | `optimize` | **Otimização** | Densidade, hops, hot path, I/O, custo de contexto — **medido**. | Métrica antes/depois + sem regressão de prova. |
| 4 | `reuse` | **Reaproveitamento** | Uma implementação canônica (traits, kernel store, Core, helpers com 2º uso). | Duplicata removida **ou** justificada com 2º consumidor real. |
| 5 | `standardize` | **Padronização** | Vocabulário, sufixos, famílias de método, layout de arquivo, estilo, APIs. | Zona alinhada ao canon; linter/pint/estilo verde no pacote. |
| 6 | `architecture` | **Estruturação de arquitetura** | Ownership, órgãos, anéis, boundaries, pipeline load-bearing, CODEMAP. | CODEMAP/OWNERSHIP da zona atualizados; hops ≤3 no hot path tocado. |

### 2.2 Massa e forma física

| # | area_id | Nome | Atuação do agente | Done mínimo da área |
|---:|---|---|---|---|
| 7 | `eliminate` | **Eliminação** | Delete com prova (morto, theater, deps, rotas, testes-espelho). | 0 refs **vivas**; restore-fixpoint limpo; boot/commands delta ok. |
| 8 | `quarantine` | **Quarentena** | Congela legado fora do operate-path (archive, catálogo frozen). | Path legado não é entrypoint operate; catálogo/LEDGER atualizado. |
| 9 | `density` | **Godfile / densidade** | SPLIT monólitos; tetos LOC; façade fina; **split before fuse**. | Nenhum novo monstro criado; alvos da zona ≤ teto **ou** floor documentado. |
| 10 | `fuse` | **Fundição** | Junta peels/hosts/pipes same-concern; reduz hops sem monstro. | Hops↓ ou peels↓ com golden/behavior intacto. |
| 11 | `rehome` | **Rehome / pastas** | Move para owner certo; root limpa; namespace = papel. | Zero root single órfão na zona; imports saneados. |
| 12 | `decouple` | **Desacoplamento** | Quebra ciclos; policy ≠ I/O ≠ projection; deps one-way. | Sem back-ref estrutural novo; boundary documentada. |

### 2.3 Semântica e contrato

| # | area_id | Nome | Atuação do agente | Done mínimo da área |
|---:|---|---|---|---|
| 13 | `honesty` | **Honestidade semântica** | Nome = papel; status/UI não mentem; fail-closed honesto. | 0 nome mentiroso na zona; erros/status honestos. |
| 14 | `contracts` | **Contratos e APIs** | Unifica entrypoints, schemas, receipts, versionamento. | 1 façade pública por capability (aliases ok se documentados). |
| 15 | `simplify` | **Simplificação de lógica** | Menos branch, menos indirection falsa, menos over-engineering. | Complexidade local ↓ **ou** ramos mortos removidos com prova. |
| 16 | `invariants` | **Idempotência e invariantes** | Regras estáveis, asserts, keep-lists, caps. | Invariantes da zona explícitas e testadas/assertadas. |

### 2.4 Prova e qualidade

| # | area_id | Nome | Atuação do agente | Done mínimo da área |
|---:|---|---|---|---|
| 17 | `golden` | **Caracterização / golden** | Congela comportamento antes de mexer. | Golden/characterization do pacote existe e passa. |
| 18 | `gates` | **Gates e verificação** | Testes do pacote, smoke, density guard, bind-to-missing, boot. | Gates do pacote verdes; sem wipe/suíte destrutiva. |
| 19 | `reliability` | **Confiabilidade / segurança** | Secrets, authority, lineage, rollback, error types. | Nenhum secret novo; failure modes tipados na zona. |
| 20 | `anti_goodhart` | **Anti-Goodhart** | Recusa fechar por proxy; exige capability real. | Scoreboard sem vanity; métricas proxy marcadas como não-done. |

### 2.5 Navegação, docs e obra

| # | area_id | Nome | Atuação do agente | Done mínimo da área |
|---:|---|---|---|---|
| 21 | `navigability` | **Navegabilidade agent-optimal** | Achar ≤3 hops; CODEMAP; cabe no contexto. | CODEMAP da zona aponta Type.method/host. |
| 22 | `dx` | **DX (humano + agente)** | Templates, DEBTS, LEDGER, anti-colisão, commits escopados. | LEDGER do pass com área→resultado; commits escopados. |
| 23 | `docs_map` | **Documentação como mapa** | Índice vivo; archive isolado; LEGADO marcado. | Docs da zona não mentem operate-path. |
| 24 | `governance` | **Governança de obra** | Ordem de WAVEs, blueprints, floors operator-present, halt. | Pass seguiu ordem; floors sagrados não forçados. |

### 2.6 Especializações de sistema

| # | area_id | Nome | Atuação do agente | Done mínimo da área |
|---:|---|---|---|---|
| 25 | `unify_pipes` | **Unificação de pipes** | Provider / runtime / ledger / execute → canônico. | Drift de pipe na zona resolvido ou DEBT explícito. |
| 26 | `world_model` | **Fusão de world-model** | Reusa extractors/edges; sem stack paralelo morto. | Nada dead-fed ativado como “vivo”. |
| 27 | `surface_std` | **Padronização de superfície** | CLI / HTTP / MCP / UI com mesmo vocabulário. | Superfícies da zona usam famílias canônicas. |
| 28 | `platform_migrate` | **Migração de plataforma** | Cross-stack só com contrato; sem big-bang cego. | `n/a` se não houver migração; senão contrato estável. |
| 29 | `operate_vs_legacy` | **Operate-path vs legado** | Vivo serve; morto/tranca o resto. | Operate-path da zona sem entrypoint legado. |
| 30 | `agent_qos` | **Excellence / QoS de agente** | Medição honesta do loop; curriculum; anti-teto. | Se tocado: medida honesta; sem falso-seguro. |

### 2.7 Lista só nomes (menu de atuação)

1. Refatoração  
2. Defatoração  
3. Otimização  
4. Reaproveitamento  
5. Padronização  
6. Estruturação de arquitetura  
7. Eliminação  
8. Quarentena  
9. Godfile / densidade  
10. Fundição  
11. Rehome / pastas  
12. Desacoplamento  
13. Honestidade semântica  
14. Contratos e APIs  
15. Simplificação de lógica  
16. Idempotência e invariantes  
17. Caracterização / golden  
18. Gates e verificação  
19. Confiabilidade / segurança  
20. Anti-Goodhart  
21. Navegabilidade agent-optimal  
22. DX  
23. Documentação como mapa  
24. Governança de obra  
25. Unificação de pipes  
26. Fusão de world-model  
27. Padronização de superfície  
28. Migração de plataforma  
29. Operate-path vs legado  
30. Excellence / QoS de agente  

---

## 3. Cadências (quando rodar o full-pass)

| Cadência | Trigger | Escopo | Profundidade |
|---|---|---|---|
| **SEMANAL** | timer / standing mandate | Hot paths da semana + zona suja (DEBTS) | Full lista, mas `n/a` rápido onde não houve delta |
| **MENSAL** | timer / operator mandate | Corpus amplo (server e/ou native) | Full lista com amostragem por owner/WAVE |
| **PÓS-OBRA-GRANDE** | logo após feat/refactor massivo | **Zona tocada obrigatória** + irmãos de ownership | Full lista sem `n/a` preguiçoso na zona tocada |
| **CONTÍNUO (Autônomos)** | brain origin quando fila “reativa” seca | Próximo debt de hygiene rankeado | Ainda **visita scoreboard de todas as áreas** do ciclo |

Regra: full-pass **não** é “só eliminate” nem “só pint”.  
É **scoreboard 1→30** preenchido no LEDGER do ciclo.

---

## 4. Ordem preferida de execução (dentro de um full-pass)

Autônomo (e qualquer agente) deve preferir esta ordem — evita fuse cego e delete suicida:

```
0. baseline + scoreboard vazio (area_id → pending)
1. golden / characterization da zona
2. eliminate + quarantine          (massa morta fora)
3. density / godfile split         (SPLIT before fuse)
4. rehome + architecture ownership
5. defactor + unify_pipes + operate_vs_legacy
6. fuse + reuse
7. standardize + surface_std + honesty
8. simplify + decouple + invariants + contracts
9. optimize (só com medida)
10. navigability + docs_map + dx
11. reliability + agent_qos (se aplicável)
12. gates finais + anti_goodhart check
13. LEDGER: cada area_id = done|partial|n/a|blocked + prova
```

**Floors operator-present** (hot-path sagrado, RSI-core, Evidence intocável, etc.):  
status `blocked` com “needs operator” — **não** forçar autônomo.

---

## 5. Contrato de Autônomos (como rodar sozinho)

### 5.1 Standing mandate (exemplo de texto de packet)

```text
FULL-PASS HYGIENE
canon: docs/engineering-knowledge-base/atlas-full-pass-hygiene-areas.md
cadence: weekly|monthly|post_large_impl
scope: <paths/owners>
rules:
  - visit ALL area_ids 1..30
  - no product features
  - behavior-preserving default
  - scoped commits on main
  - keep-lists intact; no prefix delete
  - golden before structural split
  - LEDGER scoreboard required
done: scoreboard complete + package gates green
```

### 5.2 Origem de tasks (cérebro)

O `atlas:brain` (ou originator de hygiene) deve:

1. Ler este canon + DEBTS abertos.  
2. Produzir tasks **por área** (ou lotes multi-área se atomizáveis).  
3. Cada task cita `area_id`, `scope`, `prove_command`, `rollback`.  
4. Preferir zona de **máximo leverage** (godfile, twin-OS, morto com callers zero, pipe drift).  
5. Nunca originar “vanity residual pass N” sem diff de código/teste/CODEMAP real.

### 5.3 Músculo (task)

- 1 commit = 1 lote revertível.  
- `git add -- <paths>` escopado.  
- Branch local `main` only.  
- Testes/golden do pacote no mesmo lote.  
- Se colidir com outra sessão: blackboard/claim; não roubar.

### 5.4 Evidence mínima do ciclo

Arquivo ou seção LEDGER:

```yaml
full_pass_id: hyg-2026-07-W30
cadence: weekly
scope: app/Services/Ai/SelfConstruction/**
areas:
  refactor: { status: done, proof: "..." }
  defactor: { status: n/a, reason: "no twin OS in scope" }
  eliminate: { status: done, proof: "..." }
  # ... todas as 30
anti_goodhart: pass
gates: green
```

---

## 6. Distinções que o agente não pode confundir

| Par | Diferença |
|---|---|
| **Refatoração** vs **Defatoração** | Forma local vs colapso de identidade/camada duplicada |
| **Fundição** vs **Reaproveitamento** | Juntar fragmentado vs usar canônico já existente |
| **Padronização** vs **Arquitetura** | Como se escreve vs o que existe e quem é dono |
| **Otimização** vs **Eliminação** | Ficar mais barato/rápido/denso vs **sumir** com o morto |
| **Quarentena** vs **Eliminação** | Congelar/arquivar vs deletar com prova |
| **Simplificar lógica** vs **Desacoplar** | Menos branch interno vs menos dependência entre módulos |
| **Full-pass** vs **Feature** | Limpeza/estrutura vs capacidade nova de produto |

---

## 7. Halt (parar e registrar DEBT — não “inventar caminho”)

- Delete que toca keep-list / RSI / Evidence intocável / floors sagrados  
- Fuse que cria arquivo > teto de densidade  
- Split sem golden em monstro de comportamento opaco  
- Mudança de comportamento sem mandate de change  
- Suite full que wipeia estado vivo  
- Vanity scoreboard (pass++ sem prova)  
- Prefix-delete (`AtlasLoop*`, etc.)  
- Criar N-ésima OS layer “temporária”

---

## 8. Scoreboard de um full-pass (fechamento)

Um full-pass **só fecha** se:

1. As **30** áreas têm status.  
2. Toda `partial`/`blocked` tem DEBT acionável.  
3. Gates do pacote verdes.  
4. `anti_goodhart: pass` (nenhuma área marcada done só por proxy).  
5. LEDGER commitado (docs/evidence ou path de hygiene do ciclo).  

**Não** fecha se:

- “Refatorei bastante” sem scoreboard.  
- Só eliminate/pint.  
- LOC caiu mas twin-OS / godfile / operate-path legado ficaram.

---

## 9. Superfícies (server / native / app)

| Superfície | Adaptação da mesma lista |
|---|---|
| **atlas-server** | godfile PHP, commands, AAEOS/Nucleus, brain/task, receipts |
| **atlas-native** | hosts Swift, Judgment/Chrome/spoken/product, CODEMAP casca, CoreChecks |
| **atlas-app** | só se ainda tocado; senão `platform_migrate` + `operate_vs_legacy` apontam pro native |

A **lista de áreas não muda**. O vocabulário de artefato muda.

---

## 10. Relação com outros canons

| Canon | Papel |
|---|---|
| `atlas-elite-executors-dev-forge-autonomos` | Quem executa (mesma barra) |
| `atlas-autonomos-live-system` | Operate path brain/task |
| `atlas-autonomos-self-evolution-quality-loop` | Melhoria contínua de qualidade (frontier) |
| Nucleus GOD-SOTA / GOD-DEBULK | Forma alvo + leis de split/fuse/ownership |
| Este doc | **Menu de atuação do full-pass periódico** |

Self-evolution (frontier/QoS) e full-pass hygiene **complementam**:

- Hygiene = limpar e estruturar o que já existe.  
- Self-evolution = subir a capacidade medida no frontier.  
Ambos podem originar tasks Autônomos; não se substituem.

---

## 11. Frase de bolso (para prompt de agente)

> Você está em **FULL-PASS HYGIENE**. Percorra as **30 áreas** do canon `atlas-full-pass-hygiene-areas`. Otimize, refatore, defatore, elimine, funda, reaproveite, padronize, estruture arquitetura, prove. Sem feature. Sem vanity. LEDGER com scoreboard 1→30. Commits escopados na main. Floors sagrados = blocked, não force.

---

## 12. Changelog

| Data | Nota |
|---|---|
| 2026-07-24 | v1 — lista curta 30 áreas + cadências + contrato Autônomos full-pass (pedido do operador: limpeza semanal/mensal/pós-obra documentada para atuação autônoma). |
