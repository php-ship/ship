<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Console\Commands\DownCommand;
use Ship\Runtime\ProcessRunner;
use Symfony\Component\Console\Input\ArrayInput;

final class DownCommandTest extends TestCase
{
    public function test_it_omits_volumes_by_default(): void
    {
        $command = $this->buildCommand([]);

        self::assertNotContains('--volumes', $command);
    }

    public function test_it_appends_volumes_when_the_option_is_passed(): void
    {
        $command = $this->buildCommand(['--volumes' => true]);

        self::assertContains('--volumes', $command);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function buildCommand(array $options): array
    {
        $command = new DownCommand(sys_get_temp_dir(), new ProcessRunner());
        $input = new ArrayInput($options, $command->getDefinition());

        $method = new \ReflectionMethod($command, 'buildCommand');

        /** @var list<string> */
        return $method->invoke($command, $input);
    }
}
