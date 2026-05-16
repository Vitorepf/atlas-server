import React from "react";

export interface SignupSubmitButtonProps {
  label: string;
  onSubmit: () => void;
  /**
   * SEED: prop declared but ignored. The arm must wire it to the
   * disabled state, the aria-busy attribute, the spinner and the
   * click handler suppression.
   */
  isSubmitting?: boolean;
}

/**
 * Submit button used in the signup form.
 *
 * Acceptance:
 *   - idle      → label, no spinner, enabled, aria-busy="false".
 *   - submitting → label + spinner, disabled, aria-busy="true",
 *                  click handler must not fire.
 *   - restored  → back to idle state when isSubmitting flips false.
 */
export function SignupSubmitButton({ label, onSubmit, isSubmitting }: SignupSubmitButtonProps): JSX.Element {
  return (
    <button type="submit" onClick={onSubmit}>
      {label}
    </button>
  );
}
