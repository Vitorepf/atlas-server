<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static guardrail for the finance strategy loop's hardest invariant:
 * propose-only means no broker, no keys, no order routing, no money path.
 */
final class StrategyNoExecutionSurfaceAudit
{
    /** @var array<string,string> */
    private const BANNED_PATTERNS = [
        '/\bccxt\b/i' => 'ccxt_dependency',
        '/\bcreateOrder\s*\(/i' => 'create_order_call',
        '/\bcreate_order\s*\(/i' => 'create_order_call',
        '/\bplaceOrder\s*\(/i' => 'place_order_call',
        '/\bplace_order\s*\(/i' => 'place_order_call',
        '/\bmarketBuy\s*\(/i' => 'market_buy_call',
        '/\bmarketSell\s*\(/i' => 'market_sell_call',
        '/\bfetchBalance\s*\(/i' => 'balance_fetch_call',
        '/\bfetch_balance\s*\(/i' => 'balance_fetch_call',
        '/\bfetchOpenOrders\s*\(/i' => 'open_orders_fetch_call',
        '/\bapiKey\b/' => 'api_key_reference',
        '/\bsecretKey\b/' => 'secret_key_reference',
        '/\bprivateKey\b/' => 'private_key_reference',
        '/[\'"]live_trading[\'"]\s*=>\s*[\'"]allowed[\'"]/i' => 'live_trading_allowed',
        '/"live_trading"\s*:\s*"allowed"/i' => 'live_trading_allowed',
    ];

    /**
     * @return array{passed:bool,violations:list<array<string,int|string>>}
     */
    public function scanDefault(): array
    {
        return $this->scanFiles($this->defaultFiles());
    }

    /**
     * @param  list<string>  $files
     * @return array{passed:bool,violations:list<array<string,int|string>>}
     */
    public function scanFiles(array $files): array
    {
        $violations = [];
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $lineNumber => $line) {
                foreach (self::BANNED_PATTERNS as $pattern => $reason) {
                    if (preg_match($pattern, (string) $line) === 1) {
                        $violations[] = [
                            'file' => $file,
                            'line' => $lineNumber + 1,
                            'reason' => $reason,
                        ];
                    }
                }
            }
        }

        return [
            'passed' => $violations === [],
            'violations' => $violations,
        ];
    }

    /** @return list<string> */
    private function defaultFiles(): array
    {
        $files = [];
        foreach (glob(app_path('Console/Commands/AtlasFinanceStrategy*.php')) ?: [] as $file) {
            if (str_ends_with($file, 'AtlasFinanceStrategyAdversarialAuditCommand.php')) {
                continue;
            }
            $files[] = $file;
        }
        foreach ($this->phpFiles(app_path('Services/Ai/Finance/StrategyLoop')) as $file) {
            if (str_ends_with($file, '/StrategyLoopAdversarialAudit.php')) {
                continue;
            }
            $files[] = $file;
        }
        foreach (glob(base_path('scripts/finance/*')) ?: [] as $file) {
            if (is_file($file)) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
