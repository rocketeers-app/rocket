<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;

/** Reads the origin of an environment's current release over SSH, as the environment's own user, for when the API knows no repository. */
class GetRemoteRepositoryUrl
{
    use AsAction;

    public function handle(string $host, string $slug, ?string $directory = null): string
    {
        $directory ??= "/home/{$slug}/webroot";

        $process = (new CreateSshConnection)($host, $slug)
            ->execute('git -C '.escapeshellarg("{$directory}/current").' config --get remote.origin.url');

        $url = trim($process->getOutput());

        if ($url === '') {
            throw new StepException("{$slug} has no repository in Rocketeers, and {$directory}/current on {$host} has no git origin.");
        }

        return $url;
    }
}
