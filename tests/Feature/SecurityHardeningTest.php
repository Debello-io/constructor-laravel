<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Http\Path;
use ConstructorIO\Laravel\Services\SearchService;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Support\RequestContext;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;

/**
 * What must never leave the application, and inputs that must never break it.
 */
class SecurityHardeningTest extends TestCase
{
    private function context(): RequestContext
    {
        return app(RequestContext::class);
    }

    public function test_query_strings_are_never_sent_as_origin_referrer(): void
    {
        $this->actAsShopperRequest(url: 'https://store.test/password/reset?token=SECRET&email=shopper@example.com');
        $this->assertSame('https://store.test/password/reset', $this->context()->queryParameters()['origin_referrer']);

        $this->actAsShopperRequest(server: [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_REFERER' => 'https://store.test/account?email=shopper@example.com',
        ]);
        $this->assertSame('https://store.test/account', $this->context()->queryParameters()['origin_referrer']);
    }

    public function test_a_hostile_user_agent_is_cleaned_instead_of_breaking_search(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['results' => []]])]);
        $this->actAsShopperRequest(server: ['HTTP_USER_AGENT' => "Mozilla\x01evil\r\nX-Injected: 1"]);

        app(SearchService::class)->search('grill');

        Http::assertSent(fn (Request $request) => $request->header('User-Agent') === ['MozillaevilX-Injected: 1']);
    }

    public function test_consent_applies_to_an_explicit_client_id(): void
    {
        $this->actAsShopperRequest();
        RequestContext::resolveConsentUsing(fn () => false);

        $this->assertArrayNotHasKey('i', $this->context()->queryParameters(['client_id' => 'app-sdk-client-id']));
    }

    public function test_require_consent_withholds_the_client_id_until_a_resolver_says_yes(): void
    {
        $this->reconfigure(['constructor.identity.require_consent' => true]);
        $this->actAsShopperRequest([RequestContext::CLIENT_ID_COOKIE => 'beacon-client']);

        $this->assertArrayNotHasKey('i', $this->context()->queryParameters());
        $this->assertNull(Cookie::queued(RequestContext::CLIENT_ID_COOKIE));

        RequestContext::resolveConsentUsing(fn () => true);
        $this->assertSame('beacon-client', $this->context()->queryParameters()['i']);
    }

    public function test_generated_cookie_is_secure_on_https(): void
    {
        $this->actAsShopperRequest(server: ['HTTPS' => 'on']);

        $this->context()->queryParameters();

        $cookie = Cookie::queued(RequestContext::CLIENT_ID_COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function test_endpoint_parameters_cannot_override_key_or_identity(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['results' => []]])]);
        $this->actAsShopperRequest([RequestContext::CLIENT_ID_COOKIE => 'real-client']);

        app(SearchService::class)->search('grill', [], [
            'parameters' => ['key' => 'key_other', 'i' => 'victim', 'c' => 'spoofed'],
        ]);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return $query['key'] === ['key_test'] && $query['i'] === ['real-client'] && $query['c'] === ['cio-be-web-bbq-test-store'];
        });
    }

    public function test_error_messages_and_logs_never_contain_the_response_body(): void
    {
        $log = $this->spyOnLog();
        Http::fake(['ac.cnstrc.com/*' => Http::response(['message' => 'Unknown filter shopper@example.com'], 400)]);

        app(SearchService::class)->search('grill');

        $log->shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) {
            return ! str_contains($message.json_encode($context), 'shopper@example.com');
        });
    }

    public function test_the_response_body_is_still_available_for_debugging(): void
    {
        $this->reconfigure(['constructor.throw_exceptions' => true]);
        Http::fake(['ac.cnstrc.com/*' => Http::response(['message' => 'Unknown filter colour'], 400)]);

        try {
            app(SearchService::class)->search('grill');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertStringNotContainsString('Unknown filter', $e->getMessage());
            $this->assertStringContainsString('Unknown filter colour', $e->getResponseBody());
        }
    }

    public function test_dot_segments_cannot_become_parent_paths(): void
    {
        $this->assertSame('%2E%2E', Path::segment('..'));
        $this->assertSame('%2E', Path::segment('.'));
        $this->assertSame('1%2F2%22%20grate', Path::segment('1/2" grate'));
        $this->assertSame('v1.2', Path::segment('v1.2'));
    }

    public function test_plain_http_hosts_are_rejected(): void
    {
        $this->expectException(ConfigurationException::class);

        ConstructorConfig::fromArray(['search_base_url' => 'http://ac.cnstrc.com']);
    }

    public function test_tokens_are_masked_in_dumps(): void
    {
        $dump = print_r(app(ConstructorConfig::class), true);

        $this->assertStringNotContainsString('tok_secret_api_token', $dump);
        $this->assertStringNotContainsString('backend_security_token', $dump);
    }

    public function test_missing_backend_token_is_reported_once(): void
    {
        $this->reconfigure(['constructor.backend_token' => null]);
        $log = $this->spyOnLog();
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['results' => []]])]);

        app(SearchService::class)->search('grill');
        app(SearchService::class)->search('smoker');

        $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'CONSTRUCTOR_BACKEND_TOKEN'));
    }

    public function test_nested_filter_input_is_ignored_rather_than_crashing(): void
    {
        Http::fake(['ac.cnstrc.com/*' => Http::response(['response' => ['results' => []]])]);

        app(SearchService::class)->search('grill', ['color' => ['x' => ['y' => '1']], 'brand' => ['Weber']]);

        Http::assertSent(fn (Request $request) => ! isset($this->queryOf($request)['filters[color]'])
            && $this->queryOf($request)['filters[brand]'] === ['Weber']);
    }
}
