<?php

namespace Workbench\App\Filament\Resources\Orders\Pages;

use Filament\Resources\Pages\CreateRecord;
use Workbench\App\Filament\Resources\Orders\OrderResource;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;
}
