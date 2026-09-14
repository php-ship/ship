<?php

declare(strict_types=1);

namespace Ship\Config;

use RuntimeException;

/**
 * Value object for ship.json. Kept dumb -- ServiceRegistry validates keys, not this class.
 */
final class ShipConfig
{
    /**
     * @param array<string, string> $services   group => selected service key, e.g. ['database' => 'pgsql']
     * @param list<string>          $extensions composer package names providing extra ServiceDefinitions
     */
    public function __construct(
        public readonly string $phpVersion,
        public readonly array $services,
        public readonly array $extensions = [],
        public readonly string $nodeVersion = '24',
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(
                "No ship.json found at {$path}. Run `ship init` first."
            );
        }

        /** @var array{php?: string, node?: string, services?: array<string,string>, extensions?: list<string>} $data */
        $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        return new self(
            phpVersion: $data['php'] ?? '8.4',
            services: $data['services'] ?? [],
            extensions: $data['extensions'] ?? [],
            nodeVersion: $data['node'] ?? '24',
        );
    }

    public function toFile(string $path): void
    {
        $payload = [
            'php' => $this->phpVersion,
            'node' => $this->nodeVersion,
            'services' => $this->services,
            'extensions' => $this->extensions,
        ];

        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}
