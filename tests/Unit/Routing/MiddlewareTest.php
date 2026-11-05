<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use Framework\Routing\Middleware\RequireCapability;
use Framework\Routing\Middleware\RequireLogin;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use WP_Error;
use WP_REST_Request;

final class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
    }

    public function test_require_capability_allows_a_user_holding_it(): void
    {
        WpStub::$currentUserCan = true;

        $this->assertNull((new RequireCapability('manage_options'))->handle(new WP_REST_Request()));
    }

    public function test_require_capability_denies_a_user_without_it(): void
    {
        WpStub::$currentUserCan = false;

        $result = (new RequireCapability('manage_options'))->handle(new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rest_forbidden', $result->get_error_code());
    }

    /**
     * A logged-in user lacking the capability gets 403, not 401: retrying with
     * credentials cannot possibly help, and 401 would invite exactly that.
     */
    public function test_require_capability_returns_403_for_a_logged_in_user(): void
    {
        WpStub::$currentUserCan = false;
        WpStub::$userLoggedIn = true;

        $result = (new RequireCapability('manage_options'))->handle(new WP_REST_Request());

        $this->assertSame(403, $result->get_status());
    }

    public function test_require_login_allows_a_logged_in_user(): void
    {
        WpStub::$userLoggedIn = true;

        $this->assertNull((new RequireLogin())->handle(new WP_REST_Request()));
    }

    public function test_require_login_denies_an_anonymous_request_with_401(): void
    {
        WpStub::$userLoggedIn = false;

        $result = (new RequireLogin())->handle(new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->get_status());
    }
}
