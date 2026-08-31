<?php

namespace App\Commands;

use App\Actions\GetCurrentSshConfig;
use App\Support\LocalSitePath;
use Illuminate\Console\Command;

class ListSites extends Command
{
    protected $signature = 'sites';

    protected $description = 'List sites known from ssh:config and whether each is installed locally';

    public function handle()
    {
        $sites = $this->parseSites((new GetCurrentSshConfig)());

        if (empty($sites)) {
            $this->warn('No sites found. Run `rocket ssh:config` first.');

            return self::SUCCESS;
        }

        $rows = collect($sites)
            ->map(fn ($server, $site) => [
                $site,
                $server ?? '-',
                is_dir(LocalSitePath::for($site)) ? '<fg=green>yes</>' : 'no',
            ])
            ->values()
            ->all();

        $this->table(['Site', 'Server', 'Installed locally'], $rows);

        return self::SUCCESS;
    }

    /**
     * Reads the "Host <site>" / "HostName <server>" pairs out of the
     * "### ROCKETEERS APP ###"-delimited block that ssh:config maintains in
     * ~/.ssh/config. Returns [site => server-or-null].
     */
    protected function parseSites(string $config): array
    {
        $delimiter = '### ROCKETEERS APP ###';

        if (! preg_match('/'.preg_quote($delimiter, '/').'(.*)'.preg_quote($delimiter, '/').'/ims', $config, $matches)) {
            return [];
        }

        $sites = [];
        $currentSite = null;

        foreach (preg_split('/\R/', $matches[1]) as $line) {
            $line = trim($line);

            if (preg_match('/^Host\s+(\S+)/i', $line, $m)) {
                $currentSite = $m[1];
                $sites[$currentSite] = null;
            } elseif ($currentSite !== null && preg_match('/^HostName\s+(\S+)/i', $line, $m)) {
                $sites[$currentSite] = $m[1];
            }
        }

        return $sites;
    }
}
