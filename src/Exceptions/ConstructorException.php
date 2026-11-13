<?php

namespace ConstructorIO\Laravel\Exceptions;

use RuntimeException;

/**
 * Base class for every exception this package throws.
 *
 * Catch this to handle any Constructor failure in one place:
 *
 *     try {
 *         $answer = Constructor::agent()->askProductQuestion($question, $itemId);
 *     } catch (ConstructorException $e) {
 *         report($e);
 *     }
 */
class ConstructorException extends RuntimeException {}
