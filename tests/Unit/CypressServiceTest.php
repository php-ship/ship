<?php

declare(strict_types=1);

namespace Ship\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ship\Contracts\ShipEnvironment;
use Ship\Services\CypressService;

final class CypressServiceTest extends TestCase
{
    public function test_it_is_absent_from_production(): void
    {
        self::assertSame([], (new CypressService())->composeFragment(ShipEnvironment::Production));
    }

    /**
     * The image's own entrypoint runs the suite once and exits; under the restart policy that
     * would loop forever.
     */
    public function test_it_idles_with_the_project_mounted_instead_of_running_the_suite_on_start(): void
    {
        $fragment = (new CypressService())->composeFragment(ShipEnvironment::Development)['cypress'];

        self::assertMatchesRegularExpression('/^cypress\/included:\d+\.\d+\.\d+$/', $fragment['image']);
        self::assertSame(['sleep', 'infinity'], $fragment['entrypoint']);
        self::assertTrue($fragment['init']);
        self::assertSame('/e2e', $fragment['working_dir']);
        self::assertSame(['.:/e2e'], $fragment['volumes']);
        self::assertSame('2gb', $fragment['shm_size']);
    }

    /**
     * npm install runs in the Alpine app container, which Cypress's binary doesn't support.
     */
    public function test_the_app_skips_downloading_the_cypress_binary(): void
    {
        self::assertSame(['CYPRESS_INSTALL_BINARY' => '0'], (new CypressService())->environmentVariables());
    }

    public function test_cypress_itself_gets_the_internal_base_url(): void
    {
        self::assertSame(['cypress' => 'CYPRESS_BASE_URL'], (new CypressService())->appUrlVariables());
    }
}
