import React from "react";

export interface VirtualizedGridProps {
  rowCount: number;
  columnCount: number;
  rowHeight?: number;
  /** Cell renderer; receives row and column indices. */
  renderCell: (row: number, col: number) => React.ReactNode;
}

/**
 * SEED IMPLEMENTATION — NON-VIRTUALIZED.
 *
 * This scaffold renders every row directly. The arm must:
 *   - Window the rendering so only the visible viewport (+ overscan)
 *     is in the DOM.
 *   - Add role="grid"/"row"/"gridcell" attributes.
 *   - Wire useGridKeyboard for arrow + Home/End + PageUp/PageDown.
 *   - Implement roving tabindex (only the focused cell is tabbable).
 */
export function VirtualizedGrid({ rowCount, columnCount, rowHeight = 24, renderCell }: VirtualizedGridProps): JSX.Element {
  return (
    <div data-testid="virtualized-grid">
      {Array.from({ length: rowCount }).map((_, r) => (
        <div key={r} style={{ height: rowHeight }}>
          {Array.from({ length: columnCount }).map((__, c) => (
            <span key={c}>{renderCell(r, c)}</span>
          ))}
        </div>
      ))}
    </div>
  );
}
