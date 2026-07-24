<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Governance\AtlasConstitutionalVaultService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas Constitutional Vault CLI.
 *
 * Doc canon: docs/engineering-knowledge-base/atlas-constitutional-vault.md
 *
 *   sign --actor=<name> --reason=<text>     (operator-only)
 *   verify                                  read + signature check + kernel drift check
 *   show                                    raw vault content
 *   snapshot                                in-code Kernel snapshot (for inspection)
 */
class AtlasConstitutionalVaultCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:constitutional:vault
        {--action=verify : sign|verify|show|snapshot}
        {--actor= : operator name (for sign)}
        {--reason= : signing reason (for sign)}
        {--json}';

    protected $description = 'Atlas Constitutional Vault — separately-signed file outside source for kernel invariants verification.';

    public function handle(AtlasConstitutionalVaultService $svc): int
    {
        $action = (string) $this->option('action');
        $json = (bool) $this->option('json');

        switch ($action) {
            case 'sign':
                $actor = (string) ($this->option('actor') ?? '');
                $reason = (string) ($this->option('reason') ?? '');
                if ($actor === '' || $reason === '') {
                    $this->error('sign requires --actor and --reason.');

                    return self::FAILURE;
                }
                try {
                    $env = $svc->sign($actor, $reason);
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit($env, $json);

            case 'verify':
                return $this->emit($svc->verify(), $json);

            case 'show':
                return $this->emit($svc->read() ?? ['note' => 'no vault'], $json);

            case 'snapshot':
                return $this->emit($svc->kernelSnapshot(), $json);

            default:
                $this->error("action '{$action}' desconhecida.");

                return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line($this->encode($payload));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) || $v === null ? "{$k}: ".var_export($v, true) : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
