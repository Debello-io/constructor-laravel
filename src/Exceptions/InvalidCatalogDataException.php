<?php

namespace ConstructorIO\Laravel\Exceptions;

/**
 * Catalog data was rejected before it was sent: a missing item ID or name,
 * a missing upload file, or an unknown upload operation.
 *
 * Checking these locally gives a clear message instead of a 400 from the API.
 */
class InvalidCatalogDataException extends ConstructorException {}
