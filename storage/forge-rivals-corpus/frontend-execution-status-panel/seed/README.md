# Seed · frontend-execution-status-panel

ExecutionStatusPanel só mostra "loading". O arm precisa estender o componente para renderizar os 4 estados enterprise (running, blocked, passed, failed) com label visível e announcement aria-live, mantendo loading como estado inicial. Sem pulse halo cafona.

## Arquivos
- `ExecutionStatusPanel.tsx` — versão seed: só "loading".
- `__tests__/ExecutionStatusPanel.test.tsx` — vitest + testing-library cobrindo os 4 estados + aria-live.

## Como o arm sabe que acertou
```
vitest run components/forge/__tests__/ExecutionStatusPanel.test.tsx --reporter=basic
```
