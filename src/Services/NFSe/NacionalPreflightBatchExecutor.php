<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;
use sabbajohn\FiscalCore\Exceptions\NacionalApiException;
use sabbajohn\FiscalCore\Support\Cache\FileCacheStore;

final class NacionalPreflightBatchExecutor
{
    public function __construct(
        private readonly NacionalRestClient $client,
        private readonly FileCacheStore $cache,
    ) {}

    /**
     * @param  array<string,NacionalPreflightRequest>  $requests
     * @return array<string,NacionalApiResult>
     */
    public function execute(array $requests): array
    {
        $startedAt = hrtime(true);
        $resolved = [];
        $misses = [];
        $remoteBatchSize = 0;
        $remoteNames = [];
        $batchLockFailed = false;

        foreach ($requests as $name => $request) {
            $cached = $this->cache->get($request->cacheKey, $request->cacheReadTtl);
            if ($request->isFresh($cached)) {
                $resolved[$name] = $request->cached((array) $cached);

                continue;
            }
            $misses[$name] = ['request' => $request, 'cached' => $cached];
        }

        if ($misses !== []) {
            try {
                $remoteResults = $this->cache->synchronizedMany(
                    array_map(
                        static fn (array $miss): string => $miss['request']->cacheKey,
                        array_values($misses),
                    ),
                    30,
                    function () use ($misses, &$remoteBatchSize, &$remoteNames): array {
                        $inside = [];
                        $remote = [];
                        foreach ($misses as $name => $miss) {
                            /** @var NacionalPreflightRequest $request */
                            $request = $miss['request'];
                            $current = $this->cache->get($request->cacheKey, $request->cacheReadTtl);
                            if ($request->isFresh($current)) {
                                $inside[$name] = $request->cached((array) $current);

                                continue;
                            }
                            $remote[$name] = [
                                'request' => $request,
                                'cached' => $current ?? $miss['cached'],
                            ];
                        }

                        if ($remote === []) {
                            return $inside;
                        }

                        $remoteBatchSize = count($remote);
                        $remoteNames = array_keys($remote);
                        $responses = $this->client->getMany(array_map(
                            static fn (array $miss): array => [
                                'url' => $miss['request']->url,
                                'query' => $miss['request']->query,
                            ],
                            $remote,
                        ));
                        foreach ($remote as $name => $miss) {
                            /** @var NacionalPreflightRequest $request */
                            $request = $miss['request'];
                            $response = $responses[$name] ?? new NacionalApiException(
                                'Resposta ausente no preflight Nacional.',
                                retryable: true,
                            );
                            $inside[$name] = $request->remote($response, $miss['cached']);
                        }

                        return $inside;
                    },
                );
                $resolved = array_replace($resolved, $remoteResults);
            } catch (\Throwable $exception) {
                $batchLockFailed = true;
                foreach ($misses as $name => $miss) {
                    $resolved[$name] = $miss['request']->fallback($miss['cached'], $exception, 'lock');
                }
            }
        }

        foreach ($resolved as $name => $result) {
            $source = (string) ($result->metadata['source'] ?? 'remote');
            $stale = ($result->metadata['stale'] ?? false) === true;
            $resolved[$name] = new NacionalApiResult(
                $result->status,
                $result->data,
                $result->warnings,
                array_replace($result->metadata, [
                    'cache_status' => $stale ? 'stale' : ($source === 'cache' ? 'hit' : 'miss'),
                    'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                    'preflight_request_mode' => $batchLockFailed && isset($misses[$name])
                        ? 'single_flight_fallback'
                        : (in_array($name, $remoteNames, true)
                            ? ($remoteBatchSize > 1 && $this->client->supportsParallelRequests() ? 'parallel' : 'sequential')
                            : 'cache'),
                    'preflight_batch_size' => in_array($name, $remoteNames, true) ? $remoteBatchSize : 0,
                ]),
            );
        }

        return $resolved;
    }
}
