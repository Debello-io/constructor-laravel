<?php

namespace ConstructorIO\Laravel\Exceptions;

/**
 * A method was called with an argument Constructor would reject, such as too
 * many item IDs. Thrown before any request is sent.
 *
 * Extends ConstructorException so `catch (ConstructorException $e)` covers it.
 */
class InvalidArgumentException extends ConstructorException {}
