<?php

declare(strict_types=1);

namespace Mulla\Laravel;

final class Version
{
    public const PACKAGE = '0.0.1'; // x-release-please-version
    public const USER_AGENT = 'mulla-laravel/' . self::PACKAGE;

    private function __construct() {}
}
