# Seed · test-regression-before-fix

DateRange::contains() retorna `true` para uma data fora do range (off-by-one no limite superior). O arm precisa:

1. Escrever um teste vermelho que reproduz o bug ANTES de tocar a implementação.
2. Aplicar o fix mínimo em `DateRange::contains()`.
3. Resultado final: teste verde, sem ter sido relaxado.

A política do projeto exige red-then-green proof — bugfix sem teste de regressão não merge.

## Arquivos
- `DateRange.php` — bug presente.
- `DateRangeTest.php` — cobre o caso happy, mas falta o teste de regressão.

## Como o arm sabe que acertou
```
php artisan test --filter='DateRangeTest'
```
Todos os testes verdes, incluindo um novo `test_contains_excludes_dates_after_end`.

## Como o arm sabe que errou
- Patch acrescenta fix em DateRange sem adicionar o teste novo.
- Teste existente foi modificado para acomodar o bug.
