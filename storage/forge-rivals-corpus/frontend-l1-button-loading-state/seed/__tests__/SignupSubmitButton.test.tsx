import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { SignupSubmitButton } from "../SignupSubmitButton";

describe("SignupSubmitButton", () => {
  it("renders idle state with aria-busy=false and an enabled button", () => {
    const onSubmit = vi.fn();
    render(<SignupSubmitButton label="Sign up" onSubmit={onSubmit} />);

    const button = screen.getByRole("button", { name: /sign up/i });
    expect(button).toBeEnabled();
    expect(button.getAttribute("aria-busy")).toBe("false");
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("disables the button, shows a spinner and sets aria-busy=true while submitting", () => {
    const onSubmit = vi.fn();
    render(<SignupSubmitButton label="Sign up" onSubmit={onSubmit} isSubmitting />);

    const button = screen.getByRole("button");
    expect(button).toBeDisabled();
    expect(button.getAttribute("aria-busy")).toBe("true");
    // spinner should be exposed via role="status" or data-testid="spinner"
    expect(screen.getByRole("status")).toBeInTheDocument();

    fireEvent.click(button);
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it("restores idle state when isSubmitting flips back to false", () => {
    const onSubmit = vi.fn();
    const { rerender } = render(<SignupSubmitButton label="Sign up" onSubmit={onSubmit} isSubmitting />);
    rerender(<SignupSubmitButton label="Sign up" onSubmit={onSubmit} />);

    const button = screen.getByRole("button");
    expect(button).toBeEnabled();
    expect(button.getAttribute("aria-busy")).toBe("false");
  });
});
