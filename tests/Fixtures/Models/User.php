<?php

namespace Packstub\SessionReplay\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Packstub\SessionReplay\Concerns\HasSessionReplays;

class User extends Authenticatable implements FilamentUser
{
    use HasSessionReplays;
    use Notifiable;

    protected $guarded = [];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
