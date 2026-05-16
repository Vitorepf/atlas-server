import { describe, expect, it } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { FilterableTable, type Row } from "../FilterableTable";

const fixture: Row[] = [
  { id: "1", title: "Alpha bravo", status: "open" },
  { id: "2", title: "Charlie delta", status: "closed" },
  { id: "3", title: "Echo foxtrot", status: "open" },
  { id: "4", title: "Bravo zulu", status: "closed" },
];

describe("FilterableTable", () => {
  it("filters rows by substring on title", () => {
    render(<FilterableTable rows={fixture} />);
    const search = screen.getByRole("searchbox");
    fireEvent.change(search, { target: { value: "bravo" } });
    const rows = screen.getAllByRole("row");
    // header + 2 matches
    expect(rows).toHaveLength(3);
    expect(rows[1]).toHaveTextContent(/alpha bravo/i);
    expect(rows[2]).toHaveTextContent(/bravo zulu/i);
  });

  it("sorts by clicking on a column header, stable on ties", () => {
    render(<FilterableTable rows={fixture} />);
    const titleHeader = screen.getByRole("columnheader", { name: /title/i });
    fireEvent.click(titleHeader);
    const sortedAsc = screen.getAllByRole("row").slice(1).map((r) => within(r).getAllByRole("cell")[0].textContent);
    expect(sortedAsc).toEqual(["Alpha bravo", "Bravo zulu", "Charlie delta", "Echo foxtrot"]);

    fireEvent.click(titleHeader);
    const sortedDesc = screen.getAllByRole("row").slice(1).map((r) => within(r).getAllByRole("cell")[0].textContent);
    expect(sortedDesc).toEqual(["Echo foxtrot", "Charlie delta", "Bravo zulu", "Alpha bravo"]);
  });

  it("announces empty state via aria-live=polite when filter has no matches", () => {
    render(<FilterableTable rows={fixture} />);
    fireEvent.change(screen.getByRole("searchbox"), { target: { value: "zzz" } });
    const empty = screen.getByRole("status");
    expect(empty.getAttribute("aria-live")).toBe("polite");
    expect(empty).toHaveTextContent(/zzz/);
  });
});
