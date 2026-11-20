<?php

namespace App\Filament\Components\Forms;

use App\Models\Product;
use App\Modules\Module;
use App\Services\FeatureService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Builder;

/**
 * KDS routing fields for products. Products override the category values,
 * so show_on_kds is tri-state: inherit (حسب الفئة), show or hide.
 * The select keeps string states ('', '1', '0') because select option
 * values round-trip through JavaScript as strings; they are mapped back
 * to the nullable boolean column on dehydration.
 */
class KitchenDisplayFields
{
    /**
     * @return array<int, mixed>
     */
    public static function components(): array
    {
        return [
            Section::make('شاشة المطبخ')
                ->visible(fn (): bool => app(FeatureService::class)->moduleEnabled(Module::RESTAURANT))
                ->description('توجيه هذا المنتج داخل شاشة المطبخ، ويتجاوز إعدادات الفئة')
                ->icon('heroicon-m-tv')
                ->columns(3)
                ->schema([
                    Select::make('kitchen_station_id')
                        ->label('محطة المطبخ')
                        ->relationship(
                            'kitchenStation',
                            'name',
                            fn (Builder $query): Builder => $query->where('is_active', true)
                        )
                        ->placeholder('حسب الفئة')
                        ->searchable()
                        ->nullable(),

                    Select::make('show_on_kds')
                        ->label('الظهور في شاشة المطبخ')
                        ->helperText('عند اختيار «حسب الفئة» تُقرأ القيمة من فئة المنتج')
                        ->options([
                            null => 'حسب الفئة',
                            1 => 'يظهر',
                            0 => 'لا يظهر',
                        ])
                        ->nullable()
                        ->afterStateHydrated(function (Select $component, ?Product $record): void {
                            // Map the nullable boolean column onto the
                            // string state used by the select.
                            $component->state(match ($record?->show_on_kds) {
                                true => '1',
                                false => '0',
                                default => '',
                            });
                        })
                        ->dehydrateStateUsing(fn (mixed $state): ?bool => match ($state) {
                            '1', 1, true, 'true' => true,
                            '0', 0, false, 'false' => false,
                            default => null,
                        }),

                    TextInput::make('prep_minutes')
                        ->label('وقت التحضير')
                        ->numeric()
                        ->minValue(1)
                        ->suffix('دقيقة')
                        ->helperText('يترك فارغاً لاستخدام وقت الفئة')
                        ->nullable(),
                ]),
        ];
    }
}
