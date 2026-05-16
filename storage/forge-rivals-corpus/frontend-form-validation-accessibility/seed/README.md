# Seed · frontend-form-validation-accessibility

SignupForm não tem inline errors, não tem labels associadas, não move foco para o primeiro erro. O arm precisa adicionar validação acessível: erro inline por campo (id), input apontando via `aria-describedby`, `<label htmlFor>` em cada input, foco automático no primeiro campo inválido após submit.

## Arquivos
- `SignupForm.tsx` — versão seed: form sem a11y nem validação.
- `__tests__/SignupForm.test.tsx` — vitest cobrindo submit inválido + foco + aria-describedby.

## Como o arm sabe que acertou
```
vitest run components/forge/__tests__/SignupForm.test.tsx --reporter=basic
```
