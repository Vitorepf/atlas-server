<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Foundry\Frontier\Promotion\FoundryOperatorPromotionBacklogCompilerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusInboxService;
use Illuminate\Console\Command;

/**
 * Foundry AP-D · operator-receipt promotion (I4 No Self-Canonization).
 *
 * Thin entrypoint over FoundryOperatorPromotionBacklogCompilerService::compile().
 * AP-D INVIOLABLE RULE: a proposal becomes a canonical finding ONLY with an
 * EXPLICIT operator ACCEPT receipt (AreaFocusOperatorDecisionService). No
 * receipt => refused, ZERO write. ACCEPT does NOT execute; it admits the
 * finding through the SOLE admission bridge so it inherits the SAME #2 gates
 * (no AFEF exemption). The receipt is the sole credential; the inbox is the
 * sole trusted body source. This CLI never decides, never generates a finding.
 */
class AtlasFoundryPromoteCommand extends Command
{
    protected $signature = 'atlas:foundry:promote
        {--receipt= : Path to the operator decision receipt JSON (the SOLE I4 credential)}
        {--inbox= : Path to the Area Focus findings JSON projected into the operator inbox (trusted body source)}
        {--area=agentic_engineering_os : Area id}
        {--focus= : Focus label for placement}
        {--json : Emit JSON}';

    protected $description = 'AP-D: promote a proposal to a canonical finding ONLY with an operator ACCEPT receipt (I4); refuses without one.';

    public function handle(FoundryOperatorPromotionBacklogCompilerService $compiler): int
    {
        $receipt = $this->loadJsonFile((string) $this->option('receipt'), 'receipt');
        if ($receipt === null) {
            // No receipt provided => I4 hard block, never a finding.
            $result = $compiler->compile([], $this->buildInbox([]), (string) $this->option('area'), (string) $this->option('focus'));

            return $this->render($result);
        }

        $findings = [];
        $inboxPath = (string) $this->option('inbox');
        if ($inboxPath !== '') {
            $loaded = $this->loadJsonFile($inboxPath, 'inbox');
            if ($loaded === null) {
                $this->error('Could not read --inbox JSON.');

                return self::FAILURE;
            }
            // Accept either a bare findings list or {findings:[...]} envelope.
            $findings = array_is_list($loaded) ? $loaded : (array) ($loaded['findings'] ?? []);
        }

        $result = $compiler->compile(
            $receipt,
            $this->buildInbox($findings),
            (string) $this->option('area'),
            (string) $this->option('focus'),
        );

        return $this->render($result);
    }

    /**
     * Build the real operator inbox. Its dependencies are concrete services
     * (autowirable); only the materializer ports are unbound, so this resolve
     * cannot trigger an unbound-interface autowire crash (AP-C lesson).
     *
     * @param  list<array<string,mixed>>  $findings
     */
    private function buildInbox(array $findings): AreaFocusInboxService
    {
        $inbox = app(AreaFocusInboxService::class);
        if (! ($inbox instanceof AreaFocusInboxService)) {
            $inbox = app()->make(AreaFocusInboxService::class);
        }
        // Project the operator inbox from the supplied findings so the compiler's
        // content-bound trusted-source lookup can locate the decided item.
        $inbox->project(['findings' => $findings, 'area_id' => (string) $this->option('area')]);

        return $inbox;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadJsonFile(string $path, string $label): ?array
    {
        if ($path === '') {
            return null;
        }
        if (! is_file($path)) {
            $this->error(sprintf('--%s file not found: %s', $label, $path));

            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function render(array $result): int
    {
        $status = (string) ($result['status'] ?? 'unknown');

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Promotion status', $status);
            $this->components->twoColumnDetail('Blocker', (string) ($result['blocker_reason'] ?? ''));
            $this->components->twoColumnDetail('Promoted finding hash', (string) ($result['promoted_finding_hash'] ?? ''));
            $this->components->twoColumnDetail('Backlog written', ($result['backlog_written'] ?? false) ? 'yes' : 'no');

            $candidate = (array) ($result['backlog_candidate'] ?? []);
            $gates = (array) ($candidate['required_gates'] ?? ($result['admission']['required_gates'] ?? []));
            if ($gates !== []) {
                $this->components->twoColumnDetail('Required gates (same as any finding)', implode(', ', array_map('strval', $gates)));
            }
        }

        return match ($status) {
            FoundryOperatorPromotionBacklogCompilerService::STATUS_PROMOTED,
            FoundryOperatorPromotionBacklogCompilerService::STATUS_ALREADY_PROMOTED => self::SUCCESS,
            default => self::FAILURE,
        };
    }
}
