<?php

namespace App\Support;

use App\Exceptions\StepException;

/**
 * Files that hold secrets for as long as a command runs: in a directory only you can read (0700, files 0600), gone
 * again on delete() and, should the command die first, when PHP shuts down.
 */
class PrivateScratchFiles
{
    private ?string $directory = null;

    public function __construct()
    {
        register_shutdown_function(fn () => $this->delete());
    }

    public function write(string $name, string $contents): string
    {
        $path = $this->directory().'/'.basename($name);

        if (! touch($path) || ! chmod($path, 0600) || file_put_contents($path, $contents) === false) {
            throw new StepException("Could not write {$path}.");
        }

        return $path;
    }

    public function delete(): void
    {
        if ($this->directory === null) {
            return;
        }

        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $file) {
            @unlink($this->directory.'/'.$file);
        }

        @rmdir($this->directory);
        $this->directory = null;
    }

    private function directory(): string
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        $directory = rtrim(sys_get_temp_dir(), '/').'/rocket-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700) || ! chmod($directory, 0700)) {
            throw new StepException("Could not create a private directory in {$directory}.");
        }

        return $this->directory = $directory;
    }
}
