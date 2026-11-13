<?php

namespace ConstructorIO\Laravel\Collections;

/*
 * 1.x name, kept so existing code keeps working.
 *
 * @deprecated 2.0 Use \ConstructorIO\Laravel\Scout\SearchResultCollection. Will be removed in 3.0. See UPGRADE.md.
 */
class_alias(\ConstructorIO\Laravel\Scout\SearchResultCollection::class, SearchResultCollection::class);

if (false) {
    /** @deprecated 2.0 Use \ConstructorIO\Laravel\Scout\SearchResultCollection. */
    class SearchResultCollection extends \ConstructorIO\Laravel\Scout\SearchResultCollection {}
}
