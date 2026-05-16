export interface GridFocus {
  row: number;
  col: number;
}

export interface MoveOptions {
  rowCount: number;
  columnCount: number;
  pageRows?: number; // how many rows PageUp/PageDown jump
}

export type Direction = "up" | "down" | "left" | "right" | "home" | "end" | "pageUp" | "pageDown";

/**
 * SEED: identity move — returns the same focus regardless of direction.
 * The arm must implement the canonical key navigation table.
 */
export function move(focus: GridFocus, direction: Direction, options: MoveOptions): GridFocus {
  return focus;
}
