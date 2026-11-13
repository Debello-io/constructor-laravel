<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\RequestContext;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Cookie;

class RequestContextTest extends TestCase
{
    private function context(): RequestContext
    {
        return $this->app->make(RequestContext::class);
    }

    public function test_identity_comes_from_the_beacon_cookies(): void
    {
        $this->actAsShopperRequest([
            RequestContext::CLIENT_ID_COOKIE => 'beacon-client-uuid',
            RequestContext::SESSION_ID_COOKIE => '7',
        ]);

        $parameters = $this->context()->queryParameters();

        $this->assertSame('beacon-client-uuid', $parameters['i']);
        $this->assertSame(7, $parameters['s']);
        $this->assertSame('cio-be-web-bbq-test-store', $parameters['c']);
        $this->assertSame('https://store.test/search?q=grill', $parameters['origin_referrer'], 'A page request sends its own URL');
        $this->assertIsInt($parameters['_dt']);
        $this->assertArrayNotHasKey('ui', $parameters, 'Guests must not get a ui parameter');
    }

    public function test_first_visit_generates_a_client_id_cookie_and_session_one(): void
    {
        $this->actAsShopperRequest();

        $first = $this->context()->queryParameters();
        $second = $this->context()->queryParameters();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first['i']);
        $this->assertSame($first['i'], $second['i'], 'One page view must reuse one generated ID');
        $this->assertSame(1, $first['s']);

        $queued = Cookie::queued(RequestContext::CLIENT_ID_COOKIE);
        $this->assertNotNull($queued);
        $this->assertSame($first['i'], $queued->getValue());
        $this->assertFalse($queued->isHttpOnly(), 'The beacon must be able to read the cookie');
    }

    public function test_consent_resolver_omits_the_client_id(): void
    {
        $this->actAsShopperRequest([RequestContext::CLIENT_ID_COOKIE => 'beacon-client-uuid']);
        RequestContext::resolveConsentUsing(fn () => false);

        $this->assertArrayNotHasKey('i', $this->context()->queryParameters());
        $this->assertNull(Cookie::queued(RequestContext::CLIENT_ID_COOKIE));
    }

    public function test_signed_in_users_send_ui(): void
    {
        $this->actAsShopperRequest();
        $this->actingAs((new User)->forceFill(['id' => 42]));

        $this->assertSame('42', $this->context()->queryParameters()['ui']);
    }

    public function test_custom_user_id_resolver(): void
    {
        $this->actAsShopperRequest();
        RequestContext::resolveUserIdUsing(fn () => 'hashed-user');

        $this->assertSame('hashed-user', $this->context()->queryParameters()['ui']);
    }

    public function test_a_laravel_session_id_is_never_sent_as_s(): void
    {
        $this->actAsShopperRequest([RequestContext::SESSION_ID_COOKIE => '3']);

        $parameters = $this->context()->queryParameters(['session_id' => 'VUTExGRFV0RqdANe5jYIr22MlZNX9n6V']);

        $this->assertSame(3, $parameters['s']);
    }

    public function test_headers_forward_the_shopper_and_use_only_the_backend_token(): void
    {
        $this->actAsShopperRequest();

        $this->assertSame([
            'User-Agent' => 'Mozilla/5.0 (Shopper Browser)',
            'X-Forwarded-For' => '203.0.113.42',
            'x-cnstrc-token' => 'backend_security_token',
        ], $this->context()->headers());
    }

    public function test_the_api_token_is_never_used_as_the_backend_token(): void
    {
        $this->app['config']->set('constructor.backend_token', null);
        $this->app->forgetInstance(ConstructorConfig::class);
        $this->app->forgetInstance(RequestContext::class);
        $this->actAsShopperRequest();

        $this->assertArrayNotHasKey('x-cnstrc-token', $this->context()->headers());
    }

    public function test_console_context_has_no_shopper(): void
    {
        $parameters = $this->context()->queryParameters();

        $this->assertArrayNotHasKey('i', $parameters);
        $this->assertArrayNotHasKey('s', $parameters);
        $this->assertSame(['x-cnstrc-token' => 'backend_security_token'], $this->context()->headers());
    }

    public function test_ajax_requests_send_the_calling_page_as_origin_referrer(): void
    {
        $this->actAsShopperRequest(server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        $this->assertSame('https://store.test/', $this->context()->queryParameters()['origin_referrer']);
    }
}
