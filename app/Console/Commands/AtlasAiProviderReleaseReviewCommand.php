<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseIntelligenceService;
use Illuminate\Console\Command;

class AtlasAiProviderReleaseReviewCommand extends Command
{
    protected $signature = 'atlas:ai:provider-release-review
        {--provider= : Provider or lab name}
        {--title= : Release title}
        {--url= : Source URL}
        {--type= : Release type}
        {--domain=* : Affected Atlas domain}
        {--capability=* : Capability mentioned by the release}
        {--connector=* : Connector mentioned by the release}
        {--json : Print machine-readable JSON}';

    protected $description = 'Classify a provider release into Atlas AI envelopes, owner docs, APs, Rivals and Decide signals.';

    public function handle(AtlasProviderReleaseIntelligenceService $intelligence): int
    {
        $payload = $intelligence->review([
            'provider' => $this->option('provider'),
            'title' => $this->option('title'),
            'url' => $this->option('url'),
            'type' => $this->option('type'),
            'domains' => (array) $this->option('domain'),
            'capabilities' => (array) $this->option('capability'),
            'connectors' => (array) $this->option('connector'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Provider Release Review</>', $payload['status']);
        $this->components->twoColumnDetail('Provider', data_get($payload, 'release_envelope.provider'));
        $this->components->twoColumnDetail('Release type', data_get($payload, 'release_envelope.release_type'));
        $this->components->twoColumnDetail('Action', $payload['recommended_action']);
        $this->components->twoColumnDetail('Rivals required', $payload['rivals_required'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Decide routing change', data_get($payload, 'decide_signal.changes_routing') ? 'yes' : 'no');

        $this->newLine();
        $this->table(
            ['owner doc', 'exists', 'reason'],
            collect($payload['owner_docs'])->map(fn (array $doc): array => [
                $doc['path'],
                $doc['exists'] ? 'yes' : 'no',
                $doc['reason'],
            ])->all(),
        );

        $this->newLine();
        $this->table(
            ['suggested AP', 'kind'],
            collect($payload['suggested_aps'])->map(fn (array $ap): array => [
                $ap['id'],
                $ap['kind'],
            ])->all(),
        );

        return self::SUCCESS;
    }
}
