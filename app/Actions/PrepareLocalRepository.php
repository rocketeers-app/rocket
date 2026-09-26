<?php

namespace App\Actions;

use App\Exceptions\StepException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

/** Clones a repository into a local directory, or brings an existing clone to the branch: stash when asked, fetch, checkout, fast-forward pull. */
class PrepareLocalRepository
{
    use AsAction;

    public function handle(string $directory, string $url, ?string $branch, bool $stash = false): string
    {
        if (! $this->isCloned($directory)) {
            if (! is_dir(dirname($directory))) {
                mkdir(dirname($directory), 0755, true);
            }

            app(GitCloneRepository::class)->handle(basename($directory), $url, $directory, $branch);

            return 'cloned';
        }

        if ($stash) {
            $this->git($directory, ['stash', 'push', '-u', '-m', 'rocket install']);
        }

        $this->git($directory, ['fetch']);

        if ($branch !== null) {
            $this->git($directory, ['checkout', $branch]);
        }

        $this->git($directory, ['pull', '--ff-only']);

        return 'updated';
    }

    public function isCloned(string $directory): bool
    {
        return is_dir("{$directory}/.git");
    }

    public function isDirty(string $directory): bool
    {
        return $this->isCloned($directory) && trim($this->git($directory, ['status', '--porcelain'])) !== '';
    }

    private function git(string $directory, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $directory);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new StepException('git '.implode(' ', $arguments).' failed: '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }
}
