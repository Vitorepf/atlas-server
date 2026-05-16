import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ExecutionStatusPanel } from "../ExecutionStatusPanel";

describe("ExecutionStatusPanel", () => {
  it("renders each enterprise state with its own visible label", () => {
    const states = ["running", "blocked", "passed", "failed"] as const;
    for (const state of states) {
      const { unmount } = render(<ExecutionStatusPanel status={state} />);
      expect(screen.getByRole("status")).toHaveTextContent(state);
      unmount();
    }
  });

  it("announces transitions via aria-live=polite", () => {
    render(<ExecutionStatusPanel status="running" />);
    const region = screen.getByRole("status");
    expect(region.getAttribute("aria-live")).toBe("polite");
  });

  it("keeps loading as the initial state", () => {
    render(<ExecutionStatusPanel status="loading" />);
    expect(screen.getByRole("status")).toHaveTextContent(/loading/i);
  });

  it("does not introduce pulse halo animation", () => {
    const { container } = render(<ExecutionStatusPanel status="running" />);
    const dot = container.querySelector(".status-dot");
    expect(dot?.className).not.toMatch(/pulse|halo|breathe/i);
  });
});
