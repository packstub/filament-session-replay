<?php

namespace Packstub\SessionReplay\Tests\Fixtures;

use Filament\Panel;
use Packstub\FilamentCalls\FilamentCallsPlugin;

/** The fixture panel with Filament Calls on it when the package is installed: its widget beside the stage, its routes for the stage's client. */
class AssistPanelProvider extends AdminPanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = parent::panel($panel);

        if (class_exists(FilamentCallsPlugin::class)) {
            $panel->plugin(FilamentCallsPlugin::make());
        }

        return $panel;
    }
}
