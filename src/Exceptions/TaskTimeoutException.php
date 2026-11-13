<?php

namespace ConstructorIO\Laravel\Exceptions;

/**
 * A catalog task (upload, item update) did not finish in the time allowed.
 *
 * The task keeps running at Constructor. Check it again later with
 * CatalogService::getTaskStatus(), or raise CONSTRUCTOR_TASK_TIMEOUT.
 */
class TaskTimeoutException extends ConstructorException {}
