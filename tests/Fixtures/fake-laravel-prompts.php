<?php

declare(strict_types=1);

/**
 * A hand-rolled stand-in for \Laravel\Prompts\select(), used only by
 * InitCommandLaravelPromptsTest -- required in that test's own isolated process (see its
 * #[RunInSeparateProcess] attribute), never in the main suite's process. Installing the real
 * laravel/prompts package as a require-dev dependency was tried first and reverted: its helper
 * functions autoload globally for the whole PHP process via Composer's "files" autoloading, which
 * made function_exists('Laravel\Prompts\select') true for every OTHER InitCommand test too, once
 * they ran in the same process -- breaking every one of them, since they specifically exercise
 * the ChoiceQuestion fallback on the assumption that laravel/prompts isn't installed at all (see
 * InitCommand::canUseLaravelPromptsInteractiveUi()'s own docblock). This fake only ever exists in
 * one isolated process, so it can never leak into those other tests.
 */

namespace Laravel\Prompts;

/**
 * Always "picks" the first real (non-"__none__") option rather than a fixed literal, so every
 * group prompt in the same InitCommand run gets a genuinely valid answer for whatever it's
 * actually asking about, not one hardcoded value reused everywhere it doesn't belong.
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
