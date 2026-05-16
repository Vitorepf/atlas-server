# Seed · backend-pagination-off-by-one

PageCalculator perde o último page quando count é múltiplo do per_page (off-by-one clássico). O arm precisa corrigir o cálculo em `PageCalculator::lastPage()` para usar arredondamento para cima (ceil) sem quebrar o caso vazio.

## Arquivos
- `PageCalculator.php` — bug presente: usa divisão inteira que arredonda para baixo.
- `PageCalculatorTest.php` — 4 cenários, com um teste vermelho contra a versão atual.

## Como o arm sabe que acertou
```
php artisan test --filter='PageCalculatorTest'
```
Todos os 4 testes verdes.

## Como o arm sabe que errou
- Test `test_last_page_rounds_up_without_off_by_one` continua falhando.
- Test `test_last_page_for_empty_count_returns_one` falha (arm trocou comportamento da borda).
