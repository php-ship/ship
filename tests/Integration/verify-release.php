<?php

declare(strict_types=1);

/**
 * Checks a real release folder against the ship-release contract (ship-ecosystem/spec):
 *
 *   php tests/Integration/verify-release.php <release-folder> [--load]
 *
 * Without --load, only the files are checked. With --load, every project-owned image is removed
 * from Docker, loaded back from its archive, and its ID compared with release.json. Used by CI's
 * docker-build job; exits non-zero on the first failed check.
 */

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Ship\Docker\EnvFile;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$folder = rtrim($argv[1] ?? '', '/');
$load = in_array('--load', $argv, true);

function check(bool $condition, string $what): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }

    echo "ok: {$what}\n";
}

function docker(string ...$arguments): string
{
    $process = new Process(['docker', ...$arguments]);
    $process->mustRun();

    return trim($process->getOutput());
}

check(is_dir($folder), "{$folder} is a folder");
check(is_file("{$folder}/release.json"), 'release.json exists (the release is complete)');

$json = (string) file_get_contents("{$folder}/release.json");
$manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

$result = (new Validator())->validate(json_decode($json), (string) file_get_contents(dirname(__DIR__, 2) . '/schema/ship-release.schema.json'));
$error = $result->error();
check($error === null, 'release.json validates against schema/ship-release.schema.json'
    . ($error === null ? '' : ': ' . json_encode((new ErrorFormatter())->format($error))));

$compose = Yaml::parseFile("{$folder}/{$manifest['compose']}");
check(($compose['name'] ?? null) === $manifest['app'], "app ({$manifest['app']}) equals the Compose name");
check(!str_contains((string) file_get_contents("{$folder}/{$manifest['compose']}"), 'build:'), 'the Compose file has no build:');

foreach ($manifest['images'] as $image) {
    $path = "{$folder}/{$image['file']}";
    check(is_file($path), "{$image['file']} exists");
    check(hash_file('sha256', $path) === $image['sha256'], "{$image['file']} matches images[].sha256");
}

foreach ($manifest['pulledImages'] as $image) {
    check(str_contains($image['ref'], '@sha256:'), "{$image['ref']} is pinned by digest");

    foreach ($image['services'] as $service) {
        check(($compose['services'][$service]['image'] ?? null) === $image['ref'], "{$service} uses the pinned {$image['ref']}");
    }
}

foreach ($manifest['services'] as $name => $service) {
    check(isset($compose['services'][$name]), "service {$name} is in the Compose file");
}

check(($manifest['deploy'] === null) === !is_file("{$folder}/deploy-commands.sh"), 'deploy-commands.sh exists exactly when deploy is set');
check(($manifest['env']['file'] === null) === !is_file("{$folder}/.env"), '.env exists exactly when env.file is set');

if ($manifest['env']['file'] !== null) {
    $settings = EnvFile::parse("{$folder}/{$manifest['env']['file']}");

    // Short values (`true`, `local`, port numbers) could appear in release.json by coincidence.
    foreach (array_filter($settings, static fn (string $value): bool => strlen($value) >= 6) as $key => $value) {
        check(!str_contains($json, $value), "the value of {$key} is not in release.json");
    }

    $keys = array_keys($settings);
    sort($keys, SORT_STRING);
    check($keys === $manifest['env']['keys'], 'env.keys are exactly the .env keys');
}

check(array_diff($manifest['env']['required'], $manifest['env']['keys']) === [], 'env.required is a subset of env.keys');

if ($load) {
    foreach ($manifest['images'] as $image) {
        docker('image', 'rm', '-f', $image['ref']);
        docker('load', '-i', "{$folder}/{$image['file']}");
        check(docker('image', 'inspect', '--format', '{{.Id}}', $image['ref']) === $image['id'], "the loaded {$image['ref']} has images[].id");
    }
}

echo "release {$manifest['app']} {$manifest['tag']} verified\n";
