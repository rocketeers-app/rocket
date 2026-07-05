<?php

namespace App\Commands\Api;

class Delete extends WriteVerbCommand
{
    protected string $verb = 'delete';

    protected bool $destructive = true;
}
