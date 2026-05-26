<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\CrossDomain\AtlasTemporaryDomainCompositionService;
use Illuminate\Console\Command;

class AtlasTemporaryDomainCommand extends Command
{
    protected $signature = 'atlas:temporary-domain
        {--action=compose : compose|evaluate|expire|list-active|list-all}
        {--input-json= : JSON envelope for compose}
        {--capsule-id= : capsule_id for evaluate/expire}
        {--reason= : reason for expire}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Temporary Domain Composition — compose K-domain capsules with TTL composer over ACDM + Kernel + Admission.';

    public function handle(AtlasTemporaryDomainCompositionService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        try {
            switch ($action) {
                case 'compose':
                    $raw = (string) ($this->option('input-json') ?? '');
                    if ($raw === '') {
                        $this->error('--input-json=... obrigatório.');

                        return self::FAILURE;
                    }
                    $input = json_decode($raw, true);
                    if (! is_array($input)) {
                        $this->error('input-json inválido.');

                        return self::FAILURE;
                    }

                    return $this->emit($svc->compose($input), $json);
                case 'evaluate':
                    $cid = (string) ($this->option('capsule-id') ?? '');
                    if ($cid === '') {
                        $this->error('--capsule-id=... obrigatório.');

                        return self::FAILURE;
                    }

                    return $this->emit($svc->evaluateCapsule($cid), $json);
                case 'expire':
                    $cid = (string) ($this->option('capsule-id') ?? '');
                    $reason = (string) ($this->option('reason') ?? 'operator_request');
                    if ($cid === '') {
                        $this->error('--capsule-id=... obrigatório.');

                        return self::FAILURE;
                    }

                    return $this->emit($svc->expireCapsule($cid, $reason), $json);
                case 'list-active':
                    $list = $svc->listActiveCapsules();

                    return $this->emit(['count' => count($list), 'capsules' => $list], $json);
                case 'list-all':
                    $list = $svc->listAllCapsules();

                    return $this->emit(['count' => count($list), 'capsules' => $list], $json);
                default:
                    $this->error("Unknown action '{$action}'.");

                    return self::FAILURE;
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

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
