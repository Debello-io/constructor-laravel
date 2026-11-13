<?php

namespace ConstructorIO\Laravel\Search;

/*
 * 1.x name, kept so existing code keeps working.
 *
 * @deprecated 2.0 Use \ConstructorIO\Laravel\Scout\ConstructorEngine. Will be removed in 3.0. See UPGRADE.md.
 */
class_alias(\ConstructorIO\Laravel\Scout\ConstructorEngine::class, ConstructorEngine::class);

if (false) {
    /** @deprecated 2.0 Use \ConstructorIO\Laravel\Scout\ConstructorEngine. */
    class ConstructorEngine extends \ConstructorIO\Laravel\Scout\ConstructorEngine {}
}
