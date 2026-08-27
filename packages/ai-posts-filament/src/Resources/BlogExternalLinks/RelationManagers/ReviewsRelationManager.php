<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Resources\BlogExternalLinks\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'reviews';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Safety Reviews');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('is_harmful')
                    ->boolean()
                    ->trueIcon(Heroicon::XMark)
                    ->falseIcon(Heroicon::Check)
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->label(__('Harmful')),

                TextColumn::make('reason')
                    ->limit(80)
                    ->searchable()
                    ->label(__('Reason')),

                TextColumn::make('reviewed_by')
                    ->label(__('Reviewed By')),

                TextColumn::make('reviewed_at')
                    ->dateTime()
                    ->sortable()
                    ->label(__('Reviewed At')),
            ])
            ->defaultSort('reviewed_at', 'desc');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }
}
