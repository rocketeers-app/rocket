<?php

namespace App\Commands\Api;

class Backups extends BaseApiCommand
{
    protected string $resource = 'backups';

    /**
     * Backups come grouped per environment with nested database/file backup
     * arrays, so flatten each group into one row per backup file.
     */
    protected function renderList(array $items, array $columns, string $emptyLabel = 'backups'): void
    {
        $rows = [];

        foreach ($items as $group) {
            $environment = $group['environment']['name'] ?? '-';

            if($environment !== '-'){
                $environment .= ' ('. $group['environment']['environment'] . ')';
            }

            foreach ($group['databaseBackups'] ?? [] as $backup) {
                $rows[] = [$environment, 'database', $backup['filename'] ?? '-', $backup['filesize'] ?? '-', $backup['created_at'] ?? '-'];
            }

            foreach ($group['fileBackups'] ?? [] as $backup) {
                $rows[] = [$environment, 'files', $backup['filename'] ?? '-', $backup['filesize'] ?? '-', $backup['created_at'] ?? '-'];
            }
        }

        if (empty($rows)) {
            $this->newLine();
            $this->warn('No backups found.');

            return;
        }

        $this->newLine();
        $this->table(['Environment', 'Type', 'Filename', 'Size', 'Created'], $rows);
    }
}
