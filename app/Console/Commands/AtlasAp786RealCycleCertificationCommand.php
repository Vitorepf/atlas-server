<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RealCycleCertificationService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AP-786 real cycle certification + replay CLI.
 *
 * `certify` proves whether an AP-786 session (inline file or recorded) ran real
 * full-owner-flow cycles. `replay` re-certifies a recorded session by id from the
 * append-only JSONL. Read-only: it never runs providers, git, merge or scheduler.
 */
final class AtlasAp786RealCycleCertificationCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:software-company-stewardship:ap786-cycle
        {action=certify : certify|replay}
        {--area=agentic_engineering_os : Canonical area_id}
        {--session-id= : AP-786 session id (required for replay; selects from JSONL for certify)}
        {--session-file= : JSON file containing an AP-786 session report to certify inline}
        {--min-real-cycles=3 : Minimum genuinely-real cycles required to certify the session}
        {--json : Emit JSON}';

    protected $description = 'AP-786 · certify/replay whether an autonomous evolution session ran real full-owner-flow cycles, rejecting fake direct-provider or incomplete cycles.';

    public function handle(Ap786RealCycleCertificationService $service): int
    {
        $action = (string) $this->argument('action');
        $area = (string) $this->option('area');
        $sessionId = trim((string) ($this->option('session-id') ?? ''));
        $minReal = (int) $this->option('min-real-cycles');

        if ($action === 'replay') {
            if ($sessionId === '') {
                return $this->blockedExit('session_id_required', '--session-id is required for ap786-cycle replay');
            }
            $payload = $service->certify([
                'session_id' => $sessionId,
                'area_id' => $area,
                'min_real_cycles' => $minReal,
            ]);

            return $this->emit($payload);
        }

        if ($action !== 'certify') {
            return $this->blockedExit('unknown_action', "Unknown action '{$action}'. Use certify or replay.");
        }

        $input = ['area_id' => $area, 'min_real_cycles' => $minReal];

        $sessionFile = trim((string) ($this->option('session-file') ?? ''));
        if ($sessionFile !== '') {
            if (! is_file($sessionFile)) {
                return $this->blockedExit('session_file_not_found', $sessionFile);
            }
            $decoded = json_decode((string) file_get_contents($sessionFile), true);
            if (! is_array($decoded)) {
                return $this->blockedExit('session_file_invalid_json', $sessionFile);
            }
            $input['session_report'] = $decoded;
        } elseif ($sessionId !== '') {
            $input['session_id'] = $sessionId;
        } else {
            return $this->blockedExit('session_source_required', 'Pass --session-file=<json> or --session-id=<id> for ap786-cycle certify');
        }

        return $this->emit($service->certify($input));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $this->exitCode($payload);
        }

        $this->components->twoColumnDetail('AP-786 cycle certification', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Session', (string) ($payload['session_id'] ?? ''));
        $this->components->twoColumnDetail('Replayable', YesNo::format((bool) ($payload['session_replayable_from_jsonl'] ?? false)));
        $audit = (array) ($payload['three_cycle_audit'] ?? []);
        $this->components->twoColumnDetail(
            'Real cycles',
            (string) ($audit['certified_real_cycles'] ?? 0).' / '.(string) ($audit['min_real_cycles_required'] ?? 3).' required'
        );

        foreach ((array) ($payload['cycles'] ?? []) as $cycle) {
            $this->line(sprintf(
                '  #%d %s · finding=%s',
                (int) ($cycle['cycle_index'] ?? 0),
                (string) ($cycle['status'] ?? ''),
                (string) data_get($cycle, 'selected_finding.title', data_get($cycle, 'selected_finding.finding_id', '')),
            ));
            foreach ((array) ($cycle['fake_signals'] ?? []) as $signal) {
                $this->warn('     fake: '.(string) $signal);
            }
            foreach ((array) ($cycle['missing_stages'] ?? []) as $stage) {
                $this->warn('     missing: '.(string) $stage);
            }
        }

        $this->line('');
        $this->line('  truth: '.(string) ($payload['operator_truth'] ?? ''));
        $next = $payload['next_safe_command'] ?? null;
        $this->line('  next_safe_command: '.($next === null ? '(none — not safe to proceed)' : (string) $next));

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return ($payload['status'] ?? '') === Ap786RealCycleCertificationService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function blockedExit(string $reason, string $detail): int
    {
        $this->line(json_encode([
            'schema_version' => 'atlas.software_company_stewardship.command_error.v1',
            'status' => 'blocked',
            'reason' => $reason,
            'detail' => $detail,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
