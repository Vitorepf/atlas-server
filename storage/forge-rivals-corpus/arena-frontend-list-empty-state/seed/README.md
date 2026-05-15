# arena-frontend-list-empty-state · seed

Estado inicial: lista renderiza vazia como hairline silencioso.

## Files

- `CaptureList.tsx` — lista atual sem empty state acessível.
- `__tests__/CaptureList.test.tsx` — teste atual (renderiza N items).

## Objetivo

Adicionar `CaptureListEmpty.tsx` com region role=status, anúncio via
aria-live, CTA reset filtro. Test cobre anúncio + render populada continua
verde.
