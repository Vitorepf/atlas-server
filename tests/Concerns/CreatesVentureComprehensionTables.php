<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesVentureComprehensionTables
{
    protected function createVentureComprehensionTables(): void
    {
        $this->dropVentureComprehensionTables();

        Schema::create('ai_venture_comprehension_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.venture.comprehension_run.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('venture_id')->index();
            $table->string('workspace_path', 1000);
            $table->json('repo_roots')->nullable();
            $table->string('status', 40)->default('running')->index();
            $table->json('capabilities_run')->nullable();
            $table->json('summary')->nullable();
            $table->unsignedInteger('files_scanned')->default(0);
            $table->unsignedInteger('findings_total')->default(0);
            $table->boolean('analyzed')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('run_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_venture_comprehension_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.venture.comprehension_finding.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('run_id')->index();
            $table->uuid('venture_id')->index();
            $table->string('capability', 40)->index();
            $table->string('kind', 60)->index();
            $table->string('category', 80)->nullable()->index();
            $table->string('title', 500);
            $table->text('detail')->nullable();
            $table->string('severity', 20)->nullable()->index();
            $table->decimal('impact_score', 5, 2)->nullable();
            $table->decimal('effort_score', 5, 2)->nullable();
            $table->decimal('leverage_score', 6, 3)->nullable()->index();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('evidence_kind', 20)->default('observed')->index();
            $table->string('evidence_path', 1000)->nullable();
            $table->unsignedInteger('evidence_line')->nullable();
            $table->text('evidence_snippet')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->text('recommendation')->nullable();
            $table->json('payload')->nullable();
            $table->string('source', 40)->default('deterministic')->index();
            $table->string('finding_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_venture_documentation_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 120)->default('atlas.ai.venture.documentation_artifact.v1');
            $table->string('uuid', 64)->unique();
            $table->uuid('run_id')->nullable()->index();
            $table->uuid('venture_id')->index();
            $table->string('doc_kind', 80)->index();
            $table->string('title', 500);
            $table->string('relative_path', 1000);
            $table->boolean('written_to_disk')->default(false);
            $table->json('sections')->nullable();
            $table->longText('content')->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->string('content_hash', 64)->index();
            $table->string('status', 40)->default('generated')->index();
            $table->timestamps();
        });
    }

    protected function dropVentureComprehensionTables(): void
    {
        Schema::dropIfExists('ai_venture_documentation_artifacts');
        Schema::dropIfExists('ai_venture_comprehension_findings');
        Schema::dropIfExists('ai_venture_comprehension_runs');
    }

    /**
     * Build a small fake polyglot repo on disk for capability tests.
     * Returns the absolute workspace path.
     */
    protected function makeFakeWorkspace(?string $base = null): string
    {
        $base ??= sys_get_temp_dir().'/venture_ws_'.bin2hex(random_bytes(6));
        @mkdir($base.'/app/Http/Controllers', 0777, true);
        @mkdir($base.'/app/Services', 0777, true);
        @mkdir($base.'/config', 0777, true);
        @mkdir($base.'/database/migrations', 0777, true);
        @mkdir($base.'/src/i18n/locales', 0777, true);
        @mkdir($base.'/vendor/should/be/skipped', 0777, true);

        file_put_contents($base.'/composer.json', json_encode([
            'name' => 'acme/fake',
            'description' => 'Fake performance app for tests',
            'require' => [
                'php' => '^8.3',
                'laravel/framework' => '^11.9',
                'laravel/cashier' => '^16.0',
                'stripe/stripe-php' => '^13.0',
            ],
        ], JSON_PRETTY_PRINT));

        file_put_contents($base.'/package.json', json_encode([
            'name' => 'fake-front',
            'dependencies' => ['react' => '^18.3.1', 'i18next' => '^23'],
        ], JSON_PRETTY_PRINT));

        file_put_contents($base.'/config/plans.php', <<<'PHP'
<?php
return [
    'raso' => ['price' => 149.90, 'currency' => 'BRL'],
    'recife' => ['price' => 249.99, 'currency' => 'BRL'],
    'abissal' => ['price' => 399.99, 'currency' => 'BRL'],
    'trial_days' => 7,
];
PHP);

        file_put_contents($base.'/app/Http/Controllers/BillingController.php', <<<'PHP'
<?php
namespace App\Http\Controllers;
class BillingController
{
    // TODO: handle failed Stripe transfer refunds
    public function subscribe($request)
    {
        $price = 149.90; // minimum plan price
        if ($request->amount < $price) {
            return response()->json(['error' => 'below_minimum'], 422);
        }
        $secret = "sk_live_HARDCODED_SHOULD_NOT_BE_HERE_1234567890abcd";
        return $secret;
    }
}
PHP);

        file_put_contents($base.'/app/Services/CommissionService.php', <<<'PHP'
<?php
namespace App\Services;
class CommissionService
{
    // FIXME: commission rate should be configurable
    const RATE = 0.30;
    public function payout($amount)
    {
        return $amount * self::RATE;
    }
}
PHP);

        file_put_contents($base.'/database/migrations/2024_01_01_create_campaigns_table.php', <<<'PHP'
<?php
// campaigns: clicks, conversions, postback tracking
return new class {
    public function up() {
        // Schema::create('campaigns', ...)
        // Schema::create('clicks', ...)
        // Schema::create('conversions', ...)
    }
};
PHP);

        file_put_contents($base.'/src/i18n/locales/pt-BR.json', json_encode(['hello' => 'olá']));
        file_put_contents($base.'/src/i18n/locales/en.json', json_encode(['hello' => 'hello']));
        file_put_contents($base.'/src/i18n/locales/es.json', json_encode(['hello' => 'hola']));
        file_put_contents($base.'/vendor/should/be/skipped/Huge.php', "<?php // should be excluded\n");

        return $base;
    }

    protected function removeFakeWorkspace(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($path);
    }
}
