<?php

namespace ConstructorIO\Laravel\Services;

/*
 * 1.x name, kept so existing code keeps working.
 *
 * @deprecated 2.0 Use \ConstructorIO\Laravel\Services\AgentService. Will be removed in 3.0. See UPGRADE.md.
 */
class_alias(AgentService::class, ConstructorAgentService::class);

if (false) {
    /** @deprecated 2.0 Use \ConstructorIO\Laravel\Services\AgentService. */
    class ConstructorAgentService extends AgentService {}
}
