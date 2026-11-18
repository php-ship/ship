<?php

declare(strict_types=1);

namespace Ship\Docker;

/**
 * A `ship release` failure with a message for the user. ReleaseWriter has already removed its
 * staging folder when this is thrown.
 */
final class ReleaseFailed extends \RuntimeException
{
}
