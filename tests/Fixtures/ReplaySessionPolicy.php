<?php

namespace Packstub\SessionReplay\Tests\Fixtures;

use Packstub\SessionReplay\Models\ReplaySession;
use Packstub\SessionReplay\Tests\Fixtures\Models\User;

class ReplaySessionPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_staff;
    }

    public function view(User $user, ReplaySession $session): bool
    {
        return (bool) $user->is_staff;
    }

    public function delete(User $user, ReplaySession $session): bool
    {
        return false;
    }
}
