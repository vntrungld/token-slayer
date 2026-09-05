<?php

namespace App\Filament\Resources\AiModels\Pages;

use App\Filament\Resources\AiModels\AiModelResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The `AiModel` index page — the only page this resource registers.
 */
class ListAiModels extends ListRecords
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<AiModelResource>
     */
    protected static string $resource = AiModelResource::class;
}
