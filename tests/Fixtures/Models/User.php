<?php

namespace Packstub\SessionReplay\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Packstub\SessionReplay\Concerns\HasSessionReplays;

class User extends Authenticatable implements FilamentUser
{
    use HasSessionReplays;

    protected $guarded = [];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
