<?php

use App\Actions\SummarizeEnvChanges;

it('names the keys an edit added, changed and removed', function (): void {
    $changes = (new SummarizeEnvChanges)->handle("APP_NAME=Acme\nDB_PASSWORD=old\nLEGACY=1", "# note\nAPP_NAME=Acme\nDB_PASSWORD=\"new\"\nMAIL_FROM=hi@acme.test");

    expect($changes)->toBe(['added' => ['MAIL_FROM'], 'changed' => ['DB_PASSWORD'], 'removed' => ['LEGACY']]);
});

it('treats a quoting change that keeps the value as no change', function (): void {
    expect((new SummarizeEnvChanges)->handle('APP_NAME=Acme', 'APP_NAME="Acme"'))->toBe(['added' => [], 'changed' => [], 'removed' => []]);
});

it('still names the keys when the edited file is not valid dotenv', function (): void {
    $changes = (new SummarizeEnvChanges)->handle("APP_NAME=Acme\nDB_PASSWORD=old", "APP_NAME=Acme\nDB_PASSWORD=\"unterminated\nNEW=1");

    expect($changes)->toBe(['added' => ['NEW'], 'changed' => ['DB_PASSWORD'], 'removed' => []]);
});
