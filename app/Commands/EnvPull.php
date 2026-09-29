<?php

namespace App\Commands;

use App\Actions\ConfigureDotEnvLocally;
use App\Actions\ConfigureWpConfigLocally;
use App\Actions\FindConnectedServer;
use App\Actions\FindEnvironmentAcrossTeams;
use App\Actions\NotifyLocally;
use App\Actions\PutEnvLocally;
use App\Actions\PutWpConfigLocally;
use App\Actions\ReadRemoteEnvFile;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\ResolvesTeam;
use App\Commands\Concerns\WithSteps;
use Illuminate\Console\Command;

/** Pulls an environment's .env (or wp-config.php) from its first connected server into the current directory, found by slug across every team you are in. */
class EnvPull extends Command
{
    use OutputsJson;
    use ResolvesTeam;
    use WithSteps;

    protected $signature = 'env:pull
        {environment? : The environment slug; asked for when left out}
        {--team= : Only look in this team}';

    protected $description = 'Pull the env file of an environment from its server';

    public function handle(): int
    {
        $this->ensureToken();

        ['team' => $team, 'environment' => $environment] = (new FindEnvironmentAcrossTeams)($this->argument('environment'), $this->canPrompt(), $this->teamFilter());
        $site = (string) $environment['slug'];

        $server = $this->step('Finding a connected server', fn (): string => app(FindConnectedServer::class)->handle($team, $environment));
        $file = $this->step('Fetching the remote env file', fn (): array => app(ReadRemoteEnvFile::class)->handle($server, $site, $environment['directory_path'] ?? null));

        $directory = (string) getcwd();
        $local = basename($directory);

        $path = $this->step('Saving it in '.$directory, fn (): string => $file['wordpress']
            ? app(PutWpConfigLocally::class)->handle(app(ConfigureWpConfigLocally::class)->handle($file['contents'], $local), $local, $directory)
            : app(PutEnvLocally::class)->handle(app(ConfigureDotEnvLocally::class)->handle($file['contents'], $local), $local, $directory));

        if ($this->wantsJson()) {
            return $this->emitJson(['team' => $team['slug'], 'environment' => $site, 'server' => $server, 'wordpress' => $file['wordpress'], 'path' => $path]);
        }

        (new NotifyLocally)("Env of {$site} pulled from {$server} into {$path}", $this);

        return self::SUCCESS;
    }
}
