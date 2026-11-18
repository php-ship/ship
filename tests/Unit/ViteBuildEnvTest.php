<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Docker\ViteBuildEnv;
use Symfony\Component\Process\Process;

final class ViteBuildEnvTest extends TestCase
{
    /**
     * Only VITE_* settings reach the build: everything else in .env.production may be secret.
     */
    public function test_only_vite_settings_are_passed(): void
    {
        $rendered = ViteBuildEnv::render(['APP_KEY' => 'base64:secret', 'DB_PASSWORD' => 'hunter2', 'VITE_APP_NAME' => 'Client']);

        self::assertSame('export VITE_APP_NAME=Client', $rendered);
        self::assertStringNotContainsString('secret', $rendered);
        self::assertStringNotContainsString('hunter2', $rendered);
    }

    /**
     * Laravel's default .env sets VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"; Laravel resolves that at
     * runtime, so the build has to resolve it too, or the bundle gets a literal "${...}".
     */
    public function test_references_to_other_settings_are_resolved(): void
    {
        $rendered = ViteBuildEnv::render([
            'REVERB_APP_KEY' => 'abc123',
            'REVERB_HOST' => 'ws.example.com',
            'VITE_REVERB_APP_KEY' => '${REVERB_APP_KEY}',
            'VITE_REVERB_HOST' => 'wss://${REVERB_HOST}/app',
            'VITE_UNSET' => '${NOT_DEFINED}',
        ]);

        self::assertSame(
            "export VITE_REVERB_APP_KEY=abc123\nexport VITE_REVERB_HOST=wss://ws.example.com/app\nexport VITE_UNSET=''",
            $rendered,
        );
    }

    public function test_nothing_is_rendered_without_vite_settings(): void
    {
        self::assertSame('', ViteBuildEnv::render(['APP_KEY' => 'x']));
    }

    /**
     * The assets stage `eval`s the lines in sh: every value must come out exactly as written,
     * whatever quotes, dollars or spaces it holds, and no value may run as a command.
     */
    public function test_values_survive_the_shell_exactly(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Needs a POSIX shell.');
        }

        $values = [
            'VITE_QUOTES' => "It's \"quoted\"",
            'VITE_DOLLAR' => 'costs $5 and $(whoami) `id`',
            'VITE_SPACES' => '  padded  value ',
            'VITE_HASH' => 'a#b ; echo injected',
            'VITE_EMPTY' => '',
        ];

        $script = ViteBuildEnv::render($values) . "\n" . 'for v in ' . implode(' ', array_keys($values)) . '; do eval "printf \'%s\037\' \"\$$v\""; done';
        $process = new Process(['sh', '-c', $script]);
        $process->mustRun();

        // Each value is followed by one separator; drop only the last one (a value may be empty).
        self::assertSame(array_values($values), explode("\x1f", substr($process->getOutput(), 0, -1)));
        self::assertStringNotContainsString('injected', str_replace('echo injected', '', $process->getOutput()));
    }
}
