import React from "react";

export interface Row {
  id: string;
  title: string;
  status: "open" | "closed";
}

export interface FilterableTableProps {
  rows: Row[];
}

/**
 * Tabela filtrável de capturas.
 *
 * BUG (seed): renderiza todas as linhas cruas. Sem busca, sem sort, sem empty state.
 *
 * O arm precisa adicionar:
 *   1. Campo `<input role="searchbox">` que filtra por substring no `title`.
 *   2. Clique no header de coluna alterna sort ascendente/descendente (estável).
 *   3. Quando filter resulta em zero linhas, renderizar `<div role="status" aria-live="polite">`
 *      anunciando "Nenhum resultado para '<filtro>'".
 *   4. Patch toca apenas este arquivo + o test.
 */
export function FilterableTable({ rows }: FilterableTableProps): JSX.Element {
  return (
    <table>
      <thead>
        <tr>
          <th>Title</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((row) => (
          <tr key={row.id}>
            <td>{row.title}</td>
            <td>{row.status}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
