# arena-bugfix-null-pointer-in-formatter · seed

TypeError em painel admin quando captura legacy sem `updated_at` é
renderizada. Fix: retornar string vazia em null; preservar ValueError
em mal-formada; preservar DateTime válido.
