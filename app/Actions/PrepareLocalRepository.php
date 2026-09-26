<?php

namespace App\Actions;

use App\Exceptions\StepException;
use App\Support\CommandLog;
use App\Support\ProcessError;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Process\Process;

/**
 * Clones a repository into a local directory, or brings an existing clone to the branch (the remote's default
 * branch when there is none): stash when asked, fetch, check out the branch, fast-forward it to origin.
 */
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

        $this->git($directory, ['fetch', 'origin', '--prune']);

        $branch ??= $this->defaultBranch($directory);

        if (! $this->succeeds($directory, ['rev-parse', '--verify', '--quiet', "refs/remotes/origin/{$branch}"])) {
            throw new StepException("The branch {$branch} is not on origin of {$directory}.");
        }

        $this->git($directory, $this->succeeds($directory, ['rev-parse', '--verify', '--quiet', "refs/heads/{$branch}"])
            ? ['checkout', $branch]
            : ['checkout', '-b', $branch, '--track', "origin/{$branch}"]);

        if ($this->currentBranch($directory) !== $branch) {
            throw new StepException("Could not check out {$branch} in {$directory}.");
        }

        $this->git($directory, ['merge', '--ff-only', "origin/{$branch}"]);

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

    public function currentBranch(string $directory): ?string
    {
        if (! $this->isCloned($directory)) {
            return null;
        }

        return trim($this->git($directory, ['rev-parse', '--abbrev-ref', 'HEAD'])) ?: null;
    }

    private function defaultBranch(string $directory): string
    {
        if (! $this->succeeds($directory, ['symbolic-ref', '--quiet', 'refs/remotes/origin/HEAD'])) {
            $this->git($directory, ['remote', 'set-head', 'origin', '--auto']);
        }

        return Str::after(trim($this->git($directory, ['symbolic-ref', '--short', 'refs/remotes/origin/HEAD'])), 'origin/');
    }

    private function succeeds(string $directory, array $arguments): bool
    {
        return app(CommandLog::class)->run(new Process(['git', ...$arguments], $directory))->isSuccessful();
    }

    private function git(string $directory, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $directory);
        $process->setTimeout(300);
        app(CommandLog::class)->run($process);

        if (! $process->isSuccessful()) {
            throw new StepException('git '.implode(' ', $arguments).' failed: '.ProcessError::message($process));
        }

        return $process->getOutput();
    }
}
