<?php

namespace ConstructorIO\Laravel\Console\Concerns;

use ConstructorIO\Laravel\Exceptions\ApiException;
use ConstructorIO\Laravel\Exceptions\ConstructorException;
use Illuminate\Console\Command;

/**
 * Shared output for the catalog commands: a task's status, and errors.
 *
 * @mixin Command
 */
trait RendersTaskStatus
{
    /** How many ingestion warnings to list before summarising the rest. */
    private const WARNINGS_SHOWN = 10;

    /**
     * Print a task as returned by CatalogService::getTaskStatus().
     *
     * @param  array<string, mixed>  $status
     *
     * @see https://docs.constructor.com/reference/v1-tasks-retrieve-task
     */
    protected function renderTaskStatus(array $status): void
    {
        $this->newLine();

        $details = [
            'Task' => $status['id'] ?? null,
            'Type' => $status['type'] ?? null,
            'Status' => $this->colourStatus((string) ($status['status'] ?? 'UNKNOWN')),
            'File' => $status['filename'] ?? null,
            'Submitted' => $status['submission_time'] ?? null,
            'Started' => $status['start_time'] ?? null,
            'Last update' => $status['last_update'] ?? null,
            'Searchable' => match ($status['indexed'] ?? null) {
                true => 'yes',
                false => 'not yet (the index is being rebuilt)',
                default => null,
            },
        ];

        foreach ($details as $label => $value) {
            if ($value !== null && $value !== '') {
                $this->components->twoColumnDetail($label, (string) $value);
            }
        }

        $this->renderChangelog($status['result']['changelog'] ?? null);
        $this->renderWarnings($status['warnings'] ?? []);

        if (($status['error'] ?? null) !== null) {
            $error = is_string($status['error'])
                ? $status['error']
                : (string) json_encode($status['error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $this->components->error('Constructor reported an error:');
            $this->line($error);
        }
    }

    /**
     * Report a failed Constructor call. Messages never contain tokens or
     * response bodies; with -v the body is shown too, because it usually
     * explains what was wrong with an upload (it never contains credentials).
     */
    protected function renderConstructorError(ConstructorException $e): void
    {
        $this->components->error($e->getMessage());

        if ($e instanceof ApiException && $this->output->isVerbose() && $e->getResponseBody() !== '') {
            $this->line('Response from Constructor:');
            $this->line($e->getResponseBody());
        }
    }

    /**
     * Parse --timeout: a whole number of seconds, or null for the configured default.
     *
     * @return int|false|null False if the value isn't a non-negative integer.
     */
    protected function timeoutOption(): int|false|null
    {
        $timeout = $this->option('timeout');

        if ($timeout === null) {
            return null;
        }

        return is_string($timeout) && ctype_digit($timeout) ? (int) $timeout : false;
    }

    /**
     * The changelog counts what the task changed, e.g. per section
     * {"Products": {"items_updated": 10, "items_deleted": 2}}.
     */
    private function renderChangelog(mixed $changelog): void
    {
        if (! is_array($changelog)) {
            return;
        }

        foreach ($changelog as $name => $value) {
            if ($name === 'sections' && is_array($value)) {
                foreach ($value as $section => $counts) {
                    $this->components->twoColumnDetail("Changes in {$section}", $this->formatCounts($counts));
                }
            } elseif (is_scalar($value)) {
                $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst((string) $name)), (string) $value);
            }
        }
    }

    private function formatCounts(mixed $counts): string
    {
        if (! is_array($counts)) {
            return is_scalar($counts) ? (string) $counts : '';
        }

        $parts = [];
        foreach ($counts as $name => $count) {
            if (is_scalar($count)) {
                $parts[] = str_replace('_', ' ', (string) $name).': '.$count;
            }
        }

        return implode(', ', $parts);
    }

    private function renderWarnings(mixed $warnings): void
    {
        if (! is_array($warnings) || $warnings === []) {
            return;
        }

        $this->components->warn(count($warnings).' ingestion '.(count($warnings) === 1 ? 'warning' : 'warnings').':');

        foreach (array_slice($warnings, 0, self::WARNINGS_SHOWN) as $warning) {
            if (is_array($warning)) {
                $code = is_scalar($warning['code'] ?? null) ? (string) $warning['code'] : 'WARNING';
                $message = is_scalar($warning['message'] ?? null) ? (string) $warning['message'] : '';
                $this->line("  {$code}: {$message}");
            }
        }

        if (count($warnings) > self::WARNINGS_SHOWN) {
            $this->line('  ... and '.(count($warnings) - self::WARNINGS_SHOWN).' more.');
        }
    }

    private function colourStatus(string $status): string
    {
        $colour = match ($status) {
            'DONE' => 'green',
            'FAILED', 'CANCELED' => 'red',
            default => 'yellow',
        };

        return "<fg={$colour};options=bold>{$status}</>";
    }
}
