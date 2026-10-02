<?php

namespace Packstub\SessionReplay\Filament;

use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\Column;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentSessionReplayServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-session-replay')
            ->hasViews('filament-session-replay');
    }

    /** This provider sits one level below src/; package-tools resolves resources/ relative to what this returns. */
    protected function getPackageBaseDir(): string
    {
        return dirname(__DIR__);
    }

    public function packageBooted(): void
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../../resources/lang');

        $this->registerMaskingMacros();
    }

    /**
     * Privacy declared where the field is: ->maskInReplay() replaces the text
     * with asterisks in recordings, ->blockInReplay() records an empty box of
     * the same size. Inputs and rich editors are masked anyway; this covers what is displayed.
     */
    protected function registerMaskingMacros(): void
    {
        foreach (['maskInReplay' => 'data-replay-mask', 'blockInReplay' => 'data-replay-block'] as $macro => $attribute) {
            // Layout components (Section, Fieldset, Grid…): the attribute on the component itself.
            Component::macro($macro, function (bool $condition = true) use ($attribute) {
                /** @var Component $this */
                return $condition && method_exists($this, 'extraAttributes') ? $this->extraAttributes([$attribute => 'true'], merge: true) : $this;
            });

            // Form fields: the wrapper, so label, hint, helper text and value are all covered.
            Field::macro($macro, function (bool $condition = true) use ($attribute) {
                /** @var Field $this */
                return $condition ? $this->extraFieldWrapperAttributes([$attribute => 'true'], merge: true) : $this;
            });

            Entry::macro($macro, function (bool $condition = true) use ($attribute) {
                /** @var Entry $this */
                return $condition ? $this->extraEntryWrapperAttributes([$attribute => 'true'], merge: true) : $this;
            });

            Column::macro($macro, function (bool $condition = true) use ($attribute) {
                /** @var Column $this */
                return $condition ? $this->extraCellAttributes([$attribute => 'true'], merge: true) : $this;
            });
        }
    }
}
