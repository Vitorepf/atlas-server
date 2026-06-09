---
id: atlas-embodiment-overview
type: engineering_knowledge
title: "Atlas Embodiment"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# Atlas Embodiment

> Dar corpo físico ao Atlas. O StackChan (M5Stack K151) é a primeira **surface física** do kernel — sensores, atuadores, voz, presença e linguagem corporal — operando como cliente fino do Atlas.

---

## Status do projeto

| Item | Estado |
|---|---|
| Hardware adquirido | ✅ StackChan K151 (M5Stack) |
| Atlas kernel | 🟡 Em implementação (fora do escopo desta doc) |
| Embodiment — design | 🟢 Em estruturação |
| Embodiment — implementação | ⬜ Não iniciada |
| Fase atual | **Design / Documentação** |

Esta documentação é viva. Cada decisão de design vira um documento. Cada decisão pendente fica registrada em `10-anexos/C-decisoes-pendentes.md` até ser fechada.

---

## Princípios (resumo de uma linha cada)

1. **O corpo não decide.** StackChan é Surface + Output Renderer. Toda decisão passa pelo Atlas Kernel Pipeline.
2. **Corpo é trocável, alma não.** Identidade do Atlas vive no Evidence Ledger, não no firmware.
3. **Vida = continuidade + reatividade + consistência.** Não é animação.
4. **Loops em camadas.** Reflexo local, deliberação remota, contemplação em background.
5. **Privacidade é fundação, não feature.** Captura passiva tem default conservador e modo privado físico.
6. **Iniciativa é governada.** Curator propõe; uma `interruption_policy` decide se interrompe.
7. **Modo degradado é explícito.** Sem Atlas, o robô mostra "desconectado" — não finge.
8. **Tudo relevante vira Evidence.** Eventos físicos (toque, presença, mute) também.

---

## Como navegar esta documentação

A doc é progressiva: cada pasta aprofunda a anterior. Leitura sequencial é recomendada na primeira passada.

### Índice mestre

```
docs/embodiment/
├── README.md  ← você está aqui
│
├── 00-introducao/
│   ├── 01-visao-geral.md           Por que existir, quem se beneficia, escopo
│   ├── 02-glossario.md             Termos do projeto e do Atlas
│   └── 03-historico-decisoes.md    ADR — por que cada escolha foi feita
│
├── 01-hardware/                     ◄─ ENTENDER O QUE TEMOS
│   ├── 01-componentes.md           Cada componente, função no Atlas
│   ├── 02-limitacoes-fisicas.md    Orçamento real de CPU/RAM/banda/bateria
│   ├── 03-orcamento-recursos.md    Quanto cada feature consome
│   └── 04-expansoes-possiveis.md   Module-LLM, Grove, LEGO, periféricos
│
├── 02-arquitetura/                  ◄─ COMO ENCAIXAR NO ATLAS
│   ├── 01-corpo-vs-alma.md         Separação StackChan / Atlas
│   ├── 02-loops-temporais.md       Reflex, reaction, deliberation, contemplation, heartbeat
│   ├── 03-modalidades.md           Canais de input/output e o que cada um carrega
│   ├── 04-integracao-kernel.md     Como o robô entra no pipeline existente
│   └── 05-modos-operacao.md        Online / degradado / privado / standby
│
├── 03-camadas-de-vida/              ◄─ O QUE "VIDA" SIGNIFICA
│   ├── 01-presenca.md              L1 — existe e responde
│   ├── 02-reatividade.md           L2 — reage sem ser perguntado
│   ├── 03-continuidade.md          L3 — lembra, tem rituais
│   ├── 04-iniciativa.md            L4 — começa interação dentro de policy
│   └── 05-carater.md               L5 — personalidade emergente consistente
│
├── 04-protocolos/                   ◄─ CONTRATOS ENTRE CORPO E ALMA
│   ├── 01-interaction-envelope.md  Schema canônico de input multimodal
│   ├── 02-eventos-evidence.md      Eventos físicos no Evidence Ledger
│   ├── 03-comandos-fisicos.md      Como Atlas comanda servo/face/voz/LED
│   ├── 04-transporte.md            WebSocket / MQTT / fallback
│   └── 05-streaming.md             ASR / TTS / face streaming pra latência percebida
│
├── 05-policies/                     ◄─ AS REGRAS DURAS
│   ├── 01-privacidade.md           Mic, câmera, modo privado físico
│   ├── 02-interrupcao.md           Quando o Atlas pode falar primeiro
│   ├── 03-autonomia.md             O que pode e o que precisa de aprovação
│   └── 04-degradacao.md            Comportamento quando offline
│
├── 06-firmware-stackchan/           ◄─ O QUE RODA NO ROBÔ
│   ├── 01-escolha-stack.md         Arduino vs Moddable vs UiFlow2 — trade-offs
│   ├── 02-reflex-layer.md          Comportamento local sem Atlas
│   ├── 03-renderers.md             Face, voz, servo, LED como módulos
│   ├── 04-build-flash.md           Pipeline de build, flash, OTA
│   └── 05-monitoramento.md         Telemetria do robô pro Atlas
│
├── 07-integracao-atlas/             ◄─ O QUE MUDA NO ATLAS
│   ├── 01-surface-adapter.md       Registro do StackChan como surface
│   ├── 02-output-renderer.md       Renderer especializado físico
│   ├── 03-personalidade-ledger.md  Eventos de relacionamento
│   ├── 04-curator-proposals.md     Como Curator propõe interação
│   ├── 05-domain-faces.md          Mapeamento Domain → expressão facial
│   ├── 06-stackchan-bridge-persona.md  Bridge Atlas + preservação da voz/persona StackChan
│   └── 06-host-daemon/             ◄─ Atlas Host Daemon (módulo dedicado)
│       ├── README.md               Visão geral + índice + caminhos por objetivo
│       ├── 01-visao-geral.md       Definição, princípios, arquitetura interna
│       ├── 02-apis-macos.md        IOPMAssertion, NSWorkspace, pmset, Swift
│       ├── 03-configuracao-lifecycle.md  LaunchAgent, plist, install, troubleshooting
│       ├── 04-protocolo-atlas-audit.md   Unix socket, mensagens, Evidence events
│       └── 05-edge-cases-testes.md       Edge cases, testes, critérios de aceitação
│
├── 08-roadmap/                      ◄─ ORDEM DE IMPLEMENTAÇÃO
│   ├── 01-fase-0-espelho.md        Display passivo do Evidence Ledger
│   ├── 02-fase-1-voz.md            Wake word + STT/TTS streaming
│   ├── 03-fase-2-corpo.md          Servos + LEDs + face expressiva
│   ├── 04-fase-3-continuidade.md   Memória relacional + rituais
│   ├── 05-fase-4-iniciativa.md     Curator iniciando interação
│   └── 06-fase-5-carater.md        Emergente — sem código novo
│
├── 09-testes/                       ◄─ COMO SABER SE ESTÁ FUNCIONANDO
│   ├── 01-criterios-aceitacao.md   Por fase, o que conta como pronto
│   ├── 02-cenarios-uso.md          Casos reais de interação
│   └── 03-anti-padroes.md          O que sinaliza que algo virou demo
│
└── 10-anexos/
    ├── A-comunidade.md             Ishikawa, Takao, robo8080 — repos e canais
    ├── B-referencias-tecnicas.md   Datasheets, libs, docs externas
    ├── C-decisoes-pendentes.md     Perguntas abertas que travam coisas
    └── D-vocabulario-atlas.md      Frases-assinatura do Atlas
```

### Caminho rápido por interesse

- **Quer entender o que dá pra fazer?** → `01-hardware/` + `03-camadas-de-vida/`
- **Quer começar a implementar?** → `08-roadmap/01-fase-0-espelho.md`
- **Vai mexer em código do Atlas?** → `02-arquitetura/` + `07-integracao-atlas/`
- **Vai mexer em firmware?** → `06-firmware-stackchan/`
- **Está revisando segurança/privacidade?** → `05-policies/`

---

## Glossário rápido

Glossário completo em `00-introducao/02-glossario.md`. Os essenciais:

| Termo | Significado |
|---|---|
| **Atlas** | O sistema. O kernel cognitivo. A "alma". |
| **Embodiment** | Este projeto. Dar corpo físico ao Atlas. |
| **StackChan** | O hardware (M5Stack K151). O "corpo". |
| **Surface** | Camada de entrada do Atlas (CLI, app, web, **stackchan**). |
| **Output Renderer** | Camada de saída — formata a Decisão para o canal. |
| **Decision Receipt** | Contrato emitido pelo Atlas Decide. Tudo executa em cima dele. |
| **Evidence Ledger** | Log append-only de todos os eventos relevantes. |
| **Curator** | Sub-sistema que propõe melhorias e revisões. Não autoaplica. |
| **Reflex layer** | Comportamento local no firmware, sem chamar Atlas. |
| **Domain** | Programming, Finance, Personal Dev, Marketing, Self-Improvement. |
| **Camada de vida (L1–L5)** | Níveis empilháveis de "estar vivo": presença → caráter. |

---

## Decisões pendentes (alto nível)

Detalhes em `10-anexos/C-decisoes-pendentes.md`. As 5 que travam tudo:

1. **Cliente fino vs nó autônomo?** Atlas central no PC ou robô fala direto com providers?
2. **Onde mora a personalidade em runtime?** Só no Ledger? Cache derivado?
3. **Privacy defaults** — opt-in ou opt-out por modalidade?
4. **Quem tem autoridade pra interromper o usuário?**
5. **Comportamento offline** — degradação visível e como.

---

## Próximos documentos a escrever

Em ordem de prioridade. Marcados ✅ os já escritos.

**Fundação (concluída):**

1. ✅ `README.md` (este arquivo)
2. ✅ `01-hardware/01-componentes.md`
3. ✅ `01-hardware/02-limitacoes-fisicas.md`
4. ✅ `00-introducao/01-visao-geral.md`
5. ✅ `02-arquitetura/01-corpo-vs-alma.md`
6. ✅ `02-arquitetura/02-loops-temporais.md`
7. ✅ `04-protocolos/01-interaction-envelope.md`
8. ✅ `08-roadmap/01-fase-0-espelho.md`

**Suporte à Fase 0 implementável (concluída):**

9. ✅ `04-protocolos/03-comandos-fisicos.md` — schema dos comandos alma→corpo
10. ✅ `04-protocolos/04-transporte.md` — WebSocket, autenticação, reconexão
11. ✅ `06-firmware-stackchan/01-escolha-stack.md` — Arduino vs Moddable vs UiFlow2
12. ✅ `07-integracao-atlas/01-surface-adapter.md` — registro do StackChan no Atlas
13. ✅ `05-policies/01-privacidade.md` — defaults de mic/câmera, modo privado físico

**Preparação Fase 1 — voz (concluída):**

14. ✅ `02-arquitetura/03-modalidades.md`
15. ✅ `02-arquitetura/04-integracao-kernel.md`
16. ✅ `02-arquitetura/05-modos-operacao.md`
17. ✅ `04-protocolos/02-eventos-evidence.md`
18. ✅ `04-protocolos/05-streaming.md`

**Camadas de vida (concluída):**

19. ✅ `03-camadas-de-vida/01-presenca.md` — L1
20. ✅ `03-camadas-de-vida/02-reatividade.md` — L2
21. ✅ `03-camadas-de-vida/03-continuidade.md` — L3
22. ✅ `03-camadas-de-vida/04-iniciativa.md` — L4
23. ✅ `03-camadas-de-vida/05-carater.md` — L5

**Roadmap completo (concluído):**

24. ✅ `08-roadmap/02-fase-1-voz.md` — voz bidirecional + L1
25. ✅ `08-roadmap/03-fase-2-corpo.md` — expressão física + L2
26. ✅ `08-roadmap/04-fase-3-continuidade.md` — heartbeat + memória relacional + L3
27. ✅ `08-roadmap/05-fase-4-iniciativa.md` — Curator + interruption_policy + L4
28. ✅ `08-roadmap/06-fase-5-carater.md` — fase sem fase, emergência + L5

**Firmware + Integração Atlas (concluído):**

29. ✅ `06-firmware-stackchan/02-reflex-layer.md` — L0 + sensores + anti-jitter
30. ✅ `06-firmware-stackchan/03-renderers.md` — face/card/LED/servo/audio/IR
31. ✅ `06-firmware-stackchan/04-build-flash.md` — PlatformIO + OTA + provisioning
32. ✅ `06-firmware-stackchan/05-monitoramento.md` — telemetria + logs + crash dumps
33. ✅ `07-integracao-atlas/02-output-renderer.md` — Receipt → bundles físicos
34. ✅ `07-integracao-atlas/03-personalidade-ledger.md` — identidade + eventos relacionais
35. ✅ `07-integracao-atlas/04-curator-proposals.md` — fluxo Curator → corpo
36. ✅ `07-integracao-atlas/05-domain-faces.md` — mapping Domain → expressão visual
37. ✅ `07-integracao-atlas/06-stackchan-bridge-persona.md` — Bridge Atlas + Voice/Persona Adapter

**Atlas Host Daemon (módulo dedicado, concluído):**

38. ✅ `07-integracao-atlas/06-host-daemon/README.md`
39. ✅ `07-integracao-atlas/06-host-daemon/01-visao-geral.md`
40. ✅ `07-integracao-atlas/06-host-daemon/02-apis-macos.md`
41. ✅ `07-integracao-atlas/06-host-daemon/03-configuracao-lifecycle.md`
42. ✅ `07-integracao-atlas/06-host-daemon/04-protocolo-atlas-audit.md`
43. ✅ `07-integracao-atlas/06-host-daemon/05-edge-cases-testes.md`

**Rodadas posteriores (policies restantes, anexos, hardware/expansões, testes):**

- ⬜ `00-introducao/02-glossario.md`, `03-historico-decisoes.md`
- ⬜ `01-hardware/03-orcamento-recursos.md`, `04-expansoes-possiveis.md`
- ⬜ `05-policies/02-interrupcao.md`, `03-autonomia.md`, `04-degradacao.md`
- ⬜ `09-testes/01-criterios-aceitacao.md` … `03-anti-padroes.md`
- ⬜ `10-anexos/A-comunidade.md` … `D-vocabulario-atlas.md`

A ordem privilegia: **primeiro entender o que temos**, depois **definir contratos**, depois **especificar a fase mais simples**, depois **detalhar suporte para implementá-la**.

---

## Convenções desta documentação

- **Idioma:** PT-BR. Termos técnicos consagrados em inglês ficam em inglês (ex.: Surface, Decision Receipt, Evidence Ledger).
- **Tom:** direto, denso. Sem inflar.
- **Decisões abertas** ficam marcadas com `⚠️ DECISÃO PENDENTE:` no corpo do texto.
- **Trade-offs** são apresentados como tabelas comparativas, não como "lista de prós e contras".
- **Não há números de versão.** A doc é viva; o que importa é o último commit.
- **Cada documento tem cabeçalho** com: propósito, pré-requisitos de leitura, e o que NÃO está no escopo.
