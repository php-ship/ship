<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Config\ShipConfig;
use Ship\Console\Commands\InitCommand;
use Ship\Contracts\ShipEnvironment;
use Ship\Docker\ComposeFileBuilder;
use Ship\Docker\Healthchecks;
use Ship\Frameworks\LaravelAdapter;
use Ship\Frameworks\SymfonyAdapter;
use Ship\Frameworks\TempestAdapter;
use Ship\Node\PackageManagerVersions;
use Ship\Services\ServiceRegistry;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * The processes a framework suggests for ship.json, `ship init`'s process questions, and a
 * process's own health check.
 */
final class ProcessSuggestionsTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/ship-processes-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot, recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    /**
     * @param list<string> $packages
     */
    private function installed(array $packages): void
    {
        file_put_contents($this->projectRoot . '/composer.lock', json_encode([
            'packages' => array_map(static fn (string $name): array => ['name' => $name, 'version' => '1.0.0'], $packages),
        ]));
    }

    private function write(string $path, string $content): void
    {
        @mkdir(dirname($this->projectRoot . '/' . $path), recursive: true);
        file_put_contents($this->projectRoot . '/' . $path, $content);
    }

    public function test_laravel_suggests_horizon_with_its_status_check_the_scheduler_and_reverb(): void
    {
        $this->installed(['laravel/framework', 'laravel/horizon', 'laravel/reverb']);
        $this->write('vendor/laravel/reverb/src/Servers/Reverb/Factory.php', "<?php \$routes->add('health_check', Route::get('/up', x));");

        $suggested = (new LaravelAdapter())->suggestedProcesses($this->projectRoot, []);

        self::assertSame(['horizon', 'scheduler', 'reverb'], array_keys($suggested));
        self::assertSame('php artisan horizon:status', $suggested['horizon']['healthcheck'] ?? null);
        self::assertSame('php artisan schedule:work', $suggested['scheduler']['command']);
        self::assertSame(['port' => 8080, 'role' => 'websocket', 'health' => '/up', 'urlSetting' => 'VITE_REVERB_HOST'], $suggested['reverb']['http'] ?? null);
    }

    public function test_laravel_without_horizon_gets_a_queue_worker_and_no_reverb_twice(): void
    {
        $this->installed(['laravel/framework', 'laravel/reverb']);

        $suggested = (new LaravelAdapter())->suggestedProcesses($this->projectRoot, ['broadcasting' => 'reverb']);

        self::assertSame(['queue', 'scheduler'], array_keys($suggested));
        self::assertSame('php artisan queue:work', $suggested['queue']['command']);
    }

    /**
     * An older Reverb without the /up route gets no health path.
     */
    public function test_reverb_without_its_health_route_has_no_health_path(): void
    {
        $this->installed(['laravel/reverb']);

        $http = (new LaravelAdapter())->suggestedProcesses($this->projectRoot, [])['reverb']['http'] ?? [];

        self::assertArrayHasKey('health', $http);
        self::assertNull($http['health']);
    }

    public function test_symfony_consumes_the_configured_transports_and_schedules(): void
    {
        $this->installed(['symfony/messenger', 'symfony/scheduler']);
        $this->write('config/packages/messenger.yaml', <<<'YAML'
            framework:
                messenger:
                    failure_transport: failed
                    transports:
                        async:
                            dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                        emails: '%env(MESSENGER_TRANSPORT_DSN)%'
                        failed: 'doctrine://default?queue_name=failed'
                        inline: 'sync://'
            YAML);
        $this->write('src/Scheduler/Main.php', "<?php\n#[AsSchedule]\nfinal class Main {}\n");
        $this->write('src/Scheduler/Reports.php', "<?php\n#[AsSchedule('reports')]\nfinal class Reports {}\n");

        $suggested = (new SymfonyAdapter())->suggestedProcesses($this->projectRoot, []);

        self::assertSame('php bin/console messenger:consume async emails --time-limit=3600', $suggested['messenger']['command']);
        self::assertStringContainsString('messenger:consume scheduler_', $suggested['scheduler']['command']);
        self::assertStringContainsString('scheduler_default', $suggested['scheduler']['command']);
        self::assertStringContainsString('scheduler_reports', $suggested['scheduler']['command']);
    }

    public function test_symfony_without_messenger_or_schedules_suggests_nothing(): void
    {
        $this->installed(['symfony/framework-bundle']);

        self::assertSame([], (new SymfonyAdapter())->suggestedProcesses($this->projectRoot, []));
    }

    public function test_tempest_suggests_what_the_project_uses(): void
    {
        file_put_contents($this->projectRoot . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));
        $this->write('app/Tasks.php', "<?php\nfinal class Tasks { #[Schedule(Every::HOUR)] public function prune(): void {} }\n");

        $suggested = (new TempestAdapter())->suggestedProcesses($this->projectRoot, []);
        self::assertSame(['scheduler'], array_keys($suggested));
        self::assertSame('while true; do php tempest schedule:run; sleep 60; done', $suggested['scheduler']['command']);

        $this->write('app/SendMail.php', "<?php\n#[Async]\nfinal class SendMail {}\n");
        self::assertSame(['scheduler', 'async-commands'], array_keys((new TempestAdapter())->suggestedProcesses($this->projectRoot, [])));
    }

    /**
     * A process's own check replaces the default one, with its "$" escaped for Compose.
     */
    public function test_a_process_health_check_round_trips_and_reaches_compose(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', json_encode(['php' => '8.4', 'services' => [], 'processes' => [
            'horizon' => ['command' => 'php artisan horizon', 'healthcheck' => 'php artisan horizon:status'],
            'worker' => ['command' => 'php worker.php', 'healthcheck' => 'test -f /tmp/$HOSTNAME'],
        ]]));
        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');
        $config->toFile($this->projectRoot . '/ship.json');

        self::assertSame(['horizon' => 'php artisan horizon:status', 'worker' => 'test -f /tmp/$HOSTNAME'], ShipConfig::fromFile($this->projectRoot . '/ship.json')->processHealthchecks);

        $compose = Yaml::parse((new ComposeFileBuilder(new ServiceRegistry([])))->build($config, ShipEnvironment::Production));
        self::assertSame(Healthchecks::command('php artisan horizon:status'), $compose['services']['horizon']['healthcheck']);
        self::assertSame(['CMD-SHELL', 'test -f /tmp/$$HOSTNAME'], $compose['services']['worker']['healthcheck']['test']);
    }

    public function test_an_empty_health_check_is_rejected(): void
    {
        file_put_contents($this->projectRoot . '/ship.json', json_encode(['php' => '8.4', 'services' => [], 'processes' => [
            'horizon' => ['command' => 'php artisan horizon', 'healthcheck' => ''],
        ]]));

        $this->expectExceptionMessage('"healthcheck" must be a non-empty shell command');

        ShipConfig::fromFile($this->projectRoot . '/ship.json');
    }

    private function init(array $inputs): CommandTester
    {
        $tester = new CommandTester(new InitCommand($this->projectRoot, new ServiceRegistry([]), new PackageManagerVersions(static fn (string $url): ?string => null)));
        $tester->setInputs($inputs);
        $tester->execute([]);

        // Every scripted run here answers the networks question with nothing: an answer landing
        // on the wrong question shows up as a network.
        self::assertArrayNotHasKey('externalNetwork', json_decode((string) file_get_contents($this->projectRoot . '/ship.json'), true));

        return $tester;
    }

    private function laravelProject(): void
    {
        file_put_contents($this->projectRoot . '/artisan', "<?php\n");
        file_put_contents($this->projectRoot . '/composer.json', '{}');
        $this->installed(['laravel/framework', 'laravel/horizon']);
    }

    /**
     * Ticking a suggestion adds it with its command and check; a process of one's own gets a name,
     * a command and an optional check.
     */
    public function test_init_adds_ticked_suggestions_and_processes_of_ones_own(): void
    {
        $this->laravelProject();

        // Name; Horizon and the scheduler ticked (labels, comma-separated); one of our own; PHP; Node.
        $this->init(['', 'Horizon: runs queued jobs and its dashboard (horizon),Scheduler: runs scheduled tasks every minute (schedule:work) (scheduler)', 'yes', 'mailer', 'php artisan queue:work --queue=emails', '', 'no', '', '', '8.4', '']);

        $processes = json_decode((string) file_get_contents($this->projectRoot . '/ship.json'), true)['processes'];
        self::assertSame(['command' => 'php artisan horizon', 'healthcheck' => 'php artisan horizon:status'], $processes['horizon']);
        self::assertSame('php artisan schedule:work', $processes['scheduler']);
        self::assertSame('php artisan queue:work --queue=emails', $processes['mailer']);
    }

    /**
     * Re-running shows what ship.json has, ticked, suggestions and processes of one's own alike;
     * unticking one removes it, and a changed command under a suggestion's name is kept.
     */
    public function test_re_running_init_keeps_custom_processes_and_drops_unticked_suggestions(): void
    {
        $this->laravelProject();
        file_put_contents($this->projectRoot . '/ship.json', json_encode(['php' => '8.4', 'services' => [], 'name' => 'app', 'processes' => [
            'horizon' => 'php artisan horizon --environment=production',
            'scheduler' => 'php artisan schedule:work',
            'mailer' => 'php artisan queue:work --queue=emails',
        ]]));

        // Name; Horizon and the mailer stay ticked; nothing new; PHP; Node.
        $tester = $this->init(['', 'Horizon: runs queued jobs and its dashboard (horizon),mailer: php artisan queue:work --queue=emails', 'no', '', '', '8.4', '']);

        self::assertStringContainsString('mailer: php artisan queue:work --queue=emails', $tester->getDisplay());
        $processes = json_decode((string) file_get_contents($this->projectRoot . '/ship.json'), true)['processes'];
        self::assertSame(['horizon' => 'php artisan horizon --environment=production', 'mailer' => 'php artisan queue:work --queue=emails'], $processes);

        // Unticking a process of one's own removes it.
        $this->init(['', 'Horizon: runs queued jobs and its dashboard (horizon)', 'no', '', '', '8.4', '']);
        self::assertSame(['horizon'], array_keys(json_decode((string) file_get_contents($this->projectRoot . '/ship.json'), true)['processes']));
    }

    public function test_a_process_name_already_used_is_asked_again(): void
    {
        $this->laravelProject();

        $tester = $this->init(['', 'None', 'yes', 'app', 'mailer', 'php artisan queue:work', 'php artisan queue:monitor', 'no', '', '', '8.4', '']);

        self::assertStringContainsString('"app" is already used', $tester->getDisplay());
        self::assertSame(
            ['command' => 'php artisan queue:work', 'healthcheck' => 'php artisan queue:monitor'],
            json_decode((string) file_get_contents($this->projectRoot . '/ship.json'), true)['processes']['mailer'],
        );
    }
}
