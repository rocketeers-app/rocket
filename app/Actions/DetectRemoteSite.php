<?php

namespace App\Actions;

use App\Support\RemoteSiteInfo;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Bundles what used to be up to four separate SSH round trips
 * (IsWordPress, IsBedrock, GetRepositoryName, GetCurrentBranch and/or
 * GetRepositoryUrl) into a single connection.
 *
 * $site/$server are expected to already be validated by
 * App\Commands\Concerns\ValidatesSiteArguments before reaching here.
 */
class DetectRemoteSite
{
    use AsAction;

    public function handle($site, $server = null): RemoteSiteInfo
    {
        $path = "/var/www/{$site}/current";

        $lines = [
            'echo "IS_WORDPRESS=$(test -f '.$path.'/wp-config.php -o -f '.$path.'/public/wp-config.php -o -f '.$path.'/config/application.php && echo yes || echo no)"',
            'echo "IS_BEDROCK=$(test -f '.$path.'/config/application.php && echo yes || echo no)"',
            'echo "REPO_URL=$(sudo git --work-tree='.$path.' --git-dir='.$path.'/.git config --get remote.origin.url 2>/dev/null)"',
            'echo "BRANCH=$(sudo git --work-tree='.$path.' --git-dir='.$path.'/.git rev-parse --abbrev-ref HEAD 2>/dev/null)"',
        ];

        $output = (new CreateSshConnection)($server ?? $site)->execute($lines)->getOutput();

        $repositoryUrl = $this->extract($output, 'REPO_URL');

        return new RemoteSiteInfo(
            isWordPress: $this->extract($output, 'IS_WORDPRESS') === 'yes',
            isBedrock: $this->extract($output, 'IS_BEDROCK') === 'yes',
            repositoryUrl: $repositoryUrl,
            repositoryName: $this->deriveRepositoryName($repositoryUrl, $site),
            branch: $this->extract($output, 'BRANCH'),
        );
    }

    protected function extract(string $output, string $key): string
    {
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $output, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * The repository name when a remote origin URL was found, otherwise a
     * best guess from the site alias itself (stripping a trailing
     * "-something" suffix, e.g. "example-app-prod" -> "example-app").
     */
    protected function deriveRepositoryName(string $repositoryUrl, string $site): string
    {
        if ($repositoryUrl === '') {
            return preg_replace('/-[a-z]+$/', '', $site);
        }

        return str_replace('.git', '', last(explode('/', $repositoryUrl)));
    }
}
