<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Tools;

/**
 * A request kept failing in a way that says "try later" (rate limit, server error,
 * no connection), so the answer says nothing about whether the resource exists.
 */
class TransientHttpException extends \RuntimeException
{
}
