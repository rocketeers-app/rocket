<?php

namespace App\Exceptions;

/** A team named with --team or saved as the default that the token does not reach. */
class UnknownTeamException extends StepException
{
    public function __construct(public readonly string $identifier)
    {
        parent::__construct("Team `{$identifier}` is not one of your teams. Run `rocket team` to pick one.");
    }
}
