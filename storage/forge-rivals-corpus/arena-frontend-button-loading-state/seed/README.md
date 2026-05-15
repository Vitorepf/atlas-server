# arena-frontend-button-loading-state · seed

Initial state for the case. The arm starts here and must satisfy
`acceptance_criteria` without touching `forbidden_files_scope`.

## Files

- `CaptureForm.tsx` — form sem estado de loading (estado actual).
- `Button.tsx` — componente UI básico sem prop `loading`.
- `__tests__/CaptureForm.test.tsx` — único teste atual (renderiza form, click submit chama handler).

## Objetivo do arm

Adicionar estado loading com:
- spinner enquanto submit em flight,
- `disabled=true` no botão,
- restauração em sucesso e erro,
- novo teste cobrindo o caso.
