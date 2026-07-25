<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\ProjectStackLearnSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ProjectStackLearnSupportTest extends TestCase
{
    #[Test]
    public function dependencies_and_stack_match(): void
    {
        $deps = ProjectStackLearnSupport::dependenciesFromManifest([
            'require' => ['laravel/framework' => '^12.0'],
            'require-dev' => ['phpunit/phpunit' => '^11'],
            'dependencies' => ['react' => '19'],
        ]);
        $this->assertSame('^12.0', $deps['laravel/framework']);
        $this->assertSame('19', $deps['react']);

        [$stack, $facts] = ProjectStackLearnSupport::matchStack(
            $deps,
            ['laravel/framework' => 'Laravel', 'react' => 'React'],
            'composer.json',
        );
        $this->assertContains('Laravel ^12.0', $stack);
        $this->assertSame('stack', $facts[0]['kind']);
    }

    #[Test]
    public function doc_excerpt_and_env_databases(): void
    {
        $text = "# Title\n\n![badge](x)\n\nThis project is a long enough description for the learner to capture a grounded purpose paragraph without paraphrasing inventively.\n";
        $excerpt = ProjectStackLearnSupport::excerptFromDocText($text, 'README.md');
        $this->assertSame('README.md', $excerpt['source']);
        $this->assertStringContainsString('This project is a long enough', $excerpt['excerpt']);

        $dbs = ProjectStackLearnSupport::databasesFromEnv("APP_ENV=local\nDB_CONNECTION=pgsql\nREDIS_HOST=127.0.0.1\n");
        $this->assertArrayHasKey('Pgsql (DB_CONNECTION)', $dbs);
        $this->assertArrayHasKey('Redis', $dbs);
    }
}
