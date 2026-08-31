<?php

namespace App\Support;

/**
 * Everything Rocket needs to know about a remote site before it can install,
 * sync or import it, fetched in one SSH round trip by App\Actions\DetectRemoteSite.
 */
final class RemoteSiteInfo
{
    public function __construct(
        public readonly bool $isWordPress,
        public readonly bool $isBedrock,
        public readonly string $repositoryUrl,
        public readonly string $repositoryName,
        public readonly string $branch,
    ) {}
}
