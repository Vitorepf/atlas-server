<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Vox\Gate\VoxV68CertificationService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasVoxV68CertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:vox:v6-8-certify
        {--json : Print machine-readable JSON envelope}
        {--strict : Treat warn as non-zero exit code}';

    protected $description = 'Certify Atlas Vox V6.8 Cognitive Flow Governor. Read-only, local-only.';

    public function handle(VoxV68CertificationService $service): int
    {
        try {
            $envelope = $service->build();
        } catch (Throwable $e) {
            $this->error('atlas:vox:v6-8-certify exceção fatal: '.$e->getMessage());

            return 2;
        }

        $status = (string) ($envelope['status'] ?? VoxV68CertificationService::STATUS_FAIL);
        if ($this->option('json')) {
            $this->jsonLine($envelope);
        } else {
            $this->renderHuman($envelope);
        }

        if ($status === VoxV68CertificationService::STATUS_FAIL) {
            return 1;
        }
        if ($status === VoxV68CertificationService::STATUS_WARN && $this->option('strict')) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function renderHuman(array $envelope): void
    {
        $status = (string) ($envelope['status'] ?? VoxV68CertificationService::STATUS_FAIL);
        $this->line('Atlas Vox V6.8 · Cognitive Flow Governor');
        $this->line('');
        $this->line('  status   '.($status === VoxV68CertificationService::STATUS_PASS ? '<info>PASS</info>' : '<error>FAIL</error>'));
        $this->line('  pronto   '.(($envelope['v6_8_ready'] ?? false) ? '<info>sim</info>' : '<error>não</error>'));
        $this->line('  V7       '.(($envelope['v7_unlock_allowed'] ?? false) ? '<error>destravada</error>' : '<info>bloqueada por design</info>'));
        $this->line('');
        $this->line((string) ($envelope['summary']['verdict_pt_br'] ?? ''));
        $this->line('');

        foreach ((array) ($envelope['checks'] ?? []) as $check) {
            if (! is_array($check)) {
                continue;
            }
            $marker = ($check['status'] ?? '') === VoxV68CertificationService::STATUS_PASS
                ? '<info>✓</info>'
                : '<error>✗</error>';
            $this->line(sprintf(
                '  %s %-34s %s',
                $marker,
                (string) ($check['check'] ?? '?'),
                (string) ($check['message'] ?? ''),
            ));
        }

        $next = $envelope['next_actions'] ?? [];
        if (is_array($next) && $next !== []) {
            $this->line('');
            $this->line('Próximas ações:');
            foreach ($next as $item) {
                $this->line('  · '.$item);
            }
        }
    }
}
