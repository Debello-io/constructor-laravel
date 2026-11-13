<?php

namespace ConstructorIO\Laravel\Console;

use ConstructorIO\Laravel\Console\Concerns\RendersTaskStatus;
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use ConstructorIO\Laravel\Exceptions\InvalidCatalogDataException;
use ConstructorIO\Laravel\Exceptions\TaskTimeoutException;
use ConstructorIO\Laravel\Services\CatalogService;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Upload catalog files (items, variations, item groups) to Constructor.
 *
 *     php artisan constructor:upload storage/catalog/items.jsonl --variations=storage/catalog/variations.jsonl --wait
 *     php artisan constructor:upload storage/catalog/prices.csv --operation=patch --patch-delta
 *
 * create_or_replace (the default) replaces the whole section with the
 * files, so in production it asks for confirmation unless --force is given.
 *
 * @see https://docs.constructor.com/reference/v1-catalog-create-or-replace-catalog
 * @see https://docs.constructor.com/reference/v1-catalog-update-catalog
 */
class UploadCommand extends Command
{
    use ConfirmableTrait;
    use RendersTaskStatus;

    /** Constructor's upload endpoint only has PUT (create_or_replace) and PATCH (patch). */
    private const OPERATIONS = [CatalogService::OPERATION_CREATE_OR_REPLACE, CatalogService::OPERATION_PATCH];

    private const ON_MISSING_VALUES = ['FAIL', 'IGNORE', 'CREATE'];

    private const MODES = ['full', 'ids'];

    protected $signature = 'constructor:upload
        {items? : Items file (CSV or JSONL)}
        {--variations= : Variations file}
        {--item-groups= : Item groups (categories) file}
        {--operation=create_or_replace : create_or_replace replaces the whole section; patch replaces only the records in the files}
        {--section= : Index section (default: CONSTRUCTOR_SECTION)}
        {--patch-delta : With --operation=patch, change only the fields present in the files}
        {--on-missing= : With --patch-delta, what to do with records that don\'t exist: FAIL, IGNORE or CREATE}
        {--mode= : With create_or_replace: full (default), or ids when the files only list the IDs to keep}
        {--email=* : Who Constructor emails if the upload fails (default: CONSTRUCTOR_NOTIFICATION_EMAIL)}
        {--allow-large-removal : Apply the upload even if it would remove more than half of the catalog}
        {--wait : Wait until Constructor has processed the upload}
        {--timeout= : Seconds to wait with --wait (default: CONSTRUCTOR_TASK_TIMEOUT)}
        {--force : Don\'t ask for confirmation in production}';

    protected $description = 'Upload catalog files to Constructor';

    public function handle(CatalogService $catalog): int
    {
        $operation = $this->stringOption('operation') ?? CatalogService::OPERATION_CREATE_OR_REPLACE;
        $files = $this->files();
        $timeout = $this->timeoutOption();

        $problem = $this->validationProblem($operation, $files, $timeout);
        if ($problem !== null) {
            $this->components->error($problem);

            return self::INVALID;
        }

        $section = $this->stringOption('section') ?? $catalog->defaultSection();

        if ($operation === CatalogService::OPERATION_CREATE_OR_REPLACE
            && ! $this->confirmToProceed("This replaces the whole {$section} catalog")) {
            return self::FAILURE;
        }

        try {
            $upload = $catalog->uploadCatalog($files['items'] ?? null, $operation, $this->uploadOptions($files, $section));
        } catch (InvalidCatalogDataException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        } catch (ConstructorException $e) {
            $this->renderConstructorError($e);

            return self::FAILURE;
        }

        $taskId = $upload['task_id'];

        if ($taskId === null) {
            // 202 Accepted: queued before a task existed, so there is nothing to poll.
            $this->components->info('Constructor accepted the upload. '.($upload['message'] ?? ''));

            return self::SUCCESS;
        }

        $this->components->info("Constructor accepted the upload as task {$taskId}.");

        if (! $this->option('wait')) {
            $this->line("Follow its progress with: php artisan constructor:task {$taskId} --wait");

            return self::SUCCESS;
        }

        return $this->waitFor($catalog, $taskId, $timeout === false ? null : $timeout);
    }

    private function waitFor(CatalogService $catalog, int $taskId, ?int $timeout): int
    {
        try {
            $status = $catalog->waitForTaskCompletion($taskId, $timeout);
        } catch (TaskTimeoutException $e) {
            $this->components->warn($e->getMessage());
            $this->line("Check again later: php artisan constructor:task {$taskId} --wait");

            return self::FAILURE;
        } catch (ConstructorException $e) {
            $this->renderConstructorError($e);

            return self::FAILURE;
        }

        $this->renderTaskStatus($status);

        return $status['successful'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The files given on the command line, keyed by CatalogService's names.
     *
     * @return array<string, string>
     */
    private function files(): array
    {
        $items = $this->argument('items');

        return array_filter([
            'items' => is_string($items) && $items !== '' ? $items : null,
            'variations_file' => $this->stringOption('variations'),
            'item_groups_file' => $this->stringOption('item-groups'),
        ]);
    }

    /**
     * Check the arguments before anything is sent, so a typo can't start an
     * upload with the wrong settings.
     *
     * @param  array<string, string>  $files
     */
    private function validationProblem(string $operation, array $files, int|false|null $timeout): ?string
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            return "Unknown --operation '{$operation}'. Use create_or_replace or patch. "
                .'(Constructor has no delete upload: remove records with Constructor::catalog()->deleteItems().)';
        }

        if ($files === []) {
            return 'Pass an items file, --variations or --item-groups.';
        }

        foreach ($files as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                return "Can't read {$path}.";
            }
        }

        $patch = $operation === CatalogService::OPERATION_PATCH;
        $onMissing = $this->stringOption('on-missing');
        $mode = $this->stringOption('mode');

        return match (true) {
            $this->option('patch-delta') && ! $patch => '--patch-delta only works with --operation=patch.',
            $onMissing !== null && ! $this->option('patch-delta') => '--on-missing only works with --patch-delta.',
            $onMissing !== null && ! in_array(strtoupper($onMissing), self::ON_MISSING_VALUES, true) => '--on-missing must be FAIL, IGNORE or CREATE.',
            $mode !== null && $patch => '--mode only works with --operation=create_or_replace.',
            $mode !== null && ! in_array($mode, self::MODES, true) => '--mode must be full or ids.',
            $timeout === false => '--timeout must be a whole number of seconds.',
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, mixed>
     */
    private function uploadOptions(array $files, string $section): array
    {
        $emails = array_values(array_filter((array) $this->option('email'), fn ($email) => is_string($email) && $email !== ''));

        return array_filter([
            'variations_file' => $files['variations_file'] ?? null,
            'item_groups_file' => $files['item_groups_file'] ?? null,
            'section' => $section,
            'patch_delta' => $this->option('patch-delta') ?: null,
            'on_missing' => $this->stringOption('on-missing'),
            'mode' => $this->stringOption('mode'),
            'notification_email' => $emails ?: null,
            // Constructor's `force` flag. Named differently here, because
            // --force is Laravel's "skip the confirmation" option.
            'force' => $this->option('allow-large-removal') ?: null,
        ], fn ($value) => $value !== null);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
