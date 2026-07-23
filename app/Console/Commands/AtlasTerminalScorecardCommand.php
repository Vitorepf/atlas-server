<?php

namespace App\Console\Commands;

use App\Services\Ai\TerminalDev\Desktop\TerminalDesktopBridgeContract;
use App\Services\Ai\TerminalDev\Hooks\TerminalHookRunner;
use App\Services\Ai\TerminalDev\Mcp\TerminalMcpClient;
use App\Services\Ai\TerminalDev\Plugins\TerminalPluginCatalog;
use App\Services\Ai\TerminalDev\Protocol\AapSchema;
use App\Services\Ai\TerminalDev\Session\AtlasTerminalSessionRuntime;
use App\Services\Ai\TerminalDev\Session\AtlasTerminalSessionStore;
use App\Services\Ai\TerminalDev\Session\TerminalSessionOps;
use App\Services\Ai\TerminalDev\Subagents\TerminalSubagentRunner;
use App\Services\Ai\TerminalDev\Superiority\TerminalSuperiorityService;
use App\Services\Ai\TerminalDev\Tools\TerminalToolHost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionMethod;

class AtlasTerminalScorecardCommand extends Command
{
    protected $signature = 'atlas:terminal:scorecard {--json : Machine JSON}';

    protected $description = 'Terminal Dev capability scorecard (Grok parity + Atlas superiority).';

    public function handle(TerminalToolHost $tools): int
    {
        $runtime = app(AtlasTerminalSessionRuntime::class);
        $ref = new ReflectionClass($runtime);
        $helpMethod = $ref->getMethod('helpText');
        $helpMethod->setAccessible(true);
        $helpText = (string) $helpMethod->invoke($runtime);

        $slashTokens = [
            'resume', 'sessions', 'fork', 'rewind', 'compact', 'context',
            'export', 'copy', 'approve', 'subagent', 'review', 'doctor',
            'forge', 'mcp', 'hooks', 'promote-forge',
        ];
        $slashOk = true;
        foreach ($slashTokens as $token) {
            if (! str_contains($helpText, $token)) {
                $slashOk = false;
                break;
            }
        }

        $checks = [
            'config_loaded' => config('atlas_terminal.schema') === 'atlas.terminal.config.v1',
            'protocol_version' => AapSchema::VERSION === 'atlas.agent.protocol.v0',
            'hermes_is_default' => in_array('hermes_cli', config('atlas_terminal.default_provider_order', []), true)
                && (bool) config('atlas_terminal.hermes_allowed', false)
                && (string) config('atlas_terminal.provider', 'hermes_cli') === 'hermes_cli',
            'hermes_bridge' => class_exists(\App\Services\Ai\TerminalDev\Providers\TerminalHermesBridge::class),
            'hermes_oneshot_default' => (bool) config('atlas_terminal.hermes_cli_oneshot', true),
            'core_tools' => count(array_intersect(TerminalToolHost::CORE_TOOLS, $tools->available())) >= 5,
            'product_doc' => File::isFile(base_path('docs/engineering-knowledge-base/atlas-terminal-dev-product.md')),
            'aap_doc' => File::isFile(base_path('docs/engineering-knowledge-base/atlas-agent-protocol-v0.md')),
            'runtime_class' => class_exists(AtlasTerminalSessionRuntime::class),
            'store_list_fork_compact' => method_exists(AtlasTerminalSessionStore::class, 'listForWorkspace')
                && method_exists(AtlasTerminalSessionStore::class, 'fork')
                && method_exists(AtlasTerminalSessionStore::class, 'compact'),
            'hooks' => class_exists(TerminalHookRunner::class),
            'mcp_client' => class_exists(TerminalMcpClient::class),
            'subagents' => class_exists(TerminalSubagentRunner::class),
            'superiority' => class_exists(TerminalSuperiorityService::class),
            'session_ops' => class_exists(TerminalSessionOps::class),
            'plugins' => class_exists(TerminalPluginCatalog::class),
            'desktop_bridge' => class_exists(TerminalDesktopBridgeContract::class),
            'slash_surface' => $slashOk,
            'rust_tui_source' => File::isFile(base_path('../atlas-terminal/src/main.rs')),
            'doctor_command' => class_exists(\App\Console\Commands\AtlasTerminalDoctorCommand::class),
            'product_doc_terminal' => \Illuminate\Support\Facades\File::isFile(base_path('docs/engineering-knowledge-base/atlas-terminal-dev-product.md')),
            'atlas_term_binary' => File::isFile(base_path('../atlas-terminal/target/release/atlas-term'))
                || File::isFile(($_SERVER['HOME'] ?? getenv('HOME') ?: '').'/.local/bin/atlas-term'),
            'command_registered' => true,
            'auto_compact_config' => config('atlas_terminal.auto_compact_turns') !== null,
        ];

        $passed = count(array_filter($checks));
        $total = count($checks);
        $payload = [
            'schema' => 'atlas.terminal.scorecard.v1',
            'ok' => $passed === $total,
            'passed' => $passed,
            'total' => $total,
            'score_10' => round(10 * $passed / max(1, $total), 1),
            'checks' => $checks,
            'protocol_version' => AapSchema::VERSION,
            'desktop_bridge' => app(TerminalDesktopBridgeContract::class)->describe(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Terminal scorecard %d/%d (%.1f/10) %s',
                $passed,
                $total,
                $payload['score_10'],
                $payload['ok'] ? 'OK' : 'GAPS'
            ));
            foreach ($checks as $k => $v) {
                $this->line(sprintf('  %s %s', $v ? '✓' : '✗', $k));
            }
        }

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
