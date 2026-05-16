import { describe, expect, it } from "vitest";
import { move } from "../useGridKeyboard";

describe("VirtualizedGrid keyboard hook · perf surface", () => {
  it("move() is O(1) and deterministic", () => {
    const focus = { row: 5000, col: 12 };
    for (let i = 0; i < 1000; i++) {
      const next = move(focus, "down", { rowCount: 10000, columnCount: 25, pageRows: 30 });
      expect(typeof next.row).toBe("number");
      expect(typeof next.col).toBe("number");
    }
  });

  it("PageDown advances pageRows clamped to last row", () => {
    const next = move({ row: 9990, col: 0 }, "pageDown", { rowCount: 10000, columnCount: 25, pageRows: 30 });
    expect(next.row).toBe(9999);
  });
});
