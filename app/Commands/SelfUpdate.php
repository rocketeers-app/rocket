<?php

namespace App\Commands;

use App\Actions\InstallRocketVersion;
use App\Actions\LatestRocketVersion;
use App\Commands\Concerns\OutputsJson;
use App\Commands\Concerns\WithSteps;
use App\Support\OutputRenderer;
use Illuminate\Console\Command;
use Phar;

/**
 * Updates Rocket to the newest version on GitHub. Once the running PHAR is replaced, its old contents are gone,
 * so everything printed afterwards is prepared up front and the process exits right away.
 */
class SelfUpdate extends Command
{
    use OutputsJson;
    use WithSteps;

    protected $signature = 'self-update';

    protected $description = 'Update Rocket to the latest version';

    public function handle(): int
    {
        $current = (string) config('app.version');
        $latest = app(LatestRocketVersion::class)->handle();

        if (version_compare($latest, $current, '<=')) {
            if ($this->wantsJson()) {
                return $this->emitJson(['current' => $current, 'latest' => $latest, 'updated' => false]);
            }

            $this->components->info("Rocket {$current} is the latest version.");

            return self::SUCCESS;
        }

        $installer = app(InstallRocketVersion::class);
        $phar = $installer->pharPath();
        $renderer = app(OutputRenderer::class);

        $this->startProgress(2);
        $download = $this->step("Downloading Rocket {$latest}", fn (): string => $installer->download($latest, $phar));
        $this->step("Installing Rocket {$latest}", fn () => $installer->replace($download, $phar));
        $this->finishProgress();

        if ($this->wantsJson()) {
            $renderer->json($this, ['current' => $current, 'latest' => $latest, 'updated' => true, 'path' => $phar]);
        } else {
            $this->newLine();
            $this->line("  <fg=green>✓</> Updated Rocket from {$current} to <fg=cyan>{$latest}</>");
        }

        if (Phar::running(false) === $phar) {
            exit(self::SUCCESS);
        }

        return self::SUCCESS;
    }
}
