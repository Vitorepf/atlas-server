---
id: vox-interlocutor-decision-v1
type: contract
title: VoxInterlocutorDecision.v1 — Atlas Vox V5 Symbiotic Interlocutor
status: active
category: vox
priority: 80
summary: Contrato canônico para a decisão conversacional do V5 Symbiotic Interlocutor. Camada determinística que decide se o Atlas deve perguntar, advertir, discordar ou sugerir prompt melhor ANTES da execução. Não executa, não chama provider.
tags:
  - atlas-vox
  - v5
  - interlocutor
  - contract
  - deterministic
capabilities:
  - vox_v5_interlocutor_decision
  - vox_v5_intervention_taxonomy
  - vox_v5_blocking_policy
decisions:
  - V5 Symbiotic Interlocutor é determinístico (sem LLM, sem rede, sem random) e devolve uma única decisão conversacional por evaluate().
  - blocking=true SOMENTE em HARD policy markers OU SOFT policy marker + risk_class=R4. Bloqueio nunca é opinião.
  - Mensagens curtas (≤ 120 chars) em PT-BR estrito; uma pergunta por intervenção; sem inglês; sem tom paternalista.
  - V4 (auto mode router) continua funcionando se intervention=none.
maintenance:
  - Atualizar quando marcadores HARD/SOFT mudarem.
  - Drift entre policy e teste é coberto pelo data provider hardMarkerProvider() / softMarkerProvider().
related_paths:
  - app/Services/Ai/Vox/Interlocutor/VoxInterlocutorPolicy.php
  - tests/Unit/Ai/Vox/VoxInterlocutorPolicyTest.php
  - app/Services/Ai/Vox/VoxSchema.php
  - app/Http/Controllers/AtlasAiVoxController.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: vox-interlocutor-decision-v1
graph_title: VoxInterlocutorDecision.v1
graph_world: atlas
graph_layer: contract
graph_kind: schema
graph_parent: vox-contracts-v1-index
graph_status: active
graph_source: repo

owner: surface-architecture
repo_paths:
  - docs/contracts/vox/VoxInterlocutorDecision.v1.md

allowed_changes:
  - Adicionar novo marker HARD/SOFT com teste correspondente.
  - Refinar mensagens PT-BR (sem mudar shape).
  - Calibrar thresholds (com cobertura de teste).

forbidden_changes:
  - Adicionar campo obrigatório novo sem bump de versão.
  - Permitir blocking=true fora de HARD policy + SOFT R4.
  - Chamar LLM/provider/rede dentro do policy.
  - Quebrar PT-BR (introduzir inglês na mensagem default).

depends_on:
  - vox-contracts-v1-index
  - vox-intent-packet-v1

flows_to:
  - atlas-vox-overlay-v4
  - atlas-vox-evidence-ledger

evidence:
  - VOX_INTERLOCUTOR_INTERVENED ledger event (atlas-server/app/Services/Ai/Vox/VoxEvidenceService.php)

required_tests:
  - vendor/bin/phpunit tests/Unit/Ai/Vox/VoxInterlocutorPolicyTest.php

risk_level: medium

ai_entrypoints:
  - Leia este contrato antes de adicionar nova intervenção ou mudar tom da V5.
  - Leia "Tabela de marcadores" antes de remover/adicionar palavras-chave destrutivas.

quality_gates:
  - phpunit tests/Unit/Ai/Vox/VoxInterlocutorPolicyTest.php (≥ 80 testes)

failure_modes:
  - blocking=true em opinião (clarify/caution/suggest_better_prompt). Stop-the-line.
  - tom paternalista vazando ("tem certeza absoluta", "você está errado"). Stop-the-line.
  - inglês vazando em mensagens default ("please", "warning"). Stop-the-line.
  - intervenção desnecessária em fala segura (R0/R1 sem marker). Stop-the-line.
---
# VoxInterlocutorDecision.v1

> Atlas Vox V5 · camada conversacional determinística. Decide se o Atlas deve
> **perguntar**, **advertir**, **discordar** ou **sugerir prompt melhor**
> ANTES de qualquer execução. Não executa, não chama provider, não substitui
> o Kernel.

## Doutrina

- **Útil, não chato.** A política intervém pouco em casos claros (`none`),
  pergunta só quando falta dado essencial (`clarify`), avisa sem travar em
  risco médio (`caution`), bloqueia apenas risco/política dura (`disagree`
  com `blocking=true`) e sugere prompt melhor apenas quando isso claramente
  aumenta qualidade (`suggest_better_prompt`).
- **Determinismo.** Sem LLM, sem rede, sem random, sem clock state. Mesmo
  input → mesma saída.
- **PT-BR estrito.** Sem mistura inglês/português. Tom: "Eu faria diferente
  por segurança…", "Preciso de um detalhe antes de seguir…", "Posso
  transformar isso num prompt mais forte antes de enviar.". Nunca "você
  está errado".
- **Bloqueio só em política dura.** `blocking=true` SOMENTE em HARD policy
  markers (rm -rf, drop database, apaga tudo, etc.) OU SOFT policy markers
  + risk_class=R4.

## Schema

```json
{
  "schema": "atlas.vox.interlocutor_decision.v1",
  "intervention": "none | clarify | caution | disagree | suggest_better_prompt",
  "message_pt_br": "string ≤ 120 chars",
  "question_pt_br": "string ≤ 120 chars (vazia para none e caution)",
  "blocking": false,
  "reason_code": "ambiguous_reference | destructive_risk | missing_context | weak_prompt | safer_path_available | none",
  "suggested_edit": { "...": "..." } | null,
  "policy_version": "0.2.0",
  "markers": { "...": "..." }
}
```

| Campo | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `schema` | string | sim | Sempre `atlas.vox.interlocutor_decision.v1`. |
| `intervention` | enum | sim | Tipo de intervenção (5 valores possíveis). |
| `message_pt_br` | string | sim | Mensagem curta em PT-BR. Vazia para `none`. |
| `question_pt_br` | string | sim | Pergunta curta. Uma `?` no máximo. Vazia para `none` e `caution`. |
| `blocking` | bool | sim | `true` desabilita Confirmar no overlay. SOMENTE HARD ou SOFT@R4. |
| `reason_code` | enum | sim | Razão canônica (6 valores). |
| `suggested_edit` | object\|null | sim | Edits sugeridos (ex.: `safer_path`, `add_sections`). |
| `policy_version` | string | sim | Versão semver do policy (atual `0.2.0`). |
| `markers` | object | sim | Markers internos (debugging/audit). |

## Tabela de intervenções

| `intervention` | Quando dispara | `blocking` | Exemplos |
|---|---|---|---|
| `none` | Pedido claro e seguro; ou prompt forte com identifier concreto; ou modo `dictation`/`prompt_polish` neutro. | `false` | "investiga o módulo Voice em modo leitura", "anota: comprar pão", "melhora esse texto". |
| `clarify` | Referência ambígua (`isso`, `aquele arquivo`, `lá`) sem contexto resolvido. Terminal sem comando. | `false` | "manda o Codex olhar isso" (sem context_refs), "executa no terminal". |
| `caution` | Risco R2/R3 sem marker destrutivo. SOFT marker em risco R0/R1. | `false` | "edita o config local" (R2), "faz commit pra staging" (R3). |
| `disagree` | HARD policy marker (sempre `blocking=true`). SOFT policy marker em R4 (`blocking=true`) ou R2/R3 (`blocking=false`). | conforme tier | "apaga tudo" → blocking; "git push --force" em R3 → não blocking. |
| `suggest_better_prompt` | Modo `intent_compile` + fala curta sem objetivo extraído E sem identifier concreto E sem verbo-de-ação claro. | `false` | "manda pro codex", "pergunta pro claude". |

## Tabela de marcadores

### HARD policy markers · bloqueio sempre

| `key` | label | safer_path (PT-BR) |
|---|---|---|
| `rm_rf` | `rm -rf` | mover para pasta de quarentena antes de apagar |
| `dd_if` | `dd if=` | confirmar o destino em "of=" — dd é irreversível |
| `mkfs` | `mkfs` | verificar partição montada antes — mkfs apaga |
| `drop_database` | `drop database` | dump completo e confirmar ambiente antes do drop |
| `truncate_table` | `truncate` | SELECT count(*) antes; em prod, soft-delete |
| `curl_pipe_shell` | `curl \| shell` | baixar o script e inspecionar antes de rodar |
| `wget_pipe_shell` | `wget \| shell` | baixar o script e inspecionar antes de rodar |
| `apagar_tudo` | `apagar tudo` | apagar por categoria, revisando a lista |
| `deletar_tudo` | `deletar tudo` | deletar por categoria, revisando a lista |
| `deletar_projeto` | `deletar projeto` | arquivar primeiro; deletar só após backup |

### SOFT policy markers · calibrado por risk_class

| `key` | label | R0/R1 | R2/R3 | R4 |
|---|---|---|---|---|
| `git_push_force` | `git push --force` | caution | disagree (não-blocking) | disagree (blocking) |
| `force_push` | `force push` | caution | disagree (não-blocking) | disagree (blocking) |
| `git_reset_hard` | `git reset --hard` | caution | disagree (não-blocking) | disagree (blocking) |
| `sudo` (com verbo) | `sudo` | caution | disagree (não-blocking) | disagree (blocking) |
| `deploy` | `deploy` | caution | disagree (não-blocking) | disagree (blocking) |

> `sudo` SOFT é restrito a `sudo` + verbo operacional (`rm`, `chmod`, `dd`,
> `mkfs`, `kill`, `reboot`, `systemctl`, etc.) para evitar falsos positivos
> em frases como "rodar sem sudo primeiro".

### Ambiguous reference markers · clarify

Demonstrativos sem alvo concreto: `isso`, `essa`, `esse`, `aquele arquivo`,
`aquela pasta`, `lá`, `aqui`, `aí`, `essa coisa`, `nosso projeto`,
`esse bug`. Padrões verbais: `manda/olha/vê/roda/executa/usa/abre/aplica +
isso`. Combinação IA externa + verbo + demonstrativo: `Codex olhar isso`.

Clarify é **suprimido** quando `intent_packet.context_refs` contém pelo
menos um ref com `kind != 'none'` e `resolved=true`.

### Terminal-bare marker · clarify

Frase do tipo `executa no terminal` / `roda no terminal` SEM comando explícito
depois (nenhum substantivo `testes`/`comando`/`script`/`build`, nenhum verbo
de ação subsequente). Pergunta única: "Qual comando devo rodar?".

### Weak-prompt suppressors · não dispara suggest

Quando a fala contém um destes sinais, NÃO chamamos `suggest_better_prompt`:

- Identifier concreto: CamelCase (`AuthController`), snake_case
  (`run_tests`), arquivo (`AuthService.php`), path (`/api/users`).
- Verbo de ação concreta + objeto direto: "resolver o bug", "investigar
  o módulo", "implementar a paginação no endpoint".

Weak-prompt só dispara em: fala muito curta (`≤ 5 tokens`) + compiled
prompt curto (`< 60 chars`) + sem objetivo extraído + sem identifier +
sem verbo+objeto. OU `≤ 8 tokens` + compiled muito raso (`< 40 chars`)
nas mesmas condições.

## Regras de tom

1. **Mensagem curta:** ≤ 120 chars. Sem textão.
2. **Uma pergunta por vez.** Máximo uma `?` no `question_pt_br`.
3. **PT-BR estrito.** Lista bloqueada: `please`, `force`, `clarify`,
   `caution`, `warning`, `confirm action`, `are you`, `you should`, `sorry`.
4. **Sem paternalismo.** Banido: "tem certeza absoluta", "você está errado",
   "isso é perigoso", "não faça isso", "pare", "cuidado!", "atenção!!".
5. **Tom canônico:**
   - Disagree HARD ou SOFT@R4: "Eu faria diferente por segurança: <safer>." + "Confirma seguir mesmo assim?"
   - Disagree SOFT@R2/R3: "Eu faria diferente: <safer>." + "Sigo assim ou prefere a versão mais segura?"
   - Clarify: "Preciso de um detalhe antes de seguir." + pergunta concreta com ≥2 opções.
   - Suggest: "Posso transformar isso num prompt mais forte antes de enviar." + "Estruturo melhor ou sigo com a versão atual?"
   - Caution: "Risco médio/moderado — …" + (sem pergunta)

## Evidência

Quando `intervention !== 'none'`, o controller emite o evento
`VOX_INTERLOCUTOR_INTERVENED` no Evidence Ledger. Payload do ledger NÃO
contém o conteúdo cru de `message_pt_br` ou `question_pt_br` — apenas
sha256 + length + reason_code + blocking flag + policy_version. A response
HTTP carrega as strings para o overlay; o ledger só prova que a policy
disparou.

## Frontend (Atlas Desktop)

- `bridge.ts` parse `interlocutor` field com `parseInterlocutorDecision`;
  retorna `null` quando ausente (compat Kernel antigo).
- `VoxOverlay.tsx` renderiza bloco `.vox-v4-interlocutor` apenas quando
  `intervention !== 'none'`. Variantes CSS por tipo (`clarify`, `caution`,
  `disagree`, `suggest_better_prompt`) + estado `-blocking`.
- Botão **Confirmar** fica desabilitado quando `blocking=true`. Operador
  precisa **Trocar modo** ou **Cancelar** para seguir.
- V4 (auto mode router) continua funcionando quando `intervention=none`.

## Versionamento

- v0.1.0 (V5-A · 2026-05-19): primeira versão da policy.
- **v0.2.0 (V5-C · 2026-05-19): calibração de tom + thresholds.**
  - HARD vs SOFT split.
  - Ambiguous markers ampliados (verbo + demonstrativo, "esse bug").
  - Weak-prompt suprimido por identifier + clear-action-intent.
  - Mensagens encurtadas. "Tem certeza absoluta?" removido.
  - Terminal-bare detector.

Mudanças incompatíveis (renome de field, mudança de enum) exigem `v2`.
Mudanças compatíveis (novo marker, nova mensagem) ficam dentro de `v1.x`.

## Testes obrigatórios

`vendor/bin/phpunit tests/Unit/Ai/Vox/VoxInterlocutorPolicyTest.php`

Cobertura mínima:

- 80+ testes (data providers + casos canônicos do brief).
- HARD markers: 10 casos × disagree blocking.
- SOFT markers: 9 casos × calibração R0/R2/R3/R4.
- Ambiguous: 13 casos sem contexto + 1 com contexto resolvido.
- Terminal-bare: 3 casos + 1 contra-exemplo.
- Weak-prompt: 4 disparam + 7 suprimidos por sinal forte.
- Silent cases: 5 fixtures de pedido claro/seguro.
- Tom: mensagens curtas, uma pergunta, sem inglês, sem paternalismo.
- Determinismo + schema + versão.
