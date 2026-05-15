# arena-bugfix-off-by-one-paginator · seed

Bug: total_pages = floor(count / limit) descarta o último item quando count
é múltiplo exato. Operador vê "Page 5 de 4". Fix mínimo (≤ 20 linhas),
regression tests cobrindo count=0, count=20 limit=5, count=21 limit=5.
