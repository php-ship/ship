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
     * The declared extensions the images lack, sorted, as install-php-extensions names them.
     * Development-only packages count only for the development image ($withDev): production's
     * `composer install --no-dev` doesn't install them.
     *
     * @return list<string>
     */
    public static function missingFromComposer(string $projectRoot, bool $withDev = true): array
    {
        $required = [];

        $lock = json_decode((string) @file_get_contents($projectRoot . '/composer.lock'), associative: true);
        foreach ($withDev ? ['packages', 'packages-dev'] : ['packages'] as $key) {
            foreach (is_array($lock) && is_array($lock[$key] ?? null) ? $lock[$key] : [] as $package) {
                $required = [...$required, ...self::extensionsIn(is_array($package) ? ($package['require'] ?? null) : null)];
            }
        }

        $composer = json_decode((string) @file_get_contents($projectRoot . '/composer.json'), associative: true);
        foreach ($withDev ? ['require', 'require-dev'] : ['require'] as $key) {
            $required = [...$required, ...self::extensionsIn(is_array($composer) ? ($composer[$key] ?? null) : null)];
        }

        $missing = array_values(array_unique(array_diff($required, self::IN_IMAGE)));
        sort($missing);

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
