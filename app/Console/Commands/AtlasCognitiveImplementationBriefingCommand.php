<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveImplementationBriefingService;
use Illuminate\Console\Command;
use Throwable;

class AtlasCognitiveImplementationBriefingCommand extends Command
{
    protected $signature = 'atlas:aaeos:cognitive-implementation-briefing
        {--action=briefing : briefing|roster|readiness|status|command|reading-order}
        {--status= : status token for the status action}
        {--command= : command string for the command action}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas AI Cognitive Plane Implementation Briefing — status taxonomy readiness, command policy and AP roster read model.';

    public function handle(AtlasCognitiveImplementationBriefingService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        try {
            $payload = match ($action) {
                'briefing' => $svc->briefing(),
                'roster' => $svc->apRoster(),
                'readiness' => $svc->readiness(),
                'status' => $svc->classifyStatus((string) ($this->option('status') ?? '')),
                'command' => $svc->classifyCommand((string) ($this->option('command') ?? '')),
                'reading-order' => ['reading_order' => $svc->readingOrder()],
                default => throw new \InvalidArgumentException("Unknown action '{$action}'."),
            };
        } catch (Throwable $e) {
            $envelope = [
                'ok' => false,
                'error' => $e->getMessage(),
                'action' => $action,
            ];
            $this->line((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) ? "{$k}: {$v}" : "{$k}: ".json_encode($v, JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }
}
