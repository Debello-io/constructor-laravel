<?php

namespace ConstructorIO\Laravel\Console;

use ConstructorIO\Laravel\Console\Concerns\RendersTaskStatus;
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use ConstructorIO\Laravel\Exceptions\InvalidCatalogDataException;
use ConstructorIO\Laravel\Exceptions\TaskTimeoutException;
use ConstructorIO\Laravel\Services\CatalogService;
use Illuminate\Console\Command;

/**
 * Show the status of a catalog task (an upload or an item write), and
 * optionally wait for it to finish.
 *
 *     php artisan constructor:task 12345 --wait
 *
 * Exits with 1 if the task failed or was canceled, or if --wait timed out.
 *
 * @see https://docs.constructor.com/reference/v1-tasks-retrieve-task
 */
class TaskCommand extends Command
{
    use RendersTaskStatus;

    protected $signature = 'constructor:task
        {id : The task ID returned by an upload or an item write}
        {--wait : Wait until the task has finished}
        {--timeout= : Seconds to wait with --wait (default: CONSTRUCTOR_TASK_TIMEOUT)}';

    protected $description = 'Show the status of a Constructor catalog task';

    public function handle(CatalogService $catalog): int
    {
        $id = $this->argument('id');
        $taskId = is_string($id) ? $id : '';

        $timeout = $this->timeoutOption();
        if ($timeout === false) {
            $this->components->error('--timeout must be a whole number of seconds.');

            return self::INVALID;
        }

        try {
            $status = $this->option('wait')
                ? $catalog->waitForTaskCompletion($taskId, $timeout)
                : $catalog->getTaskStatus($taskId);
        } catch (InvalidCatalogDataException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        } catch (TaskTimeoutException $e) {
            $this->components->warn($e->getMessage());
            $this->line("Check again later: php artisan constructor:task {$taskId} --wait");

            return self::FAILURE;
        } catch (ConstructorException $e) {
            $this->renderConstructorError($e);

            return self::FAILURE;
        }

        $this->renderTaskStatus($status);

        // A task that is still running isn't a failure: there's just nothing to report yet.
        return $status['completed'] && ! $status['successful'] ? self::FAILURE : self::SUCCESS;
    }
}
