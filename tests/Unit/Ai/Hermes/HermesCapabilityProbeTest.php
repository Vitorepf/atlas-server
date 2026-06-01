<?php

namespace Tests\Unit\Ai\Hermes;

use App\Services\Ai\Hermes\HermesCapabilityProbe;
use Tests\TestCase;

class HermesCapabilityProbeTest extends TestCase
{
    private string $workDir;

    private string $binary;

    private string $argsLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/atlas-hermes-probe-'.bin2hex(random_bytes(4));
        mkdir($this->workDir, 0775, true);

        $this->binary = $this->workDir.'/hermes';
        $this->argsLog = $this->workDir.'/args.log';

        $this->writeStub($this->binary, $this->argsLog);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->workDir);

        parent::tearDown();
    }

    public function test_it_produces_the_pinned_capability_manifest_contract(): void
    {
        $manifest = (new HermesCapabilityProbe)->probe(['binary' => $this->binary, 'config_path' => '/nonexistent/config.yaml']);

        $this->assertSame('atlas.hermes.capability_manifest.v1', $manifest['schema_version']);
        $this->assertSame(0, $manifest['manifest_version']);
        $this->assertTrue($manifest['binary_present']);
        $this->assertArrayHasKey('probed_at', $manifest);
        $this->assertArrayHasKey('section_status', $manifest);
        $this->assertArrayHasKey('entries', $manifest);
        $this->assertArrayHasKey('manifest_hash', $manifest);

        // Every required section_status key is present.
        foreach (['chat', 'subcommands', 'toolsets', 'mcp', 'skills', 'bundles', 'hooks', 'delegation', 'providers', 'config_yaml'] as $section) {
            $this->assertArrayHasKey($section, $manifest['section_status']);
        }
    }

    public function test_it_parses_chat_flags_into_flag_entries(): void
    {
        $manifest = (new HermesCapabilityProbe)->probe(['binary' => $this->binary]);

        $flagTokens = $this->tokensForClass($manifest, 'flag');

        $this->assertContains('--toolsets', $flagTokens);
        $this->assertContains('--skills', $flagTokens);
        $this->assertContains('--checkpoints', $flagTokens);
        $this->assertContains('--max-turns', $flagTokens);

        // Flag entries carry the bare key + the emittable hermes_token.
        $toolsetFlag = $this->entryById($manifest, 'flag:toolsets');
        $this->assertNotNull($toolsetFlag);
        $this->assertSame('--toolsets', $toolsetFlag['hermes_token']);
        $this->assertTrue($toolsetFlag['supported']);

        // --ignore-rules is surfaced but marked unsupported (Atlas must not pass it).
        $ignoreRules = $this->entryById($manifest, 'flag:ignore-rules');
        $this->assertNotNull($ignoreRules);
        $this->assertFalse($ignoreRules['supported']);
    }

    public function test_it_parses_subcommands_from_the_choice_token(): void
    {
        $manifest = (new HermesCapabilityProbe)->probe(['binary' => $this->binary]);

        $subKeys = array_map(
            static fn (array $entry): string => (string) $entry['capability_key'],
            $this->entriesForClass($manifest, 'subcommand'),
        );

        foreach (['mcp', 'skills', 'hooks', 'chat', 'bundles'] as $expected) {
            $this->assertContains($expected, $subKeys, "subcommand '{$expected}' should be parsed from the choices token");
        }

        // Subcommands are not directly emittable.
        $mcpSub = $this->entryById($manifest, 'subcommand:mcp');
        $this->assertNotNull($mcpSub);
        $this->assertNull($mcpSub['hermes_token']);
    }

    public function test_it_captures_the_hermes_version_redacted(): void
    {
        $manifest = (new HermesCapabilityProbe)->probe(['binary' => $this->binary]);

        $this->assertIsString($manifest['hermes_version']);
        $this->assertStringContainsString('Hermes Agent', $manifest['hermes_version']);
    }

    public function test_it_parses_toolsets_skills_mcp_servers_and_hooks(): void
    {
        $manifest = (new HermesCapabilityProbe)->probe(['binary' => $this->binary]);

        $toolsetTokens = $this->tokensForClass($manifest, 'toolset');
        $this->assertContains('browser', $toolsetTokens);

        // Toolset tokens must be bare CLI names: no newlines, no flag fragments, no prose
        // leaking in from the surrounding `chat --help` line.
        foreach ($toolsetTokens as $token) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_-]*$/', (string) $token, "toolset token '{$token}' must be a bare name");
        }

        $skillKeys = array_map(
            static fn (array $entry): string => (string) $entry['capability_key'],
            $this->entriesForClass($manifest, 'skill'),
        );
        $this->assertContains('pdf', $skillKeys);

        $mcpKeys = array_map(
            static fn (array $entry): string => (string) $entry['capability_key'],
            $this->entriesForClass($manifest, 'mcp_server'),
        );
        $this->assertContains('github', $mcpKeys);

        $hookKeys = array_map(
            static fn (array $entry): string => (string) $entry['capability_key'],
            $this->entriesForClass($manifest, 'hook'),
        );
        $this->assertContains('pre_tool_call', $hookKeys);

        $this->assertSame('ok', $manifest['section_status']['skills']);
        $this->assertSame('ok', $manifest['section_status']['mcp']);
    }

    public function test_it_returns_binary_offline_manifest_without_throwing(): void
    {
        $manifest = (new HermesCapabilityProbe)->probe(['binary' => $this->workDir.'/does-not-exist-hermes']);

        $this->assertSame('binary_offline', $manifest['probe_status']);
        $this->assertFalse($manifest['binary_present']);
        $this->assertNull($manifest['hermes_version']);
        $this->assertSame([], $manifest['entries']);

        foreach ($manifest['section_status'] as $status) {
            $this->assertSame('absent', $status);
        }

        // Offline manifest is still sealed.
        $this->assertArrayHasKey('manifest_hash', $manifest);
    }

    public function test_it_marks_a_failing_subcommand_section_as_help_failed(): void
    {
        // The stub exits nonzero for `bundles`.
        $manifest = (new HermesCapabilityProbe)->probe(['binary' => $this->binary]);

        $this->assertSame('help_failed', $manifest['section_status']['bundles']);
        $this->assertSame('degraded', $manifest['probe_status']);

        // No bundle entries were produced for the failed section.
        $this->assertSame([], $this->entriesForClass($manifest, 'bundle'));
    }

    public function test_manifest_hash_is_deterministic(): void
    {
        $first = (new HermesCapabilityProbe)->probe(['binary' => $this->binary, 'config_path' => '/nonexistent/config.yaml']);

        // The seal is sha256 over the manifest minus the hash key itself.
        $full = $first;
        unset($full['manifest_hash']);
        $recomputed = hash('sha256', json_encode($full, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->assertSame($first['manifest_hash'], $recomputed, 'manifest_hash must equal sha256 over the manifest minus the hash key');
    }

    public function test_config_yaml_records_presence_booleans_only_never_secret_values(): void
    {
        $configPath = $this->workDir.'/config.yaml';
        file_put_contents($configPath, <<<'YAML'
default_model: hermes_cli_default
mcp_servers:
  github:
    command: github-mcp
    env:
      GITHUB_TOKEN: ghp_supersecrettokenvalue1234567890
    oauth:
      client_secret: oauth-super-secret-value
    headers:
      Authorization: Bearer abcdefghijklmnopqrstuvwxyz
skills:
  external_dirs:
    - /tmp/skills
providers:
  openrouter:
    api_key: sk-proj-shouldneverappearinmanifest
YAML);

        $manifest = (new HermesCapabilityProbe)->probe(['binary' => $this->binary, 'config_path' => $configPath]);

        $this->assertSame('ok', $manifest['section_status']['config_yaml']);

        $configEntry = $this->entryById($manifest, 'feature:config_yaml');
        $this->assertNotNull($configEntry);

        $detail = $configEntry['detail'];
        $this->assertTrue($detail['present']);
        $this->assertContains('mcp_servers', $detail['keys_present']);
        $this->assertContains('skills', $detail['keys_present']);
        $this->assertContains('providers', $detail['keys_present']);
        $this->assertTrue($detail['has_mcp_servers']);
        $this->assertTrue($detail['has_env']);
        $this->assertTrue($detail['has_oauth']);
        $this->assertTrue($detail['has_headers']);

        // The secret VALUES must never appear anywhere in the manifest.
        $encoded = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('ghp_supersecrettokenvalue1234567890', $encoded);
        $this->assertStringNotContainsString('oauth-super-secret-value', $encoded);
        $this->assertStringNotContainsString('sk-proj-shouldneverappearinmanifest', $encoded);
        $this->assertStringNotContainsString('github-mcp', $encoded);
    }

    public function test_probe_never_invokes_a_model_call(): void
    {
        (new HermesCapabilityProbe)->probe(['binary' => $this->binary]);

        $this->assertFileExists($this->argsLog);
        $log = (string) file_get_contents($this->argsLog);

        // The probe must never call `hermes chat --query ...` or `hermes send ...`.
        foreach (preg_split('/\R/', trim($log)) ?: [] as $line) {
            $args = explode(' ', trim($line));
            $sub = $args[0] ?? '';

            $this->assertNotSame('send', $sub, 'probe must never run `hermes send`');

            if ($sub === 'chat') {
                $this->assertNotContains('--query', $args, 'probe must never run `hermes chat --query` (a model call)');
                $this->assertNotContains('-q', $args, 'probe must never run `hermes chat -q` (a model call)');
            }
        }

        // Sanity: it DID run read-only introspection.
        $this->assertStringContainsString('--version', $log);
        $this->assertStringContainsString('chat --help', $log);
        $this->assertStringContainsString('mcp list', $log);
    }

    private function writeStub(string $path, string $argsLog): void
    {
        $logLiteral = var_export($argsLog, true);

        $script = <<<SH
#!/bin/sh
# Record the full arg vector so the test can prove no model call happened.
printf '%s\\n' "\$*" >> {$logLiteral}

case "\$1" in
  --version)
    echo "Hermes Agent v0.15.1 (2026.5.29)"
    exit 0
    ;;
  --help)
    echo "usage: hermes [-h] {chat,model,fallback,secrets,mcp,skills,hooks,bundles,tools,sessions,config,cron,webhook} ..."
    echo ""
    echo "Hermes Agent CLI"
    exit 0
    ;;
  chat)
    if [ "\$2" = "--help" ]; then
      echo "usage: hermes chat [-h] [-q QUERY] [-m MODEL] [-t TOOLSETS] [-s SKILLS]"
      echo ""
      echo "  -q, --query QUERY       the prompt"
      echo "  -m, --model MODEL       model id"
      echo "  -t, --toolsets TOOLSETS comma list e.g. browser, files, shell, delegation, mcp-github"
      echo "  -s, --skills SKILLS     repeat or comma"
      echo "  --provider {openrouter,anthropic,openai,minimax}"
      echo "  --checkpoints           enable checkpoints"
      echo "  --max-turns MAX_TURNS   default 90"
      echo "  --resume, -r            resume a session"
      echo "  --continue, -c          continue last session"
      echo "  --pass-session-id       reuse session id"
      echo "  --ignore-rules          disables AGENTS.md/SOUL.md/.cursorrules/memory/preloaded-skills injection"
      echo "  --ignore-user-config    ignore user config"
      echo "  --yolo                  auto-approve"
      exit 0
    fi
    # Any other chat invocation (e.g. --query) would be a model call: refuse loudly.
    echo "MODEL CALL ATTEMPTED" >&2
    exit 99
    ;;
  send)
    echo "MODEL CALL ATTEMPTED" >&2
    exit 99
    ;;
  tools)
    echo "browser"
    echo "files"
    echo "shell"
    echo "delegation"
    echo "mcp-github"
    exit 0
    ;;
  mcp)
    if [ "\$2" = "list" ]; then
      echo "github"
      echo "filesystem"
      exit 0
    fi
    if [ "\$2" = "catalog" ]; then
      echo "github"
      echo "linear"
      echo "notion"
      exit 0
    fi
    exit 0
    ;;
  skills)
    if [ "\$2" = "list" ]; then
      echo "pdf"
      echo "docx"
      echo "xlsx"
      exit 0
    fi
    exit 0
    ;;
  hooks)
    if [ "\$2" = "list" ]; then
      echo "pre_tool_call"
      echo "post_tool_call"
      exit 0
    fi
    exit 0
    ;;
  bundles)
    # Intentionally fail to exercise the degraded/help_failed section path.
    echo "bundles command failed" >&2
    exit 2
    ;;
  *)
    echo "unknown subcommand: \$1" >&2
    exit 1
    ;;
esac
SH;

        file_put_contents($path, $script);
        chmod($path, 0755);
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,array<string,mixed>>
     */
    private function entriesForClass(array $manifest, string $class): array
    {
        return array_values(array_filter(
            $manifest['entries'],
            static fn (array $entry): bool => ($entry['capability_class'] ?? null) === $class,
        ));
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<int,string>
     */
    private function tokensForClass(array $manifest, string $class): array
    {
        return array_values(array_filter(array_map(
            static fn (array $entry): ?string => $entry['hermes_token'] ?? null,
            $this->entriesForClass($manifest, $class),
        )));
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>|null
     */
    private function entryById(array $manifest, string $id): ?array
    {
        foreach ($manifest['entries'] as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        return null;
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            is_dir($path) ? $this->deleteDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
