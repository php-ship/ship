<?php

declare(strict_types=1);

namespace Ship\Node;

/**
 * The current version of Yarn or pnpm, from the npm registry, for `ship init` to record in
 * package.json's packageManager without a container (`ship package-manager` asks Corepack, in the
 * container, instead).
 *
 * Yarn has two lines: Yarn 1 ("classic", the `yarn` package, with "# yarn lockfile v1" lockfiles)
 * and Yarn 2+ (the `@yarnpkg/cli-dist` package). A project with a Yarn 1 lockfile keeps Yarn 1;
 * otherwise Yarn means the current Yarn, as Corepack's `yarn@stable` does.
 */
final class PackageManagerVersions
{
    private const REGISTRY = 'https://registry.npmjs.org/';

    /**
     * @var \Closure(string): ?string fetches a URL's body, or null on failure
     */
    private readonly \Closure $fetch;

    /**
     * @param ?\Closure(string): ?string $fetch
     */
    public function __construct(?\Closure $fetch = null)
    {
        $this->fetch = $fetch ?? static function (string $url): ?string {
            $context = stream_context_create(['http' => [
                'timeout' => 10,
                'header' => "Accept: application/vnd.npm.install-v1+json\r\n",
            ]]);
            $body = @file_get_contents($url, false, $context);

            return $body === false ? null : $body;
        };
    }

    /**
     * "pnpm@12.21.0", "yarn@4.18.1" or "yarn@1.22.22", or null when the registry can't be reached.
     */
    public function latest(string $tool, string $projectRoot): ?string
    {
        $package = match ($tool) {
            'pnpm' => 'pnpm',
            'yarn' => self::usesYarnClassic($projectRoot) ? 'yarn' : '@yarnpkg/cli-dist',
            default => throw new \InvalidArgumentException("No registry version for {$tool}: npm comes with Node."),
        };

        $body = ($this->fetch)(self::REGISTRY . str_replace('/', '%2f', $package));
        /** @var mixed $data */
        $data = $body === null ? null : json_decode($body, associative: true);
        $version = is_array($data) && is_array($data['dist-tags'] ?? null) ? ($data['dist-tags']['latest'] ?? null) : null;

        return is_string($version) && preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 ? "{$tool}@{$version}" : null;
    }

    public static function usesYarnClassic(string $projectRoot): bool
    {
        $lockfile = $projectRoot . '/yarn.lock';

        return is_file($lockfile) && str_contains((string) file_get_contents($lockfile, length: 512), '# yarn lockfile v1');
    }

    /**
     * Sets package.json's packageManager, keeping the file's indentation and key order.
     */
    public static function record(string $projectRoot, string $spec): void
    {
        $path = $projectRoot . '/package.json';
        $raw = (string) file_get_contents($path);
        // Objects stay objects, so an empty "{}" isn't rewritten as "[]".
        $package = json_decode($raw, associative: false, flags: JSON_THROW_ON_ERROR);

        if (!$package instanceof \stdClass) {
            throw new \RuntimeException('package.json isn\'t a JSON object.');
        }

        $package->packageManager = $spec;

        $indent = preg_match('/^([ \t]+)"/m', $raw, $matches) === 1 ? $matches[1] : '  ';
        $json = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // json_encode indents with four spaces.
        $json = (string) preg_replace_callback(
            '/^(?: {4})+/m',
            static fn (array $m): string => str_repeat($indent, intdiv(strlen($m[0]), 4)),
            $json,
        );

        file_put_contents($path, $json . "\n");
    }
}
