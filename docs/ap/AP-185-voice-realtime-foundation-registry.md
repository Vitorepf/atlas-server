# AP-185 - Voice Realtime Foundation Registry

Status: `foundation-registry-implemented`  
Owner: Atlas Kernel / Voice Realtime  
Last updated: 2026-05-08

## 1. Proposito

AP-185 agrega AP-181, AP-182, AP-183 e AP-184 em um registro read-only.

Ele existe para dar ao Codex principal e aos humanos um mapa claro da fundacao de Voice Realtime: quais contratos existem, quais gates passam, qual pipeline esta pronto e onde a integracao real deve ocorrer.

## 2. Escopo Implementado

- `AtlasVoiceRealtimeFoundationRegistry`
- teste unitario dedicado
- readiness gates de fundacao
- boundaries explicitos para proxima integracao

## 3. Autoridade

Schema: `atlas.voice_realtime.foundation_registry.v1`  
Modo: `read_only_registry`  
Autoridade: `foundation_status_only_no_runtime_execution`

O registry nao executa runtime, nao chama Kernel, nao grava ledger e nao cria receipt.

## 4. Componentes Agregados

- AP-181: callback payload contract
- AP-182: callback sequence contract
- AP-183: runtime event normalizer
- AP-184: kernel handoff contract

## 5. Gates

- payload bloqueia audio cru
- payload bloqueia texto cru de resposta
- sequencia exige entrada antes de turno
- normalizer mapeia aliases de runtime
- handoff exige envelope/receipt para turn decision
- callbacks de runtime exigem receipt existente
- fundacao nao tem autoridade de execucao

## 6. Proximo Ponto Permitido

Quando a area quente estiver livre, o proximo passo permitido e:

`connect_through_authorized_adapter_with_existing_kernel_methods`

Isto significa conectar por adapter autorizado aos metodos existentes de `AtlasVoiceRealtimeService`, nao criar fluxo paralelo.

## 7. Nao Escopo

- nao cria API
- nao cria comando
- nao altera Architecture Operations
- nao altera Python runtime
- nao grava Evidence Ledger
- nao emite Decision Receipt
- nao chama provider

## 8. Definition of Done

- registry lista os quatro componentes
- readiness retorna `ready`
- boundaries declaram o que pode conectar e o que nao pode
- testes provam ausencia de autoridade de execucao
