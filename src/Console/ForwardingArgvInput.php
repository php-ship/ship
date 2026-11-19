<?php

declare(strict_types=1);

namespace Ship\Console;

use Symfony\Component\Console\Input\ArgvInput;

/**
 * The command line, where everything after a forwarding command's name (`ship npm`, `ship exec`,
 * `ship artisan`, ...) belongs to the command it runs, not to ship.
 *
 * Symfony's Application looks for its global options (--version, --help, --quiet, --silent,
 * --no-interaction, -v, ...) anywhere on the line, so `ship pnpm --version` printed ship's version,
 * `ship artisan migrate --help` showed ship's help, and `ship npm install --silent` silenced ship.
 * Here those lookups only see the tokens before the forwarding command's name: `ship -q npm …`
 * still quiets ship, `ship npm … -q` only quiets npm. The forwarded tokens are untouched (see
 * getRawTokens()).
 */
final class ForwardingArgvInput extends ArgvInput
{
    /**
     * Ship's own part of the command line: its options and the command name, without what follows.
     */
    private readonly ArgvInput $shipPart;

    /**
     * @param list<string> $argv including the script name, as $_SERVER['argv']
     * @param list<string> $forwardingCommands names of the commands that forward what follows them
     */
    public function __construct(array $argv, array $forwardingCommands)
    {
        parent::__construct($argv);

        $tokens = array_slice($argv, 1);
        $position = null;
        foreach ($tokens as $index => $token) {
            if (in_array($token, $forwardingCommands, true)) {
                $position = $index;
                break;
            }
        }

        $this->shipPart = new ArgvInput([$argv[0] ?? 'ship', ...($position === null ? $tokens : array_slice($tokens, 0, $position + 1))]);
    }

    /**
     * @param string|array<mixed> $values
     */
    public function hasParameterOption(string|array $values, bool $onlyParams = false): bool
    {
        return $this->shipPart->hasParameterOption($values, $onlyParams);
    }

    /**
     * @param string|array<mixed> $values
     * @param string|bool|int|float|array<mixed>|null $default
     */
    public function getParameterOption(string|array $values, string|bool|int|float|array|null $default = false, bool $onlyParams = false): mixed
    {
        return $this->shipPart->getParameterOption($values, $default, $onlyParams);
    }
}
