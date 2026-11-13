<?php

namespace ConstructorIO\Laravel\Tests\Feature;

use ConstructorIO\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * constructor:verify, constructor:upload and constructor:task.
 */
class ConsoleCommandsTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function temporaryFile(string $extension, string $contents): string
    {
        $path = sys_get_temp_dir().'/constructor-cli-'.uniqid().'.'.$extension;
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * Run a command and return its exit code and everything it printed.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{int, string}
     */
    private function runCommand(string $command, array $parameters = []): array
    {
        $exitCode = Artisan::call($command, $parameters);

        return [$exitCode, Artisan::output()];
    }

    /*
    |--------------------------------------------------------------------------
    | constructor:verify
    |--------------------------------------------------------------------------
    */

    public function test_verify_passes_and_never_prints_secrets(): void
    {
        $this->reconfigure(['constructor.api_key' => 'key_K2pX7vBnU0bgA5xp']);
        Http::fake([
            'ac.cnstrc.com/browse/groups*' => Http::response(['response' => ['groups' => []]]),
            'ac.cnstrc.com/v2/items*' => Http::response(['items' => [], 'total_count' => 0]),
        ]);

        [$exitCode, $output] = $this->runCommand('constructor:verify');

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('key_…A5xp', $output);
        $this->assertStringContainsString('accepted the API key and backend token', $output);
        $this->assertStringContainsString('accepted the API token', $output);
        $this->assertStringNotContainsString('key_K2pX7vBnU0bgA5xp', $output);
        $this->assertStringNotContainsString('tok_secret_api_token', $output);
        $this->assertStringNotContainsString('backend_security_token', $output);

        // The storefront check carries the backend token and never the API token;
        // the catalog check uses the API token as Basic auth.
        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/browse/groups'
            && $request->header('x-cnstrc-token') === ['backend_security_token']
            && ! $request->hasHeader('Authorization'));
        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/v2/items'
            && $request->header('Authorization') === ['Basic '.base64_encode('tok_secret_api_token:')]);
    }

    public function test_verify_fails_without_an_api_key_and_sends_nothing(): void
    {
        $this->reconfigure(['constructor.api_key' => null]);
        Http::fake();

        [$exitCode, $output] = $this->runCommand('constructor:verify');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('CONSTRUCTOR_API_KEY is not set', $output);
        Http::assertNothingSent();
    }

    public function test_verify_explains_throttling_without_a_backend_token(): void
    {
        $this->reconfigure(['constructor.backend_token' => null, 'constructor.retry_attempts' => 1]);
        Http::fake([
            'ac.cnstrc.com/browse/groups*' => Http::response(['message' => 'Too many requests'], 429),
            'ac.cnstrc.com/v2/items*' => Http::response(['items' => []]),
        ]);

        [$exitCode, $output] = $this->runCommand('constructor:verify');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Throttled (429). Set CONSTRUCTOR_BACKEND_TOKEN', $output);
    }

    public function test_verify_reports_a_rejected_api_token(): void
    {
        Http::fake([
            'ac.cnstrc.com/browse/groups*' => Http::response(['response' => ['groups' => []]]),
            'ac.cnstrc.com/v2/items*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        [$exitCode, $output] = $this->runCommand('constructor:verify');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Constructor rejected the API token', $output);
    }

    public function test_verify_offline_only_checks_configuration(): void
    {
        $this->reconfigure(['constructor.backend_token' => null]);
        Http::fake();

        [$exitCode, $output] = $this->runCommand('constructor:verify', ['--offline' => true]);

        // A missing backend token is a warning, not a failure.
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('CONSTRUCTOR_BACKEND_TOKEN is not set', $output);
        Http::assertNothingSent();
    }

    public function test_verify_reports_an_invalid_host(): void
    {
        $this->reconfigure(['constructor.search_base_url' => 'http://ac.cnstrc.com']);
        Http::fake();

        [$exitCode, $output] = $this->runCommand('constructor:verify');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('https', $output);
        Http::assertNothingSent();
    }

    /*
    |--------------------------------------------------------------------------
    | constructor:upload
    |--------------------------------------------------------------------------
    */

    public function test_upload_patch_waits_for_the_task_and_shows_the_result(): void
    {
        Http::fake([
            'ac.cnstrc.com/v1/catalog*' => Http::response(['task_id' => 5, 'task_status_path' => '/v1/tasks/5']),
            'ac.cnstrc.com/v1/tasks/5*' => Http::response([
                'id' => 5,
                'type' => 'ingestion',
                'status' => 'DONE',
                'filename' => 'prices.csv',
                'result' => [
                    'index_built' => true,
                    'changelog' => ['sections' => ['Products' => ['items_updated' => 2]]],
                    'ingestion_warnings' => [['code' => 'MISSING_FIELD', 'message' => "Field 'price' is missing for item 'A-1'"]],
                ],
            ]),
        ]);

        [$exitCode, $output] = $this->runCommand('constructor:upload', [
            'items' => $this->temporaryFile('csv', "id,price\nA-1,10"),
            '--operation' => 'patch',
            '--patch-delta' => true,
            '--on-missing' => 'create',
            '--email' => ['catalog@example.com'],
            '--wait' => true,
        ]);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('task 5', $output);
        $this->assertStringContainsString('DONE', $output);
        $this->assertStringContainsString('items updated: 2', $output);
        $this->assertStringContainsString('MISSING_FIELD', $output);
        $this->assertStringNotContainsString('tok_secret_api_token', $output);

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && $this->pathOf($request) === '/v1/catalog'
            && $this->queryOf($request)['patch_delta'] === ['true']
            && $this->queryOf($request)['on_missing'] === ['CREATE']
            && $this->queryOf($request)['notification_email'] === ['catalog@example.com']);
    }

    public function test_upload_without_wait_points_to_the_task_command(): void
    {
        Http::fake(['ac.cnstrc.com/v1/catalog*' => Http::response(['task_id' => 9, 'task_status_path' => '/v1/tasks/9'])]);

        [$exitCode, $output] = $this->runCommand('constructor:upload', [
            '--variations' => $this->temporaryFile('jsonl', '{"id":"V-1","item_id":"A-1"}'),
        ]);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('constructor:task 9 --wait', $output);

        // Testbench isn't "production", so create_or_replace runs without asking.
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $this->queryOf($request)['format'] === ['jsonl']);
        Http::assertSentCount(1);
    }

    public function test_upload_rejects_bad_arguments_before_sending_anything(): void
    {
        Http::fake();
        $file = $this->temporaryFile('csv', 'id');

        $cases = [
            [['items' => '/does/not/exist.csv'], "Can't read /does/not/exist.csv"],
            [[], 'Pass an items file'],
            [['items' => $file, '--operation' => 'delete'], 'Constructor has no delete upload'],
            [['items' => $file, '--patch-delta' => true], '--patch-delta only works with --operation=patch'],
            [['items' => $file, '--operation' => 'patch', '--on-missing' => 'CREATE'], '--on-missing only works with --patch-delta'],
            [['items' => $file, '--operation' => 'patch', '--patch-delta' => true, '--on-missing' => 'skip'], '--on-missing must be FAIL, IGNORE or CREATE'],
            [['items' => $file, '--mode' => 'partial'], '--mode must be full or ids'],
            [['items' => $file, '--timeout' => 'soon'], '--timeout must be a whole number'],
        ];

        foreach ($cases as [$parameters, $message]) {
            [$exitCode, $output] = $this->runCommand('constructor:upload', $parameters);

            $this->assertSame(2, $exitCode, $output);
            $this->assertStringContainsString($message, $output);
        }

        Http::assertNothingSent();
    }

    public function test_replacing_the_catalog_in_production_needs_confirmation(): void
    {
        app()->instance('env', 'production');
        Http::fake(['ac.cnstrc.com/v1/catalog*' => Http::response(['task_id' => 3])]);
        $file = $this->temporaryFile('csv', "id,name\n1,Grill");

        // Laravel only routes prompts to test doubles in the "testing"
        // environment, so run non-interactively: the prompt's answer is "no".
        [$exitCode, $output] = $this->runCommand('constructor:upload', ['items' => $file, '--no-interaction' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Command cancelled', $output);
        Http::assertNothingSent();

        [$exitCode] = $this->runCommand('constructor:upload', ['items' => $file, '--force' => true, '--allow-large-removal' => true]);

        $this->assertSame(0, $exitCode);

        // --force skips the prompt; --allow-large-removal is Constructor's own `force`.
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $this->queryOf($request)['force'] === ['true']);
    }

    public function test_upload_fails_when_the_task_fails(): void
    {
        Http::fake([
            'ac.cnstrc.com/v1/catalog*' => Http::response(['task_id' => 6]),
            'ac.cnstrc.com/v1/tasks/6*' => Http::response(['id' => 6, 'status' => 'FAILED', 'error' => ['message' => 'Missing column: name']]),
        ]);

        [$exitCode, $output] = $this->runCommand('constructor:upload', [
            'items' => $this->temporaryFile('csv', 'id'),
            '--wait' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FAILED', $output);
        $this->assertStringContainsString('Missing column: name', $output);
    }

    public function test_upload_reports_rejected_requests_without_the_body_unless_verbose(): void
    {
        Http::fake(['ac.cnstrc.com/v1/catalog*' => Http::response(['message' => 'Invalid file format'], 400)]);
        $file = $this->temporaryFile('csv', 'id');

        [$exitCode, $output] = $this->runCommand('constructor:upload', ['items' => $file]);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('rejected the request (400)', $output);
        $this->assertStringNotContainsString('Invalid file format', $output);

        [, $verboseOutput] = $this->runCommand('constructor:upload', ['items' => $file, '--verbose' => true]);
        $this->assertStringContainsString('Invalid file format', $verboseOutput);
    }

    /*
    |--------------------------------------------------------------------------
    | constructor:task
    |--------------------------------------------------------------------------
    */

    public function test_task_shows_a_running_task(): void
    {
        Http::fake(['ac.cnstrc.com/v1/tasks/12*' => Http::response(['id' => 12, 'status' => 'IN_PROGRESS', 'submission_time' => '2026-11-13T10:00:00Z'])]);

        [$exitCode, $output] = $this->runCommand('constructor:task', ['id' => '12']);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('IN_PROGRESS', $output);
        $this->assertStringContainsString('2026-11-13T10:00:00Z', $output);
        Http::assertSent(fn (Request $request) => $this->pathOf($request) === '/v1/tasks/12'
            && $request->header('Authorization') === ['Basic '.base64_encode('tok_secret_api_token:')]);
    }

    public function test_task_wait_polls_until_done(): void
    {
        Sleep::fake();
        Http::fakeSequence('ac.cnstrc.com/v1/tasks/12*')
            ->push(['id' => 12, 'status' => 'QUEUED'])
            ->push(['id' => 12, 'status' => 'DONE', 'result' => ['index_built' => false, 'changelog' => []]]);

        [$exitCode, $output] = $this->runCommand('constructor:task', ['id' => '12', '--wait' => true]);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('DONE', $output);
        $this->assertStringContainsString('not yet', $output);
        Http::assertSentCount(2);
    }

    public function test_task_timeout_and_failure_exit_with_one(): void
    {
        Http::fake([
            'ac.cnstrc.com/v1/tasks/12*' => Http::response(['id' => 12, 'status' => 'IN_PROGRESS']),
            'ac.cnstrc.com/v1/tasks/13*' => Http::response(['id' => 13, 'status' => 'CANCELED']),
        ]);

        [$exitCode, $output] = $this->runCommand('constructor:task', ['id' => '12', '--wait' => true, '--timeout' => '0']);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('constructor:task 12 --wait', $output);

        [$exitCode, $output] = $this->runCommand('constructor:task', ['id' => '13']);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('CANCELED', $output);
    }

    public function test_task_rejects_an_invalid_id(): void
    {
        Http::fake();

        [$exitCode] = $this->runCommand('constructor:task', ['id' => '../items']);

        $this->assertSame(2, $exitCode);
        Http::assertNothingSent();
    }
}
