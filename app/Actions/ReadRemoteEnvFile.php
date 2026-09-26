<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;

/** Reads an environment's env file over SSH as the environment's own user (its slug), without sudo: ~/webroot/persistent/.env, else the WordPress config of the current release. */
class ReadRemoteEnvFile
{
    use AsAction;

    private const array WORDPRESS_CONFIGS = ['current/wp-config.php', 'current/public/wp-config.php', 'current/config/application.php'];

    /** @return array{contents: string, wordpress: bool, repository: string} */
    public function handle(string $host, string $slug, ?string $directory = null): array
    {
        $directory ??= "/home/{$slug}/webroot";
        $env = $this->read($host, $slug, "{$directory}/persistent/.env");

        if ($env !== null) {
            return ['contents' => $env, 'wordpress' => false, 'repository' => $this->repository($host, $slug, $directory)];
        }

        foreach (self::WORDPRESS_CONFIGS as $config) {
            $contents = $this->read($host, $slug, "{$directory}/{$config}");

            if ($contents !== null) {
                return ['contents' => $contents, 'wordpress' => true, 'repository' => $slug];
            }
        }

        throw new StepException("No env file in {$directory} on {$host} (connected as {$slug}).");
    }

    private function read(string $host, string $slug, string $path): ?string
    {
        $process = (new CreateSshConnection)($host, $slug)->execute('cat '.escapeshellarg($path).' 2>/dev/null');
        $output = $process->getOutput();

        return $process->isSuccessful() && trim($output) !== '' ? $output : null;
    }

    private function repository(string $host, string $slug, string $directory): string
    {
        $process = (new CreateSshConnection)($host, $slug)->execute('git -C '.escapeshellarg("{$directory}/current").' config --get remote.origin.url 2>/dev/null');
        $url = trim($process->getOutput());

        return $url === '' ? (string) preg_replace('/-[a-z]+$/', '', $slug) : str_replace('.git', '', basename($url));
    }
}
