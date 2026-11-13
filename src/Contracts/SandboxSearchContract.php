<?php

namespace ConstructorIO\Laravel\Contracts;

/*
 * 1.x name, kept so existing code keeps working.
 *
 * @deprecated 2.0 Use \ConstructorIO\Laravel\Contracts\SearchProvider. Will be removed in 3.0.
 */
class_alias(SearchProvider::class, SandboxSearchContract::class);

if (false) {
    /** @deprecated 2.0 Use \ConstructorIO\Laravel\Contracts\SearchProvider. */
    interface SandboxSearchContract extends SearchProvider {}
}
