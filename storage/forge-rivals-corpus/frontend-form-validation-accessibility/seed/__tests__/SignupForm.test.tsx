import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { SignupForm } from "../SignupForm";

describe("SignupForm", () => {
  it("associates a label with each input via htmlFor", () => {
    render(<SignupForm />);
    const emailInput = screen.getByLabelText(/email/i);
    const passwordInput = screen.getByLabelText(/password/i);
    expect(emailInput).toBeInTheDocument();
    expect(passwordInput).toBeInTheDocument();
  });

  it("shows inline errors on invalid submit and exposes them via aria-describedby", () => {
    const onSubmit = vi.fn();
    render(<SignupForm onSubmit={onSubmit} />);
    fireEvent.click(screen.getByRole("button", { name: /sign up/i }));

    const emailInput = screen.getByLabelText(/email/i);
    const describedById = emailInput.getAttribute("aria-describedby");
    expect(describedById, "input precisa apontar para mensagem de erro").not.toBeNull();
    const errorRegion = document.getElementById(describedById ?? "");
    expect(errorRegion).not.toBeNull();
    expect(errorRegion).toHaveTextContent(/email/i);
    expect(onSubmit, "submit inválido NÃO pode chamar handler").not.toHaveBeenCalled();
  });

  it("moves focus to the first invalid field after invalid submit", () => {
    render(<SignupForm />);
    fireEvent.click(screen.getByRole("button", { name: /sign up/i }));
    const emailInput = screen.getByLabelText(/email/i);
    expect(document.activeElement).toBe(emailInput);
  });

  it("calls onSubmit when both fields are valid", () => {
    const onSubmit = vi.fn();
    render(<SignupForm onSubmit={onSubmit} />);
    fireEvent.change(screen.getByLabelText(/email/i), { target: { value: "ada@example.com" } });
    fireEvent.change(screen.getByLabelText(/password/i), { target: { value: "longenoughpw" } });
    fireEvent.click(screen.getByRole("button", { name: /sign up/i }));
    expect(onSubmit).toHaveBeenCalledWith({ email: "ada@example.com", password: "longenoughpw" });
  });
});
