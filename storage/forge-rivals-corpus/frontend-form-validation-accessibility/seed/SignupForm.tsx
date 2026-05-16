import React, { useState } from "react";

export interface SignupFormProps {
  onSubmit?: (values: { email: string; password: string }) => void;
}

/**
 * Formulário de signup.
 *
 * BUG (seed):
 *   - Inputs sem `<label htmlFor>` associado.
 *   - Sem validação client-side; submit envia mesmo com email vazio.
 *   - Sem `aria-describedby` apontando para mensagens de erro.
 *   - Não move foco para o primeiro campo inválido.
 *
 * O arm precisa:
 *   1. Adicionar `<label htmlFor="...">` para email e password.
 *   2. Validar no submit: email não-vazio + password.length >= 8.
 *   3. Renderizar mensagem de erro inline com `id="..."` e fazer o input apontar
 *      via `aria-describedby="..."`.
 *   4. Após submit inválido, mover foco programaticamente ao primeiro input inválido.
 */
export function SignupForm({ onSubmit }: SignupFormProps): JSX.Element {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");

  function handleSubmit(evt: React.FormEvent) {
    evt.preventDefault();
    // BUG: dispara onSubmit sem validar — e sem foco/erro acessível.
    onSubmit?.({ email, password });
  }

  return (
    <form onSubmit={handleSubmit} noValidate>
      <input
        type="email"
        value={email}
        onChange={(evt) => setEmail(evt.target.value)}
        placeholder="email"
      />
      <input
        type="password"
        value={password}
        onChange={(evt) => setPassword(evt.target.value)}
        placeholder="password"
      />
      <button type="submit">Sign up</button>
    </form>
  );
}
