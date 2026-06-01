---
id: atlas-vox-v6-certification
type: certification
title: Atlas Vox V6 — Certificação Final e Como Usar
status: active
category: vox
priority: 95
summary: Como certificar o Atlas Vox V6 para dogfood real, como usar no dia-a-dia, e quando voltar para V7. Cert read-only via `atlas:vox:v6-certify`.
tags:
  - atlas-vox
  - v6
  - certification
  - dogfood
  - gated
capabilities:
  - vox_v6_certification
  - vox_v6_dogfood_handbook
  - vox_v7_unlock_criteria
decisions:
  - V6 fecha o ciclo Atlas Vox para uso real. Implementação pesada pausa após cert pass.
  - Cert principal é `php artisan atlas:vox:v6-certify --json` (envelope `atlas.vox.v6_certification.v1`).
  - Companion desktop `npm run vox:v6-certify --workspace=@atlas/desktop` regrava manifests e delega à artisan.
  - V7 (memória longitudinal) permanece bloqueada — `v7_unlock_allowed=false` mesmo em pass.
  - Destravar V7 exige nova ADR + decisão humana fora do comando de cert.
maintenance:
  - Atualizar quando uma área (backend/desktop/macos/ux/safety) ganhar novo check no service.
  - Atualizar a tabela "Quando voltar para V7" quando o gate humano mudar.
related_paths:
  - app/Services/Ai/Vox/Gate/VoxV6CertificationService.php
  - app/Console/Commands/AtlasVoxV6CertifyCommand.php
  - atlas-desktop/apps/desktop/scripts/voxV6Certify.mjs
  - docs/contracts/vox/VoxInterlocutorDecision.v1.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-vox-v6-certification
graph_title: Atlas Vox V6 — Certificação Final e Como Usar
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-vox-operational-thinking-interface
graph_status: active
graph_source: repo
human_name: "Atlas Vox V6 — Certificação Final e Como Usar"
canonical_name: "Atlas Vox V6 — Certificação Final e Como Usar"
technical_name: atlas-vox-v6-certification
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-vox-v6-certification.md

owner: surface-architecture
repo_paths:
  - docs/engineering-knowledge-base/atlas-vox-v6-certification.md

allowed_changes:
  - Adicionar novo check ao service + entrada correspondente nas tabelas abaixo.
  - Atualizar a seção "Como usar" quando o overlay V6 ganhar atalho novo.
  - Atualizar critérios de "Quando voltar para V7" sob nova ADR.

forbidden_changes:
  - Permitir `v7_unlock_allowed=true` por algum check.
  - Adicionar API paga ao service.
  - Permitir cert que grava no ledger.
  - Promover V7 sem nova ADR explícita.

depends_on:
  - vox-contracts-v1-index
  - vox-interlocutor-decision-v1
  - atlas-vox-operational-thinking-interface
  - adr-0003-vox-vs-voice-realtime-surface-boundary

flows_to:
  - atlas-ai-runtime-release-gate
  - atlas-ai-product-certification

unlocks:
  - vox_v6_dogfood_handbook
  - vox_v6_certification

governs:
  - vox_v6_certification_gate
  - vox_v7_unlock_boundary

evidence:
  - app/Services/Ai/Vox/Gate/VoxV6CertificationService.php
  - test-results/vox-v6-certify/manifest.json (gerado pelo companion desktop)
evidence_refs:
  - symbol: VoxV6CertificationService
  - command: atlas:vox:v6-certify

required_tests:
  - php artisan atlas:vox:v6-certify --json

requires_evidence: true
risk_level: medium

next_actions:
  - Rodar cert V6 antes de dogfood real.
  - Abrir nova ADR antes de destravar V7.

ai_entrypoints:
  - Leia esta doc antes de tocar o serviço de cert ou tentar destravar V7.
  - Leia "Quando voltar para V7" antes de propor qualquer feature longitudinal.

quality_gates:
  - php artisan atlas:vox:v6-certify (não pode retornar fail)

failure_modes:
  - blocking=true em opinião dentro do V5 → cert.safety_r4_blocks falha.
  - String legacy ("Dictation", "Prompt Polish", etc.) aparecendo na UI → cert.ux_overlay_pt_br_no_legacy_strings falha.
  - voice_mode default != off → cert.desktop_voice_reply_default_off falha.
  - Backend importar Anthropic/OpenAI → cert.backend_no_paid_api_dependency falha.
  - Touchar atlas-app/ ou Voice Realtime → cert.backend_no_voice_realtime_touched falha.
---
# Atlas Vox V6 — Certificação Final

## Resumo

Este runbook define como certificar o Atlas Vox V6 para dogfood real e quando manter V7 bloqueado.

## Papel no Atlas

Ele funciona como contrato operacional para o fechamento do ciclo Vox V6.

## Onde Se Encaixa

O documento fica entre a interface operacional Vox, os contratos de interlocutor e os gates de release.

## Contratos

O comando `php artisan atlas:vox:v6-certify --json` e o envelope canonico de certificacao governam a promocao.

## Fluxo

O operador roda o cert, revisa checks por area, confirma dogfood V6 e mantem V7 bloqueado ate nova ADR.

## Regras para IA

Nao destravar V7, nao adicionar provider pago e nao transformar warnings em aprovacao sem evidencia.

## Escopo de Implementacao

O escopo cobre certificacao read-only, companion desktop e criterios de retorno para V7.

## Dependencias

Depende dos contratos Vox, da decisao de fronteira Vox versus Voice Realtime e do service de gate V6.

## Evidencias

As evidencias minimas sao o service de certificacao, o comando artisan e o manifest gerado pelo companion desktop.

## Riscos

O risco principal e promover memoria longitudinal V7 sem decisao humana ou sem ADR explicita.

## Exemplos

Um resultado `pass` pode liberar dogfood V6, mas nunca deve retornar `v7_unlock_allowed=true`.

## Proximas Acoes

Executar o cert antes de dogfood e abrir nova ADR quando V7 voltar ao roadmap ativo.

> V6 fecha a primeira jornada Atlas Vox: ambient Mac, Option+Space, auto mode,
> interlocutor que pergunta/avisa/discorda/sugere, resposta curta opcional,
> dogfood leve. Esta doc é o handbook canônico para certificar e usar.

## Comando principal

```bash
php artisan atlas:vox:v6-certify --json
```

- Read-only. Não grava ledger, não chama provider, não toca rede.
- Cobre **5 áreas** (Backend, Desktop, macOS, UX, Safety).
- Envelope canônico: `atlas.vox.v6_certification.v1`.
- Exit codes:
  - `0` → `status='pass'` ou `status='warn'`.
  - `0` → idem (default), mas use `--strict` para tratar warn como falha em CI.
  - `1` → `status='fail'` (ou `--strict` com `warn`).
  - `2` → exceção fatal.

### Companion desktop (opcional)

```bash
npm run vox:v6-certify --workspace=@atlas/desktop
```

Regrava manifests de `vox:release-check` e `vox:visual-smoke` e em seguida
invoca a cert principal. Usar quando você quer um único caminho do lado
desktop antes de entregar o JSON para o operador. O envelope companion
(`atlas.vox.v6_desktop_companion.v1`) cita explicitamente que o comando
principal continua sendo o artisan.

## Envelope retornado

```json
{
  "schema": "atlas.vox.v6_certification.v1",
  "version": "0.1.0",
  "status": "pass|warn|fail",
  "v6_ready_for_dogfood": true,
  "v7_unlock_allowed": false,
  "checks": [...],
  "summary": {
    "verdict_pt_br": "...",
    "totals": { "pass": N, "warn": N, "fail": N },
    "by_area": { "backend": {...}, "desktop": {...}, "macos": {...}, "ux": {...}, "safety": {...} },
    "warnings": [...],
    "failures": [...],
    "checks_count": N
  },
  "next_actions": [...],
  "generated_at": "ISO-8601"
}
```

### Regras de status

- **pass:** zero warns, zero fails. Use Atlas Vox V6 em dogfood real.
- **warn:** ≥ 1 warn, zero fails. Usável; revise pontos antes de uso público intenso.
- **fail:** ≥ 1 fail. **Não use** até corrigir.
- **v7_unlock_allowed:** SEMPRE `false`. V7 (memória longitudinal) está
  congelada por doutrina V6-F. Destravar exige nova ADR + decisão humana.

## Tabela de checks (25)

### Backend (8)
| check | o que valida |
|---|---|
| `backend_vox_endpoints_registered` | `/ai/vox/health`, `/ai/vox/intent`, `/ai/vox/execute` em `routes/api.php`. |
| `backend_v4_auto_mode_available` | `VoxAutoModeRouter::decide()` devolve `atlas.vox.auto_mode_decision.v1`. |
| `backend_v5_interlocutor_available` | `VoxInterlocutorPolicy` bloqueia caso destrutivo canônico. |
| `backend_dogfood_endpoint_ok` | Rota `/ai/vox/dogfood/*` + controller + modelo + migration presentes. |
| `backend_no_raw_audio_persisted` | Controller rejeita campos de áudio cru e `raw_pcm_persisted=true`. |
| `backend_no_paid_api_dependency` | Nenhuma menção a Anthropic/OpenAI nos serviços V3–V6. |
| `backend_no_voice_realtime_touched` | Não importa `App\Services\Ai\Voice\` (ADR 0003). |
| `backend_no_mobile_touched` | Não toca `atlas-app/` nem `App\Mobile`. |

### Desktop (7)
| check | o que valida |
|---|---|
| `desktop_release_check_manifest` | `vox:release-check` retornou pass/warn (warn é não-bloqueante). |
| `desktop_visual_smoke_manifest` | `vox:visual-smoke` passou (SSR + leak scan). |
| `desktop_v6_tauri_commands_present` | `commands_vox_reply.rs` + handlers `vox_settings_get/update/speak_short` registrados. |
| `desktop_ambient_helpers_present` | `vox_ambient_launch.rs` + `voxDev.mjs` presentes. |
| `desktop_voice_reply_default_off` | `VoiceMode::default() = Off` em Rust E `voiceMode: 'off'` em TS. |
| `desktop_settings_persistence_available` | `VoxSettingsStore` aponta para `~/.atlas/vox/settings.json` + API completa. |
| `desktop_option_space_in_process` | Hotkey Option+Space documentada no runtime Rust. |

### macOS (4)
| check | o que valida |
|---|---|
| `macos_identifier_canonical` | `tauri.conf.json` declara `identifier=com.atlas.code`. |
| `macos_microphone_usage_description` | `Info.plist` contém `<key>NSMicrophoneUsageDescription</key>`. |
| `macos_audio_input_entitlement` | `entitlements.plist` contém `com.apple.security.device.audio-input=true`. |
| `macos_signing_identity_documented` | Signing identity declarada (pass) OU justificativa "Atlas Local Code Signing" (warn). |

### UX (2)
| check | o que valida |
|---|---|
| `ux_overlay_pt_br_no_legacy_strings` | Snapshots `vox-visual-smoke/overlay-*.html` não contêm `Dictation`, `Prompt Polish`, `Intent Compile`, `Governed Execute`, `READINESS`, `V3 GATE`, `AudioInputInvalid`, `rms=`, `peak=`. |
| `ux_advanced_details_collapsed` | "Detalhes avançados" começa colapsado em `VoxOverlay.tsx`. |

### Safety (4)
| check | o que valida |
|---|---|
| `safety_no_terminal_auto_execute` | Backend Vox não usa `exec/shell_exec/proc_open/passthru/system/popen/Symfony Process`. |
| `safety_r4_blocks` | Casos canônicos (`rm -rf` R4, `drop database`, `apaga tudo` R3) retornam `disagree` + `blocking=true`. |
| `safety_receipt_required` | `/ai/vox/execute` exige `intent_id` + `receipt_id` required. |
| `safety_reply_helper_no_audio_no_provider` | `commands_vox_reply.rs` só usa `/usr/bin/say` + whitelist (sem áudio, sem provider). |

## Como usar Atlas Vox V6 (dia-a-dia)

1. **Abrir Atlas Desktop** (Tauri shell completo, identificador `com.atlas.code`).
2. **Pressionar `Option+Space`** para abrir o overlay Vox e começar a gravar.
3. **Falar em PT-BR**. Whisper local (`~/.atlas/vox/models/ggml-large-v3.bin`)
   transcreve. Nada de áudio cru sai do Mac.
4. **Atlas decide o modo** automaticamente (V4 auto mode router) e mostra
   "Atlas entendeu". Trocar manualmente é possível em "Trocar modo".
5. **Atlas pode perguntar/avisar/discordar** (V5 interlocutor). Em casos
   destrutivos canônicos (rm -rf, drop database, apaga tudo, etc.) o botão
   Confirmar fica bloqueado até trocar de modo ou cancelar.
6. **Resposta curta** ("Entendi.", "Preciso de um detalhe.", "Bloqueei por
   segurança.", "Prompt pronto.") aparece sempre em texto. Para ouvir essas
   frases via macOS `say`, abrir **Detalhes avançados → Voz: curta**.
   Default é `desligada`.
7. **Dogfood**: ao final de cada sessão útil, registrar em
   `POST /ai/vox/dogfood/session` (1 clique no overlay). O relatório vive em
   `GET /ai/vox/dogfood/report`.

### Atalhos canônicos

| Atalho | O que faz |
|---|---|
| `Option+Space` | abrir overlay e começar a gravar (ou parar se já gravando) |
| `Enter` (no overlay listening) | finalizar gravação |
| `Esc` (no overlay listening) | cancelar gravação |
| `Esc Esc` (global) | eclipse — derruba qualquer sessão em curso |

### Modos manuais (controle secundário)

Visíveis em "Trocar modo" no overlay. Não use por default — V4 auto mode é
quase sempre certo.

| Modo | O que faz |
|---|---|
| `dictation` | texto literal pro composer |
| `prompt_polish` | limpa e melhora o texto antes de inserir |
| `intent_compile` | transforma a fala em prompt mais forte (Codex/Claude) |
| `governed_execute` | revisa risco antes de qualquer ação |

### Voz curta (whitelist de 4 frases)

| Key | Texto PT-BR |
|---|---|
| `understood` | "Entendi." |
| `need_detail` | "Preciso de um detalhe." |
| `blocked_safety` | "Bloqueei por segurança." |
| `prompt_ready` | "Prompt pronto." |

Default: `voice_mode='off'`. Whitelist é hardcoded no Rust — payload do
webview NUNCA injeta texto cru. Cooldown global de 5s anti-flood. Falha do
`say` jamais quebra o fluxo (texto continua aparecendo).

## Quando voltar para V7

V7 (memória longitudinal — Atlas que lembra contexto entre sessões via
embedding, RAG persistente, etc.) está **explicitamente congelada** após V6.
A cert V6 carrega `v7_unlock_allowed=false` mesmo em pass — destravar exige
TODOS os critérios abaixo, em ordem:

| # | Critério | Como verificar |
|---|---|---|
| 1 | **Cert V6 = `pass`** por ≥ 4 semanas consecutivas em execuções semanais. | `php artisan atlas:vox:v6-certify --strict` retorna 0 toda semana. |
| 2 | **≥ 30 sessões dogfood reais** registradas. | `GET /ai/vox/dogfood/report` mostra ≥ 30 sessões com `outcome=completed` ou `partial`. |
| 3 | **Aprovação humana explícita do Vitor.** | Decisão registrada como ADR 0004 `vox-v7-longitudinal-memory-scope`. |
| 4 | **ADR 0004 publicada e aprovada.** | Documento ativo em `docs/engineering-knowledge-base/adr/` com escopo, limites e contratos. |
| 5 | **Plano de implementação V7 (paper-only).** | Doc em `docs/engineering-knowledge-base/atlas-vox-v7-*-plan.md` com graph_status=`planned`. |
| 6 | **Audit hardening V3+V6 sem `fail`.** | `GET /ai/vox/audit/v3-hardening` retorna `pass`/`warn`. |

Antes de destravar V7, o serviço de cert V6 deve ganhar um novo check
`v7_gate_passed` que valide todos os critérios acima e — só então —
permita `v7_unlock_allowed=true`. Este check NÃO existe ainda; criá-lo é
parte do trabalho V7-A (futuro).

### O que V7 NÃO é

- **Não é** Voice Realtime Surface (essa segue paused até ADR específica).
- **Não é** mobile (atlas-app continua intocado).
- **Não é** API paga de embedding (continua proibido).
- **Não é** captura silenciosa de tela (V4 doc lista contexto proibido).

### O que continua proibido após V6 (independente de V7)

- Captura de áudio cru persistido (Lei 0.75).
- Auto-execute de terminal (Lei 0.9).
- API paga (Lei 0).
- Tocar Voice Realtime Surface ou atlas-app (ADR 0003).
- Bloqueio em opinião (V5 doutrina; cert.safety_r4_blocks vigia).

## Build/release esperado

```bash
# Backend (atlas-server)
/opt/homebrew/bin/php artisan atlas:vox:v6-certify
/opt/homebrew/bin/php vendor/bin/phpunit tests/Unit/Ai/Vox tests/Feature/Ai/Vox

# Desktop (atlas-desktop)
cd atlas-desktop
npm run vox:v6-certify --workspace=@atlas/desktop   # companion
npm run vox:release-check --workspace=@atlas/desktop
npm run build --workspace=@atlas/desktop
cargo test -p atlas-platform -p atlas-tauri
```

Todos devem fechar verde (pass) ou warn explicado. Qualquer fail =
stop-the-line; não usar Vox V6 em dogfood até resolver.
