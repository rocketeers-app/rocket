<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class ConfigureWpConfigLocally
{
    use AsAction;

    public function handle($config, $name)
    {
        $config = $this->replaceDefine($config, 'DB_NAME', $name);
        $config = $this->replaceDefine($config, 'DB_USER', 'root');
        $config = $this->replaceDefine($config, 'DB_PASSWORD', '');
        $config = $this->replaceDefine($config, 'DB_HOST', '127.0.0.1');
        $config = $this->replaceDefine($config, 'WP_DEBUG', true);

        return $config;
    }

    protected function replaceDefine($config, $key, $value)
    {
        if (is_bool($value)) {
            $replacement = $value ? 'true' : 'false';

            return preg_replace(
                "/define\s*\(\s*['\"]".preg_quote($key, '/')."['\"]\s*,\s*.*?\)/",
                "define('{$key}', {$replacement})",
                $config
            );
        }

        return preg_replace(
            "/define\s*\(\s*['\"]".preg_quote($key, '/')."['\"]\s*,\s*.*?\)/",
            "define('{$key}', '{$value}')",
            $config
        );
    }
}
