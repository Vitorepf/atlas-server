---
title: Voice Realtime Python Runtime Boundary
status: implemented_ready
owner: Atlas Kernel / Voice Realtime
line_limit: 220
related_paths:
  - runtimes/python/voice_realtime
  - app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
  - docs/engineering-knowledge-base/kernel/static-scans.md
---

# AP-686 - Voice Realtime Python Runtime Boundary

## 1. Proposito

Garantir que o runtime Python de Voice Realtime, incluindo LiveKit Agents SDK,
continue sendo apenas runtime governado pelo Kernel, nunca um mini-Atlas
paralelo que decide provider, executa tool, grava memoria ou persiste audio cru.

## 2. Status Real

Implementado como scan estatico dentro de `architecture-validate`.

O scan `ap686_voice_realtime_python_runtime_boundary_contract` valida contratos
do pacote `runtimes/python/voice_realtime` e falha se a fronteira Python tentar
importar provider SDK, chamar endpoint de provider, usar shell/subprocess,
persistir audio cru ou aceitar segredo aninhado em metadata de SDK.

## 3. Escopo

- runtime Python chama somente endpoints declarados no contrato do Kernel;
- `AtlasKernelClient` usa URLs do `AtlasVoiceRuntimeContract`;
- callbacks de sintese, playback, interrupcao, falha e provider health exigem
  turno aceito com Decision Receipt;
- worker LiveKit rejeita provider/tool/raw/secret fields em qualquer nivel;
- session, turn, wake-word e callback payloads usam o mesmo reject recursivo;
- helper `payload_safety.py` varre payloads recursivamente antes de adaptar SDK;
- log payload remove token, API secret, audio cru e texto cru de resposta;
- dependencia core permanece standard library only;
- LiveKit Agents fica dependencia opcional com activation gate;
- testes Python cobrem payload, sequencia, runtime, nested metadata e sanitizacao.

## 4. Nao Escopo

- nao implementar streaming real de audio;
- nao escolher STT/TTS/LLM provider;
- nao iniciar daemon em producao;
- nao criar memoria, tool execution ou policy no Python;
- nao substituir AP-185 runtime certification;
- nao promover Voice Realtime para produto final.

## 5. Invariantes

- Python runtime executa; Kernel decide.
- LiveKit transporta; Kernel emite receipt.
- Provider SDK direto e proibido.
- Tool/shell direto e proibido.
- Audio cru e segredo nunca entram em log, Ledger ou artifact.
- Optional dependency nao vira permissao de autonomia.

## 6. Definition Of Done

- `python3 -m unittest` passa com `PYTHONPATH=runtimes/python/voice_realtime`;
- `php artisan atlas:ai:architecture-validate --json` mostra AP-686 valid;
- `docs/engineering-knowledge-base/kernel/static-scans.md` documenta AP-686;
- docs-health permanece verde;
- KB sync e Code Intelligence index foram atualizados.
