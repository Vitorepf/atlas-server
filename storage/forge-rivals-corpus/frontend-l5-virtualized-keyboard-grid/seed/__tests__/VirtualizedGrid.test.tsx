import { describe, expect, it } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { VirtualizedGrid } from "../VirtualizedGrid";

describe("VirtualizedGrid", () => {
  it("uses role=grid on the container and role=row/gridcell internally", () => {
    render(<VirtualizedGrid rowCount={50} columnCount={3} renderCell={(r, c) => `${r},${c}`} />);
    const grid = screen.getByRole("grid");
    expect(grid).toBeInTheDocument();
    expect(screen.getAllByRole("row").length).toBeGreaterThan(0);
    expect(screen.getAllByRole("gridcell").length).toBeGreaterThan(0);
  });

  it("only renders a windowed slice of the rows", () => {
    render(<VirtualizedGrid rowCount={10000} columnCount={3} rowHeight={24} renderCell={(r, c) => `${r},${c}`} />);
    // The arm must window the rendering so the DOM size stays bounded.
    expect(screen.getAllByRole("row").length).toBeLessThan(200);
  });

  it("moves focus on arrow keys", () => {
    render(<VirtualizedGrid rowCount={5} columnCount={5} renderCell={(r, c) => `${r},${c}`} />);
    const grid = screen.getByRole("grid");
    fireEvent.keyDown(grid, { key: "ArrowDown" });
    fireEvent.keyDown(grid, { key: "ArrowRight" });
    const focused = document.activeElement;
    expect(focused).toBeTruthy();
    expect(focused?.getAttribute("data-row")).toBe("1");
    expect(focused?.getAttribute("data-col")).toBe("1");
  });

  it("home/end jump to row edges", () => {
    render(<VirtualizedGrid rowCount={5} columnCount={5} renderCell={(r, c) => `${r},${c}`} />);
    const grid = screen.getByRole("grid");
    fireEvent.keyDown(grid, { key: "End" });
    expect(document.activeElement?.getAttribute("data-col")).toBe("4");
    fireEvent.keyDown(grid, { key: "Home" });
    expect(document.activeElement?.getAttribute("data-col")).toBe("0");
  });

  it("roving tabindex keeps exactly one cell tabbable", () => {
    render(<VirtualizedGrid rowCount={5} columnCount={5} renderCell={(r, c) => `${r},${c}`} />);
    const cells = screen.getAllByRole("gridcell");
    const tabbable = cells.filter((cell) => cell.getAttribute("tabindex") === "0");
    expect(tabbable.length).toBe(1);
  });
});
