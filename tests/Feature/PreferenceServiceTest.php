<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Constructor as ConstructorEntry;
use ConstructorIO\Laravel\Exceptions\AuthenticationException;
use ConstructorIO\Laravel\Exceptions\ConfigurationException;
use ConstructorIO\Laravel\Exceptions\InvalidArgumentException;
use ConstructorIO\Laravel\Facades\Constructor;
use ConstructorIO\Laravel\Services\PreferenceService;
use ConstructorIO\Laravel\Support\ConstructorConfig;
use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

class PreferenceServiceTest extends TestCase
{
    private function preferences(): PreferenceService
    {
        return app(PreferenceService::class);
    }

    public function test_preferences_are_put_with_a_bearer_token_and_the_documented_body(): void
    {
        Http::fake(['user-profile.cnstrc.com/*' => Http::response(null, 204)]);

        $sent = $this->preferences()->setPreferences([
            ['user_id' => 101, 'preference_key' => 'fuel_type', 'preference_values' => ['pellet', 'charcoal']],
            ['ui' => 'u-201', 'preference_key' => 'loyalty_tier', 'values' => 'gold'],
        ]);

        $this->assertSame(2, $sent);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT'
                && $request->url() === 'https://user-profile.cnstrc.com/v1/user_preferences?key=key_test'
                && $request->header('Authorization') === ['Bearer tok_user_profile']
                && ! $request->hasHeader('x-cnstrc-token')
                && $request->data() === ['user_preferences' => [
                    ['ui' => '101', 'preference_key' => 'fuel_type', 'preference_values' => ['pellet', 'charcoal']],
                    ['ui' => 'u-201', 'preference_key' => 'loyalty_tier', 'preference_values' => ['gold']],
                ]];
        });
    }

    public function test_a_separate_user_profile_token_is_preferred_and_masked(): void
    {
        Http::fake(['user-profile.cnstrc.com/*' => Http::response(null, 204)]);
        $this->reconfigure(['constructor.user_profile_token' => 'tok_scoped_profile']);

        $this->preferences()->setUserPreference(7, 'fuel_type', 'gas');

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer tok_scoped_profile']);
        $this->assertSame('********', app(ConstructorConfig::class)->__debugInfo()['userProfileToken']);
    }

    public function test_preferences_need_their_own_token(): void
    {
        Http::fake();
        // The catalog API token is set, but it must never be used here.
        $this->reconfigure(['constructor.user_profile_token' => null]);

        $this->expectException(ConfigurationException::class);
        $this->preferences()->setUserPreference(7, 'fuel_type', 'gas');
    }

    public function test_batches_of_100_are_sent(): void
    {
        Http::fake(['user-profile.cnstrc.com/*' => Http::response(null, 204)]);

        $this->preferences()->setPreferences(array_map(
            fn (int $i) => ['user_id' => $i, 'preference_key' => 'fuel_type', 'preference_values' => ['gas']],
            range(1, 250),
        ));

        $this->assertSame([100, 100, 50], array_map(
            fn (Request $request) => count($request->data()['user_preferences']),
            $this->sentRequests(),
        ));
    }

    public function test_invalid_preferences_are_rejected_before_anything_is_sent(): void
    {
        Http::fake();

        foreach ([
            ['preference_key' => 'fuel_type', 'preference_values' => ['gas']],
            ['user_id' => 1, 'preference_values' => ['gas']],
            ['user_id' => 1, 'preference_key' => 'fuel_type', 'preference_values' => ['', null]],
        ] as $invalid) {
            try {
                $this->preferences()->setPreferences([
                    ['user_id' => 1, 'preference_key' => 'ok', 'preference_values' => ['ok']],
                    $invalid,
                ]);
                $this->fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('#1', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_current_user_uses_the_storefront_user_id(): void
    {
        Http::fake(['user-profile.cnstrc.com/*' => Http::response(null, 204)]);
        $this->actAsShopperRequest();
        ConstructorEntry::resolveUserIdUsing(fn (?HttpRequest $request) => 'hashed-42');

        $this->assertTrue($this->preferences()->setForCurrentUser('fuel_type', ['pellet']));

        Http::assertSent(fn (Request $request) => $request->data()['user_preferences'][0]['ui'] === 'hashed-42');
    }

    public function test_current_user_sends_nothing_for_guests_or_without_consent(): void
    {
        Http::fake();
        $this->actAsShopperRequest();

        $this->assertFalse($this->preferences()->setForCurrentUser('fuel_type', ['pellet']));

        ConstructorEntry::resolveUserIdUsing(fn (?HttpRequest $request) => 'hashed-42');
        ConstructorEntry::resolveConsentUsing(fn (?HttpRequest $request) => false);
        $this->assertFalse($this->preferences()->setForCurrentUser('fuel_type', ['pellet']));

        Http::assertNothingSent();
    }

    public function test_files_are_uploaded_as_multipart_with_the_bearer_token(): void
    {
        Http::fake(['user-profile.cnstrc.com/*' => Http::response(null, 204)]);
        $path = sys_get_temp_dir().'/constructor-preferences-'.uniqid().'.jsonl';
        file_put_contents($path, '{"preference_key": "fuel_type", "preference_values": ["pellet"], "ui": "101"}'."\n");

        try {
            $this->preferences()->uploadPreferencesFile($path);
        } finally {
            unlink($path);
        }

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->isMultipart()
            && $request->header('Authorization') === ['Bearer tok_user_profile']
            && ($request->data()[0]['name'] ?? null) === 'file');
    }

    public function test_a_missing_file_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->preferences()->uploadPreferencesFile('/nonexistent/preferences.jsonl');
    }

    public function test_writes_throw_and_are_not_retried(): void
    {
        Http::fake(['user-profile.cnstrc.com/*' => Http::response(['message' => 'missing scope'], 403)]);

        try {
            $this->preferences()->setUserPreference(7, 'fuel_type', 'gas');
            $this->fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringNotContainsString('missing scope', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_the_facade_exposes_the_service(): void
    {
        $this->assertSame($this->preferences(), Constructor::preferences());
    }
}
