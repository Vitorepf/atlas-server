# Seed · backend-l2-currency-formatter

Implement `App\Support\CurrencyFormatter::format(int $cents, string $currency, string $locale): string`.

Requirements:

- Input is integer cents (no float arithmetic).
- Locale-specific thousands + decimal separator:
  - `pt_BR` → thousands `.`, decimal `,`, prefix `R$ `
  - `en_US` → thousands `,`, decimal `.`, prefix `$`
  - `de_DE` → thousands `.`, decimal `,`, suffix ` €`
- Unknown locale falls back to `en_US` formatting with the ISO currency
  code as suffix (e.g. `12,345.67 ZZD`); MUST NOT throw.
- Negative values keep the minus sign before the prefix.
- No external library: pure PHP only.

## Files
- `CurrencyFormatter.php` — scaffold.
- `CurrencyFormatterTest.php` — locale × currency table.

## Pass criteria
```
php artisan test --filter='CurrencyFormatterTest'
```
