<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseIntelligenceService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiProviderReleaseReviewCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:provider-release-review
        {--provider= : Provider or lab name}
        {--title= : Release title}
        {--url= : Source URL}
        {--published-at= : Source publication timestamp}
        {--content-hash= : Optional precomputed content/body hash}
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
            'published_at' => $this->option('published-at'),
            'content_hash' => $this->option('content-hash'),
            'type' => $this->option('type'),
            'domains' => (array) $this->option('domain'),
            'capabilities' => (array) $this->option('capability'),
            'connectors' => (array) $this->option('connector'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Provider Release Review</>', $payload['status']);
        $this->components->twoColumnDetail('Provider', data_get($payload, 'release_envelope.provider'));
        $this->components->twoColumnDetail('Release type', data_get($payload, 'release_envelope.release_type'));
        $this->components->twoColumnDetail('Action', $payload['recommended_action']);
        $this->components->twoColumnDetail('Rivals required', YesNo::format($payload['rivals_required']));
        $this->components->twoColumnDetail('Decide routing change', YesNo::format(data_get($payload, 'decide_signal.changes_routing')));

        $this->newLine();
        $this->table(
            ['owner doc', 'exists', 'reason'],
            collect($payload['owner_docs'])->map(fn (array $doc): array => [
                $doc['path'],
                YesNo::format($doc['exists']),
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
