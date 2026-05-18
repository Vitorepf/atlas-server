<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Kernel\ProgrammingAdapterException;
use App\Services\Ai\Programming\Kernel\ProgrammingAdapterReadinessService;
use App\Services\Ai\Programming\Kernel\ProgrammingAdapterSmokeService;
use App\Services\Ai\Programming\Kernel\ProgrammingControlPlaneProjection;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiProgrammingAdapterCommand extends Command
{
    protected $signature = 'atlas:ai:programming-adapter
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, smoke, control-plane}
        {--dev-prompt= : Dev prompt for smoke}
        {--forge-prompt= : Forge prompt for smoke}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Programming Domain Adapter: bridges Atlas Dev/Forge into Kernel (Mission, Domain Runtime, Policy, Evidence, Tool Runtime).';

    public function handle(
        ProgrammingAdapterReadinessService $readiness,
        ProgrammingAdapterSmokeService $smoke,
        ProgrammingControlPlaneProjection $controlPlane,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'smoke' => $this->renderSmoke($smoke),
                'control-plane' => $this->renderControlPlane($controlPlane),
                default => $this->invalidAction($action),
            };
        } catch (ProgrammingAdapterException $e) {
            return $this->renderError('programming_adapter_exception', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->renderError('invalid_argument', $e->getMessage());
        } catch (Throwable $e) {
            return $this->renderError('exception', $e->getMessage(), $e::class);
        }
    }

    private function renderReadiness(ProgrammingAdapterReadinessService $readiness): int
    {
        $payload = $readiness->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', $payload['ok'] ? 'true' : 'false');
            $this->components->twoColumnDetail('passed', (string) $payload['summary']['passed']);
            $this->components->twoColumnDetail('failed', (string) $payload['summary']['failed']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSmoke(ProgrammingAdapterSmokeService $smoke): int
    {
        $payload = $smoke->run(
            $this->stringOption('dev-prompt'),
            $this->stringOption('forge-prompt'),
        );
        $payload['schema'] = 'atlas.ai.programming.smoke.v1';
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('manifest', (string) $payload['manifest']['domain_id']);
            $this->components->twoColumnDetail('dev.mission_status', (string) $payload['dev']['mission_status']);
            $this->components->twoColumnDetail('dev.certification_status', (string) $payload['dev']['certification_status']);
            $this->components->twoColumnDetail('forge.handoff_status', (string) $payload['forge']['handoff_status']);
            $this->components->twoColumnDetail('forge.why_escalated', (string) $payload['forge']['why_escalated']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderControlPlane(ProgrammingControlPlaneProjection $controlPlane): int
    {
        $payload = $controlPlane->snapshot();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('open_work_orders', (string) $payload['programming']['open_work_orders']);
            $this->components->twoColumnDetail('missions_total', (string) $payload['programming']['missions_total']);
            $this->components->twoColumnDetail('dev_to_forge_escalations', (string) $payload['programming']['dev_to_forge_escalations']);
            $this->components->twoColumnDetail('evidence_pack_count', (string) $payload['programming']['evidence_pack_count']);
            foreach ($payload['bridges'] as $key => $value) {
                $this->components->twoColumnDetail('bridge:'.$key, $value ? 'true' : 'false');
            }
        });

        return self::SUCCESS;
    }

    private function renderError(string $error, string $message, ?string $type = null): int
    {
        $payload = ['ok' => false, 'error' => $error, 'message' => $message];
        if ($type !== null) {
            $payload['type'] = $type;
        }
        $this->line($this->encode($payload));

        return self::FAILURE;
    }

    private function invalidAction(string $action): int
    {
        return $this->renderError('invalid_arguments', "invalid action [{$action}] for atlas:ai:programming-adapter");
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function json(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ($this->json()) {
            $this->line($this->encode($payload));

            return;
        }
        $human();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
