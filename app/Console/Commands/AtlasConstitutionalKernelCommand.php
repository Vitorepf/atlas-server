<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

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
    use EmitsCanonicalJson;

    protected $signature = 'atlas:constitutional:kernel
        {--action=list-invariants : list-invariants|validate|list-violations|kernel-hash|elastic-state|flip-elastic|runtime-state|tune-runtime|runtime-windows}
        {--class= : petreo|elastic|runtime (for list-invariants)}
        {--change-json= : JSON envelope of the proposed change (for validate)}
        {--invariant= : invariant id (for elastic-state|flip-elastic|runtime-state|tune-runtime)}
        {--enabled= : true|false (for flip-elastic)}
        {--value= : new value (for tune-runtime; numeric or string from window)}
        {--actor= : operator name}
        {--reason= : human-readable reason}
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

            case 'elastic-state':
                $id = (string) ($this->option('invariant') ?? '');
                if ($id === '') {
                    // Report all elastic invariants effective state.
                    $rows = [];
                    foreach ($svc->listInvariants('elastic') as $inv) {
                        $rows[] = [
                            'invariant_id' => $inv['id'],
                            'effective_enabled' => $svc->isElasticEnabled($inv['id']),
                            'canonical_enabled' => $inv['enabled'],
                            'statement' => $inv['statement'],
                        ];
                    }

                    return $this->emit(['count' => count($rows), 'elastic_state' => $rows], $json);
                }
                try {
                    $state = $svc->isElasticEnabled($id);
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit(['invariant_id' => $id, 'effective_enabled' => $state], $json);

            case 'flip-elastic':
                $id = (string) ($this->option('invariant') ?? '');
                $enabledRaw = (string) ($this->option('enabled') ?? '');
                $actor = (string) ($this->option('actor') ?? '');
                $reason = (string) ($this->option('reason') ?? '');
                if ($id === '' || $enabledRaw === '' || $actor === '' || $reason === '') {
                    $this->error('flip-elastic requires --invariant, --enabled, --actor, --reason.');

                    return self::FAILURE;
                }
                $enabled = in_array(strtolower($enabledRaw), ['1', 'true', 'on', 'yes'], true);
                try {
                    $entry = $svc->flipElastic($id, $enabled, $actor, $reason);
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit($entry, $json);

            case 'runtime-windows':
                return $this->emit(['windows' => $svc->runtimeWindows()], $json);

            case 'runtime-state':
                $id = (string) ($this->option('invariant') ?? '');
                if ($id === '') {
                    $rows = [];
                    foreach ($svc->listInvariants('runtime') as $inv) {
                        $rows[] = [
                            'invariant_id' => $inv['id'],
                            'current_value' => $svc->currentRuntimeValue($inv['id']),
                            'window' => $svc->runtimeWindows()[$inv['id']] ?? null,
                            'statement' => $inv['statement'],
                        ];
                    }

                    return $this->emit(['count' => count($rows), 'runtime_state' => $rows], $json);
                }

                return $this->emit([
                    'invariant_id' => $id,
                    'current_value' => $svc->currentRuntimeValue($id),
                    'window' => $svc->runtimeWindows()[$id] ?? null,
                ], $json);

            case 'tune-runtime':
                $id = (string) ($this->option('invariant') ?? '');
                $valueRaw = (string) ($this->option('value') ?? '');
                $actor = (string) ($this->option('actor') ?? '');
                $reason = (string) ($this->option('reason') ?? '');
                if ($id === '' || $valueRaw === '' || $actor === '' || $reason === '') {
                    $this->error('tune-runtime requires --invariant, --value, --actor, --reason.');

                    return self::FAILURE;
                }
                // Coerce numeric values to int/float for range windows.
                $value = is_numeric($valueRaw)
                    ? (str_contains($valueRaw, '.') ? (float) $valueRaw : (int) $valueRaw)
                    : $valueRaw;
                try {
                    $entry = $svc->tuneRuntime($id, $value, $actor, $reason);
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit($entry, $json);

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
                $this->line(is_scalar($v) ? "{$k}: {$v}" : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
