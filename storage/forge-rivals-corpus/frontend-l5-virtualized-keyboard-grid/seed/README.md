# Seed · frontend-l5-virtualized-keyboard-grid

Build `VirtualizedGrid` rendering 10k rows with full keyboard
navigation:

- Arrow keys (Up/Down/Left/Right) — cell focus walks one cell.
- Home/End — first/last cell on the current row.
- PageUp/PageDown — one viewport's worth.
- ARIA: container `role="grid"`, each row `role="row"`, each cell `role="gridcell"`.
- Roving `tabindex` (single tabbable cell at a time).

The provided scaffold renders all 10k DOM nodes (deliberately
non-virtualized). The arm must implement windowing and the keyboard
hook.

A perf budget test runs in DOM-mock mode and just asserts the keyboard
hook can advance through 1000 cells in O(n) (no quadratic scans). The
real frame-budget snapshot lives in the repo under
`__tests__/VirtualizedGrid.perf.test.tsx` and only enforces that
`useGridKeyboard.move()` returns deterministically.

## Files
- `VirtualizedGrid.tsx`, `useGridKeyboard.ts` — scaffolds.
- `__tests__/VirtualizedGrid.test.tsx` — behaviour tests.
- `__tests__/VirtualizedGrid.perf.test.tsx` — perf hook stub.

## Pass criteria
```
vitest run components/forge/__tests__/VirtualizedGrid.test.tsx --reporter=basic
```
