<?php

declare(strict_types=1);

namespace sabbajohn\FiscalCore\Support;

/**
 * Optional bridge used by host applications to provide live NFSe catalogs.
 *
 * Fiscal Core keeps the JSON files as its standalone defaults. An embedding
 * application may register a resolver that returns a database-backed version
 * without coupling this package to a framework or persistence layer.
 */
final class NFSeCatalogRuntime
{
    /** @var null|callable(string, array): array */
    private static $resolver = null;

    /** @var null|callable(): string */
    private static $revisionResolver = null;

    private static bool $resolving = false;

    public static function configure(?callable $resolver, ?callable $revisionResolver = null): void
    {
        self::$resolver = $resolver;
        self::$revisionResolver = $revisionResolver;
    }

    public static function resolve(string $catalog, array $fallback): array
    {
        if (! is_callable(self::$resolver) || self::$resolving) {
            return $fallback;
        }

        try {
            self::$resolving = true;
            $resolved = (self::$resolver)($catalog, $fallback);

            return is_array($resolved) ? $resolved : $fallback;
        } catch (\Throwable) {
            return $fallback;
        } finally {
            self::$resolving = false;
        }
    }

    public static function revision(): string
    {
        if (! is_callable(self::$revisionResolver)) {
            return 'filesystem';
        }

        try {
            return (string) (self::$revisionResolver)();
        } catch (\Throwable) {
            return 'filesystem';
        }
    }
}
