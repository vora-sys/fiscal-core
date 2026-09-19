<?php

namespace sabbajohn\FiscalCore\Services\NFSe;

use sabbajohn\FiscalCore\DTO\NFSe\Nacional\NacionalApiResult;

final class NacionalPreflightRequest
{
    private readonly \Closure $isFreshCacheEntry;

    private readonly \Closure $fromCache;

    private readonly \Closure $fromRemote;

    private readonly \Closure $fallback;

    /**
     * @param  array<string,mixed>  $query
     * @param  callable(array<string,mixed>|null):bool  $isFreshCacheEntry
     * @param  callable(array<string,mixed>,bool,?string):NacionalApiResult  $fromCache
     * @param  callable(array<string,mixed>|\Throwable,array<string,mixed>|null):NacionalApiResult  $fromRemote
     * @param  callable(array<string,mixed>|null,\Throwable,string):NacionalApiResult  $fallback
     */
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly array $query,
        public readonly string $cacheKey,
        public readonly int $cacheReadTtl,
        callable $isFreshCacheEntry,
        callable $fromCache,
        callable $fromRemote,
        callable $fallback,
    ) {
        $this->isFreshCacheEntry = \Closure::fromCallable($isFreshCacheEntry);
        $this->fromCache = \Closure::fromCallable($fromCache);
        $this->fromRemote = \Closure::fromCallable($fromRemote);
        $this->fallback = \Closure::fromCallable($fallback);
    }

    /** @param array<string,mixed>|null $cached */
    public function isFresh(?array $cached): bool
    {
        return ($this->isFreshCacheEntry)($cached);
    }

    /** @param array<string,mixed> $cached */
    public function cached(array $cached, bool $stale = false, ?string $error = null): NacionalApiResult
    {
        return ($this->fromCache)($cached, $stale, $error);
    }

    /**
     * @param  array<string,mixed>|\Throwable  $response
     * @param  array<string,mixed>|null  $cached
     */
    public function remote(array|\Throwable $response, ?array $cached): NacionalApiResult
    {
        return ($this->fromRemote)($response, $cached);
    }

    /** @param array<string,mixed>|null $cached */
    public function fallback(?array $cached, \Throwable $exception, string $source): NacionalApiResult
    {
        return ($this->fallback)($cached, $exception, $source);
    }
}
