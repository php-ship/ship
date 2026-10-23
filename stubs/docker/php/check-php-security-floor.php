<?php

// Run by the Dockerfiles' base stage: fails the image build when the image's PHP is a patch release
// with known security vulnerabilities, or a branch past end of life.
//
// ship.json's "php" is a branch ("8.4"), and Docker keeps building on whatever php:8.4 image is
// cached locally, so this checks the PHP actually in the image rather than the configured version.
//
// PHP_SECURITY_FLOOR is the first patched release per branch, oldest branch first ("8.2.35 8.3.36
// ..."), from Ship\Support\PhpSecurityFloor via the generated compose file. Empty skips the check.
// The optional argument stands in for PHP_VERSION, for tests.

$version = $argv[1] ?? PHP_VERSION;
$baseImage = getenv('SHIP_BASE_IMAGE') ?: 'the PHP base image';
$floor = array_values(array_filter(explode(' ', (string) getenv('PHP_SECURITY_FLOOR'))));

if ($floor === []) {
    exit(0);
}

$branchOf = static fn (string $release): string => implode('.', array_slice(explode('.', $release), 0, 2));
$branch = $branchOf($version);
$oldestBranch = $branchOf($floor[0]);

if (version_compare($branch, $oldestBranch, '<')) {
    fwrite(STDERR, "ship: PHP {$version} is past end of life and has unfixed security vulnerabilities. "
        . "Set \"php\" in ship.json to {$oldestBranch} or newer, then run `ship init` again.\n");
    exit(1);
}

foreach ($floor as $minimum) {
    if ($branchOf($minimum) === $branch && version_compare($version, $minimum, '<')) {
        fwrite(STDERR, "ship: PHP {$version} has known security vulnerabilities, fixed in {$minimum}. "
            . "The locally cached base image is stale: run `docker pull {$baseImage}` and build again.\n");
        exit(1);
    }
}
