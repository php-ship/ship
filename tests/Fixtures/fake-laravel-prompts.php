<?php

declare(strict_types=1);

/**
 * A stand-in for \Laravel\Prompts\select(), required only by InitCommandLaravelPromptsTest in
 * its own isolated process. The real package can't be a dev dependency: its functions autoload
 * globally and would switch every other InitCommand test off the ChoiceQuestion fallback.
 */

namespace Laravel\Prompts;

/**
 * Picks the first real (non-"__none__") option, which is a valid answer for any group prompt.
 */
function select(string $label, array $options, mixed $default = null): int|string
{
    foreach ($options as $key => $optionLabel) {
        if ($key !== '__none__') {
            return $key;
        }
    }

    return $default;
}
