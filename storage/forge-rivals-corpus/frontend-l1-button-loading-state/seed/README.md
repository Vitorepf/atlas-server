# Seed · frontend-l1-button-loading-state

`SignupSubmitButton` does not reflect the submitting state. Add an
`isSubmitting` prop that:

- Disables the underlying `<button>`.
- Renders a spinner alongside the label.
- Sets `aria-busy="true"` on the button while submitting.
- Restores the idle state when `isSubmitting` flips back to false.
- Suppresses click handler invocation while submitting.

The test scaffold exercises idle → submitting → idle.

## Files
- `SignupSubmitButton.tsx` — current component.
- `__tests__/SignupSubmitButton.test.tsx` — @testing-library/react harness.

## Pass criteria
```
vitest run components/forge/__tests__/SignupSubmitButton.test.tsx --reporter=basic
```
