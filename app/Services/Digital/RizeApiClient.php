<?php

namespace App\Services\Digital;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RizeApiClient
{
    public function query(string $query, array $variables = []): array
    {
        $apiKey = config('services.rize.api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException('RIZE_API_KEY is not configured.');
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.rize.http_timeout', 30))
            ->post((string) config('services.rize.graphql_endpoint'), [
                'query' => $query,
                'variables' => $variables,
            ]);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Rize API returned a non-JSON response.');
        }

        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            throw new RuntimeException('Rize GraphQL error: '.json_encode($errors, JSON_UNESCAPED_SLASHES));
        }

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    public function inspectQueryFields(): array
    {
        $data = $this->query(<<<'GRAPHQL'
            query AtlasRizeInspectQueryFields {
              __schema {
                queryType {
                  fields {
                    name
                    args {
                      name
                      type {
                        kind
                        name
                        ofType {
                          kind
                          name
                          ofType {
                            kind
                            name
                          }
                        }
                      }
                    }
                    type {
                      kind
                      name
                      ofType {
                        kind
                        name
                        ofType {
                          kind
                          name
                        }
                      }
                    }
                  }
                }
              }
            }
        GRAPHQL);

        return Arr::get($data, '__schema.queryType.fields', []);
    }

    public function fetchSessions(CarbonImmutable $from, CarbonImmutable $to, int $first = 100, ?string $after = null): RizeApiPage
    {
        $query = $this->sessionsQuery();
        $rootPath = (string) config('services.rize.sessions_root_path', 'timeEntries.nodes');
        $pageInfoPath = (string) config('services.rize.sessions_page_info_path', 'timeEntries.pageInfo');

        $data = $this->query($query, [
            'from' => $from->utc()->toIso8601String(),
            'to' => $to->utc()->toIso8601String(),
            'first' => $first,
            'after' => $after,
        ]);

        $records = Arr::get($data, $rootPath, []);
        if (! is_array($records)) {
            $records = [];
        }

        $pageInfo = Arr::get($data, $pageInfoPath, []);
        if (! is_array($pageInfo)) {
            $pageInfo = [];
        }

        return new RizeApiPage(
            records: array_values(array_filter($records, 'is_array')),
            hasNextPage: (bool) ($pageInfo['hasNextPage'] ?? false),
            endCursor: is_string($pageInfo['endCursor'] ?? null) ? $pageInfo['endCursor'] : null,
            rawData: $data,
        );
    }

    private function sessionsQuery(): string
    {
        $queryPath = config('services.rize.sessions_query_path');
        if (is_string($queryPath) && $queryPath !== '' && is_file($queryPath)) {
            return (string) file_get_contents($queryPath);
        }

        $query = config('services.rize.sessions_query');
        if (is_string($query) && trim($query) !== '') {
            return $query;
        }

        return <<<'GRAPHQL'
            query AtlasRizeSessions($from: ISO8601DateTime, $to: ISO8601DateTime, $first: Int, $after: String) {
              timeEntries(startTime: $from, endTime: $to, first: $first, after: $after) {
                nodes {
                  id
                  title
                  description
                  status
                  source
                  startTime
                  endTime
                  duration
                  aiConfidenceScore
                  reasoning
                  project
                  {
                    id
                    name
                    status
                  }
                  task
                  {
                    id
                    name
                    status
                  }
                  client
                  {
                    id
                    name
                  }
                }
                pageInfo {
                  hasNextPage
                  endCursor
                }
              }
            }
        GRAPHQL;
    }
}
