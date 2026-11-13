<?php

namespace ConstructorIO\Laravel\Services\Search;

use ConstructorIO\Laravel\Constructor;

/*
 * 1.x name, kept so existing code keeps working.
 *
 * @deprecated 2.0 Use \ConstructorIO\Laravel\Constructor. Will be removed in 3.0. See UPGRADE.md.
 */
class_alias(Constructor::class, ConstructorSandboxSearch::class);

if (false) {
    /** @deprecated 2.0 Use \ConstructorIO\Laravel\Constructor. */
    class ConstructorSandboxSearch extends Constructor {}
}
