# Seed · frontend-filterable-table

FilterableTable não filtra, não ordena, não tem empty state. O arm precisa implementar busca por substring, sort estável por coluna clicável, e empty state acessível (role=status + aria-live=polite) citando o filtro ativo.

## Arquivos
- `FilterableTable.tsx` — versão seed: dumb table sem filtros.
- `__tests__/FilterableTable.test.tsx` — vitest cobrindo busca, sort, empty state.

## Como o arm sabe que acertou
```
vitest run components/forge/__tests__/FilterableTable.test.tsx --reporter=basic
```
