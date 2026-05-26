<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Illuminate\Console\Command;

/**
 * Atlas Constitutional Kernel — CLI read/validate interface (Patamar 4 · 4.0).
 *
 * Doc canon: docs/engineering-knowledge-base/atlas-constitutional-kernel.md
 *
 * Actions:
 *   list-invariants [--class=petreo|elastic|runtime]
 *   validate        --change-json='{...}'
 *   list-violations
 *   kernel-hash
 */
class AtlasConstitutionalKernelCommand extends Command
{
    protected $signature = 'atlas:constitutional:kernel
        {--action=list-invariants : list-invariants|validate|list-violations|kernel-hash}
        {--class= : petreo|elastic|runtime (for list-invariants)}
        {--change-json= : JSON envelope of the proposed change (for validate)}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Constitutional Kernel — read pétreo invariants and validate proposed autonomous changes.';

    public function handle(AtlasConstitutionalKernelService $svc): int
    {
        $action = (string) $this->option('action');
        $json = (bool) $this->option('json');

        switch ($action) {
            case 'list-invariants':
                $class = $this->option('class');
                $class = is_string($class) && $class !== '' ? $class : null;
                try {
                    $list = $svc->listInvariants($class);
                } catch (\InvalidArgumentException $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit(['count' => count($list), 'invariants' => $list], $json);

            case 'validate':
                $raw = (string) ($this->option('change-json') ?? '');
                if ($raw === '') {
                    $this->error('--change-json=... obrigatório.');

                    return self::FAILURE;
                }
                $change = json_decode($raw, true);
                if (! is_array($change)) {
                    $this->error('change-json inválido (não decoda como objeto).');

                    return self::FAILURE;
                }
                $env = $svc->validateChange($change);

                return $this->emit($env, $json);

            case 'list-violations':
                $list = $svc->listViolations();

                return $this->emit(['count' => count($list), 'violations' => $list], $json);

            case 'kernel-hash':
                return $this->emit(['kernel_hash' => $svc->kernelHash()], $json);

            default:
                $this->error("action '{$action}' desconhecida.");

                return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) ? "{$k}: {$v}" : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
