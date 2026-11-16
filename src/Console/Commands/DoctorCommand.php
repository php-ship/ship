<?php

declare(strict_types=1);

namespace Ship\Console\Commands;

use Ship\Config\ShipConfig;
use Ship\Docker\ComposeCommand;
use Ship\Docker\ProjectName;
use Ship\Docker\StackHealth;
use Ship\Runtime\ProcessRunner;
use Ship\Support\ShipVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Reports versions and the state and health of every container in the development stack, with a
 * recovery hint for anything that isn't running. Python Ship has the same command.
 *
 * It reads the compose file `ship up` last generated instead of regenerating it, so it never
 * changes what `ship exec` and friends run against (the hostUser marker, Mutagen's volume).
 */
#[AsCommand(name: 'doctor', description: 'Report versions and the state and health of every container')]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = ShipConfig::fromFile($this->projectRoot . '/ship.json');
        $recordedVersionFile = $this->projectRoot . '/ship/.ship-version';
        $recorded = is_file($recordedVersionFile) ? trim((string) file_get_contents($recordedVersionFile)) : '';
        $installed = ShipVersion::current() ?? 'unknown';

        $output->writeln(sprintf(
            'php-ship/ship %s; project %s; PHP %s; Node.js %s',
            $installed,
            ProjectName::resolve($config, $this->projectRoot),
            $config->phpVersion,
            $config->nodeVersion,
        ));
        $output->writeln('Published ship/: php-ship/ship ' . ($recorded !== '' ? $recorded : 'unknown'));

        if ($recorded !== '' && $installed !== 'unknown' && $recorded !== $installed) {
            $output->writeln("<comment>ship: ship/ was published by {$recorded}, but {$installed} is installed. Run `ship init` again to pick up stub changes.</comment>");
        }

        $composePath = $this->projectRoot . '/ship/docker-compose.generated.yml';

        if (!is_file($composePath)) {
            $output->writeln('<error>ship: the development stack has never been started here; run `ship up`.</error>');

            return Command::FAILURE;
        }

        $compose = Yaml::parseFile($composePath);
        /** @var array<string, array<string, mixed>> $services */
        $services = is_array($compose) && is_array($compose['services'] ?? null) ? $compose['services'] : [];

        $result = $this->runner->runQuietWithResult(
            [...ComposeCommand::baseArgs($this->projectRoot), 'ps', '--all', '--format', 'json'],
            $this->projectRoot,
        );

        if ($result['exitCode'] !== 0) {
            $output->writeln(sprintf(
                '<error>ship: could not inspect Docker Compose: %s Check that Docker is running.</error>',
                $result['errorOutput'] !== '' ? $result['errorOutput'] : 'no output.',
            ));

            return Command::FAILURE;
        }

        $report = StackHealth::diagnose($services, StackHealth::parseRecords($result['output']));

        foreach ($report['lines'] as $line) {
            $output->writeln($line);
        }

        if ($report['problems'] !== []) {
            foreach ($report['problems'] as $problem) {
                $output->writeln("<error>ship: {$problem}</error>");
            }
            $output->writeln('<error>ship: run `ship logs SERVICE` to see why, then `ship up` to rebuild or restart the stack.</error>');

            return Command::FAILURE;
        }

        $output->writeln('Every service is running.');

        return Command::SUCCESS;
    }
}
