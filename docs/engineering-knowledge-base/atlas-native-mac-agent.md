---
id: atlas-native-mac-agent
type: engineering_knowledge
title: Atlas Native Mac Agent
status: future
category: runtime-architecture
priority: 92
summary: Contrato canonico para Swift/macOS como runtime nativo local do Atlas, limitado a integracao Apple, permissoes, contexto ambiental e automacao assistida sem virar Kernel paralelo.
tags:
  - atlas-ai
  - swift
  - macos
  - native-agent
  - local-runtime
capabilities:
  - native_mac_agent
  - swift_macos_runtime
  - secure_local_approval
  - native_context_surface
  - native_permission_broker
  - native_environment_sensing
decisions:
  - Swift entra como Atlas Native Mac Agent, nao como backend alternativo.
  - Laravel continua sendo Kernel/Maestro e unico emissor de DecisionReceipt.
  - Swift pode acessar APIs nativas do macOS apenas com opt-in, policy, receipt e evidence.
  - Captura continua de tela, microfone ou automacao destrutiva e future/blocked ate existir privacy, consent e review gates.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar antes de criar app, daemon, helper, menu bar, Accessibility bridge, FSEvents watcher ou Keychain/Touch ID integration.
  - Rodar docs-health, sync, index-code e architecture-validate depois de alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-local-agent-surface.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---

# Atlas Native Mac Agent

Este documento define como Swift/macOS entra no Atlas.

O objetivo e aproveitar o Mac M5 Pro local sem criar bagunca: Swift da corpo
nativo ao Atlas, mas nao decide, nao roteia modelo, nao executa dominio e nao
substitui Laravel.

## Identidade

Nome canonico: `Atlas Native Mac Agent`.

Papel: runtime/surface nativo local para APIs Apple, contexto ambiental,
seguranca local e automacao assistida.

Nao e:

1. novo dominio;
2. novo Kernel;
3. substituto de Laravel;
4. runtime de Graph RAG/analytics pesado;
5. daemon com autonomia irrestrita.

## Divisao De Linguagens

| Linguagem | Papel |
|---|---|
| Laravel/PHP | Kernel, API, policy, receipts, ledger, gates, domains |
| Python | AI/Data Runtime: RAG, embeddings, ML, analytics, swarms |
| Go | Edge/Concurrency Runtime: rede, ingestao, streaming, baixo overhead |
| Swift | Native Mac Runtime: Keychain, Touch ID, macOS APIs, UI local e sensores opt-in |

## Escopo Agora

Primeiro bloco recomendado, com maior ROI e menor risco:

1. Keychain para guardar tokens locais sob policy do Kernel;
2. Touch ID approval para acoes sensiveis aprovadas pelo operador;
3. notificacoes nativas para proposals, gates, failures e reviews;
4. Menu Bar status read-only com health do Atlas;
5. FSEvents para avisar que workspace mudou e disparar index/update governado.

Essas capacidades sao permitidas apenas como `preview/assistido` ate haver
receipt, ledger events, health check e teste de contrato.

## Escopo Proximo

Pode entrar depois do primeiro bloco:

1. global command palette local, estilo Raycast, chamando surfaces do Kernel;
2. Accessibility API limitada a app em foco, titulo de janela e texto
   explicitamente selecionado;
3. ScreenCaptureKit manual, por comando ou botao, nunca continuo por padrao;
4. Core Spotlight para indexar atalhos/resultados aprovados do Atlas;
5. Core ML leve para classificacao local auxiliar, sem substituir Decide.

## Backlog Nativo Adicional

Capacidades Swift valiosas, ainda subordinadas ao Kernel:

| Capability | Uso correto | Status |
|---|---|---|
| App Intents / Shortcuts | Atalhos macOS/iOS e Siri chamam surfaces Atlas | next |
| XPC helper isolado | Keychain/Touch ID em processo com permissao minima | next |
| Clipboard opt-in | enviar texto/imagem/arquivo atual somente por comando | next |
| NSWorkspace focus events | app ativo/titulo de janela sem ler conteudo sensivel | next |
| URL scheme / deep links | `atlas://review/...` abre proposal/run/contexto | next |
| Battery/thermal awareness | reduzir jobs quando Mac esta quente ou na bateria | next |
| LaunchAgent supervision | iniciar/parar watcher local com health e opt-in | next |
| Local permission broker | centralizar consentimentos Apple e TTL por capability | next |
| Endpoint Security API | auditoria profunda de processos/arquivos | future/critical |

Tudo acima exige capability manifest, permission gate e evidence. Endpoint
Security nunca deve entrar sem AP proprio, entitlement review e fallback seguro.
LaunchAgent nao substitui scheduler Laravel; apenas mantem bridges nativos vivos.

## Escopo Futuro

Somente com AP proprio, privacy review e opt-in explicito:

1. captura continua de tela;
2. controle de janelas e teclado em background;
3. HomeKit, Bluetooth ou IoT;
4. automacao de apps externos;
5. modelos locais com acesso a dados privados;
6. daemon always-on com contexto ambiental longitudinal.

## Escopo Ativo Especializado: Voice Realtime Edge

Voz/microfone era escopo futuro deste documento. Foi destravado por AP proprio
e governanca em `atlas-ai-voice-realtime-surface.md`, sob as seguintes
restricoes constitutivas:

1. wake word detectado **localmente**; audio ambiente nao streama antes;
2. audio raw nunca persiste em disco;
3. eclipse modes (calendar, focus, manual, domain rule) sao class-3 imutaveis;
4. LED virtual sinaliza captura ativa em tempo real;
5. binario `atlas-voice-edge` registrado como runtime `swift_native_mac` com
   capability `voice.realtime.edge`;
6. Privacy class por domain (sensitive/secret bloqueia hash de audio).

Captura continua de tela e modelos locais com dados privados continuam future.

## Proibido

Swift nunca pode:

1. emitir DecisionReceipt;
2. escolher provider/modelo;
3. decidir domain/flow;
4. aplicar policy propria;
5. gravar memoria sem privacy/gate;
6. executar deploy, financa ou comando destrutivo sem approval;
7. capturar tela/microfone continuamente sem consentimento forte;
8. manter banco canonico paralelo;
9. esconder falha de permissao nativa.

## Contrato De Comunicacao

Toda chamada vem do Kernel:

```json
{
  "schema_version": "atlas.native_mac.invoke.v1",
  "envelope_id": "uuid",
  "decision_receipt_hash": "sha256",
  "capability": "keychain.approval|fsevents.watch|notification.send",
  "mode": "preview|assist|apply",
  "policy": {},
  "limits": {},
  "consent": {}
}
```

Resposta:

```json
{
  "schema_version": "atlas.native_mac.result.v1",
  "status": "succeeded|failed|blocked|needs_permission",
  "permission_state": {},
  "artifacts": [],
  "metrics": {},
  "evidence": []
}
```

Laravel valida, grava Evidence Ledger e decide o proximo passo.

## Evidence Obrigatoria

Toda operacao Swift deve registrar:

1. envelope id;
2. receipt hash;
3. capability;
4. permission state;
5. mode;
6. input/output hash;
7. duration;
8. status;
9. artifact refs sem conteudo sensivel.

## Gates De Privacidade

| Capability | Gate minimo |
|---|---|
| Keychain | operador autenticado + receipt |
| Touch ID | operator approval + action reason |
| Notification | user-visible + no secrets |
| FSEvents | path allowlist + no content exfiltration |
| Accessibility | app allowlist + selected text only |
| ScreenCaptureKit | manual trigger + visible indicator |
| Clipboard | explicit trigger + no clipboard history |
| App focus | metadata only + no hidden content capture |
| LaunchAgent | opt-in + health + user-visible disable |
| XPC helper | signed helper + least privilege |
| Microphone | wake word local + eclipse + privacy class + LED visivel; ver `atlas-ai-voice-realtime-surface.md` |
| Endpoint Security | future/blocked + AP + entitlement review |

## Implementation Roadmap

| Fase | Status | Entrega |
|---|---|---|
| NM-0 | future | spec, contracts, command discovery, permission model |
| NM-1 | future | Keychain + Touch ID approval bridge |
| NM-2 | future | Notifications + Menu Bar read-only status |
| NM-3 | future | FSEvents workspace watcher com event contract |
| NM-4 | future | Accessibility selected-context bridge |
| NM-5 | future | Manual screen capture bridge |
| NM-6 | future | Core Spotlight / local command palette |
| NM-7 | future | Shortcuts, XPC helper, clipboard opt-in e deep links |
| NM-8 | future | Battery/thermal awareness, permission broker e LaunchAgent |
| NM-9 | future | Endpoint Security review e automacoes AppleScript governadas |

## Definition Of Done

Antes de implementar qualquer modulo Swift:

1. criar AP curto;
2. declarar capability e permission gate;
3. passar por DecisionReceipt;
4. usar payload/result versionados;
5. registrar Evidence Ledger;
6. ter health check;
7. ter teste de contrato;
8. atualizar runtime language boundaries, local agent e KB;
9. rodar `docs-health`, `sync --prune`, `index-code --prune` e
   `architecture-validate`.
