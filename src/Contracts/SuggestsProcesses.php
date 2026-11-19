<?php

declare(strict_types=1);

namespace Ship\Contracts;

/**
 * Optional add-on for a FrameworkAdapter: the long-running processes a project of this framework
 * commonly needs (queue workers, a scheduler, a WebSocket server), which `ship init` offers to add
 * to ship.json's `processes`. Read from the project, so only what it actually uses is offered
 * (Horizon only with laravel/horizon installed, Messenger only with transports configured).
 */
interface SuggestsProcesses
{
    /**
     * Process name => what it is and how it runs. `healthcheck` is a shell command that exits 0
     * while the process works; `http` makes it a release.json http[] entry (see ship.json's
     * processes).
     *
     * @param array<string, string> $services the project's services (ship.json's `services`), so
     *        a process a selected service already provides isn't offered again
     * @return array<string, array{label: string, command: string, healthcheck?: string, http?: array{port: int, role: string, health: ?string, urlSetting: ?string}}>
     */
    public function suggestedProcesses(string $projectRoot, array $services): array;
}
