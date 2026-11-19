<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * The PHP extensions a project's Composer packages declare (`ext-*` in composer.lock, and in
 * composer.json for the project itself) that ship's images don't already have, so the image
 * installs them without ship.json having to list them: `composer install` refuses to run without
 * them. ship.json's phpExtensions remains for an extension no package declares.
 */
final class PhpExtensions
{
    /**
     * Already in ship's images: the official PHP image's own (`php -m`), and what
     * stubs/docker/php/Dockerfile and Dockerfile.frankenphp always install. Xdebug too: the
     * development stage builds it itself, and installing it twice fails that stage.
     */
    private const IN_IMAGE = [
        'core', 'ctype', 'curl', 'date', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'json', 'lexbor',
        'libxml', 'mbstring', 'mysqlnd', 'openssl', 'pcre', 'pdo', 'pdo_sqlite', 'phar', 'posix', 'random',
        'readline', 'reflection', 'session', 'simplexml', 'sodium', 'spl', 'sqlite3', 'standard',
        'tokenizer', 'uri', 'xml', 'xmlreader', 'xmlwriter', 'zlib',
        'opcache', 'zend-opcache', 'intl', 'pcntl', 'pdo_mysql', 'pdo_pgsql', 'redis', 'sockets', 'zip',
        'xdebug',
    ];

    /**
     * Whether every ship image has $extension already (see IN_IMAGE).
     */
    public static function isInImage(string $extension): bool
    {
        return in_array(strtolower($extension), self::IN_IMAGE, true);
    }

    /**
     * The declared extensions the images lack, sorted, as install-php-extensions names them.
     * Development-only packages count only for the development image ($withDev): production's
     * `composer install --no-dev` doesn't install them.
     *
     * @return list<string>
     */
    public static function missingFromComposer(string $projectRoot, bool $withDev = true): array
    {
        return array_keys(self::declaredBy($projectRoot, $withDev));
    }

    /**
     * The declared extensions the images lack, each with the packages that declare it ("the
     * project" for its own composer.json), sorted by extension.
     *
     * @return array<string, list<string>>
     */
    public static function declaredBy(string $projectRoot, bool $withDev = true): array
    {
        $declared = [];

        $lock = json_decode((string) @file_get_contents($projectRoot . '/composer.lock'), associative: true);
        foreach ($withDev ? ['packages', 'packages-dev'] : ['packages'] as $key) {
            foreach (is_array($lock) && is_array($lock[$key] ?? null) ? $lock[$key] : [] as $package) {
                if (!is_array($package)) {
                    continue;
                }

                foreach (self::extensionsIn($package['require'] ?? null) as $extension) {
                    $declared[$extension][] = is_string($package['name'] ?? null) ? $package['name'] : 'a package';
                }
            }
        }

        $composer = json_decode((string) @file_get_contents($projectRoot . '/composer.json'), associative: true);
        foreach ($withDev ? ['require', 'require-dev'] : ['require'] as $key) {
            foreach (self::extensionsIn(is_array($composer) ? ($composer[$key] ?? null) : null) as $extension) {
                $declared[$extension][] = 'the project';
            }
        }

        $missing = [];
        foreach ($declared as $extension => $by) {
            if (!self::isInImage($extension)) {
                $missing[$extension] = array_values(array_unique($by));
            }
        }
        ksort($missing);

        return $missing;
    }

    /**
     * @return list<string>
     */
    private static function extensionsIn(mixed $requirements): array
    {
        $extensions = [];

        foreach (is_array($requirements) ? array_keys($requirements) : [] as $name) {
            if (is_string($name) && str_starts_with($name, 'ext-')) {
                $extensions[] = strtolower(substr($name, 4));
            }
        }

        return $extensions;
    }
}
