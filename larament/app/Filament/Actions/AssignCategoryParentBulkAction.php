<?php

namespace App\Filament\Actions;

use App\Models\Category;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignCategoryParentBulkAction extends BulkAction
{
    public static function getDefaultName(): ?string
    {
        return 'assignParent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('تعيين تصنيف رئيسي')
            ->modalHeading('تعيين أو إزالة التصنيف الرئيسي للفئات المحددة')
            ->modalSubmitActionLabel('تطبيق')
            ->icon('heroicon-o-folder-plus')
            ->deselectRecordsAfterCompletion()
            ->schema(fn (AssignCategoryParentBulkAction $action): array => [
                Toggle::make('remove_parent')
                    ->label('إزالة التصنيف الرئيسي (تحويل الفئات إلى رئيسية)')
                    ->helperText('عند التفعيل، سيتم إزالة التصنيف الرئيسي من الفئات المحددة لتصبح فئات رئيسية مستقلة.')
                    ->default(false)
                    ->live(),

                Select::make('parent_id')
                    ->label('التصنيف الرئيسي')
                    ->placeholder('اختر التصنيف الرئيسي')
                    ->options(function () use ($action): array {
                        $selectedIds = [];

                        try {
                            if ($action->canAccessSelectedRecords()) {
                                $selectedIds = collect($action->getSelectedRecords())
                                    ->pluck('id')
                                    ->filter()
                                    ->all();
                            }
                        } catch (\Throwable) {
                            $selectedIds = [];
                        }

                        return Category::query()
                            ->whereNull('parent_id')
                            ->when(! empty($selectedIds), fn (Builder $query) => $query->whereNotIn('id', $selectedIds))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => ! (bool) $get('remove_parent'))
                    ->required(fn (Get $get): bool => ! (bool) $get('remove_parent'))
                    ->rule(function () use ($action): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($action): void {
                            if (blank($value)) {
                                return;
                            }

                            $parent = Category::query()->find($value);
                            if (! $parent instanceof Category) {
                                $fail('التصنيف الرئيسي المحدد غير موجود.');

                                return;
                            }

                            if ($parent->parent_id !== null) {
                                $fail('لا يمكن اختيار تصنيف فرعي كتصنيف رئيسي (الحد الأقصى مستويان فقط).');

                                return;
                            }

                            $selectedRecords = [];
                            try {
                                if ($action->canAccessSelectedRecords()) {
                                    $selectedRecords = $action->getSelectedRecords();
                                }
                            } catch (\Throwable) {
                            }

                            $records = collect($selectedRecords);
                            $selectedIds = $records->pluck('id')->filter()->all();

                            if (in_array((int) $parent->getKey(), array_map('intval', $selectedIds), true)) {
                                $fail('لا يمكن اختيار أحد التصنيفات المحددة كأب لنفسه.');

                                return;
                            }

                            $categoriesWithChildren = $records->filter(function (mixed $category): bool {
                                return $category instanceof Category && $category->children()->exists();
                            });

                            if ($categoriesWithChildren->isNotEmpty()) {
                                $names = $categoriesWithChildren->pluck('name')->join('، ');
                                $fail("لا يمكن تعيين تصنيف رئيسي للفئات التي تحتوي على فئات فرعية ({$names}).");
                            }
                        };
                    }),
            ])
            ->action(function (Collection $records, array $data): void {
                $removeParent = (bool) ($data['remove_parent'] ?? false);

                if ($removeParent) {
                    DB::transaction(function () use ($records): void {
                        foreach ($records as $record) {
                            $record->update([
                                'parent_id' => null,
                            ]);
                        }
                    });

                    Notification::make()
                        ->title('تم إزالة التصنيف الرئيسي بنجاح')
                        ->success()
                        ->send();

                    return;
                }

                if (blank($data['parent_id'] ?? null)) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'يرجى اختيار تصنيف رئيسي أو تفعيل خيار إزالة التصنيف الرئيسي.',
                    ]);
                }

                $parentId = (int) $data['parent_id'];
                $parent = Category::query()->find($parentId);

                if (! $parent instanceof Category) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'التصنيف الرئيسي المحدد غير موجود.',
                    ]);
                }

                if ($parent->parent_id !== null) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'لا يمكن اختيار تصنيف فرعي كتصنيف رئيسي (الحد الأقصى مستويان فقط).',
                    ]);
                }

                $selectedIds = $records->pluck('id')->filter()->all();

                if (in_array($parentId, array_map('intval', $selectedIds), true)) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'لا يمكن اختيار أحد التصنيفات المحددة كأب لنفسه.',
                    ]);
                }

                $records->loadCount('children');
                $categoriesWithChildren = $records->filter(function (mixed $category): bool {
                    return $category instanceof Category && (int) $category->children_count > 0;
                });

                if ($categoriesWithChildren->isNotEmpty()) {
                    $names = $categoriesWithChildren->pluck('name')->join('، ');
                    throw ValidationException::withMessages([
                        'parent_id' => "لا يمكن تعيين تصنيف رئيسي للفئات التي تحتوي على فئات فرعية ({$names}).",
                    ]);
                }

                DB::transaction(function () use ($records, $parent): void {
                    foreach ($records as $record) {
                        $record->update([
                            'parent_id' => $parent->id,
                        ]);
                    }
                });

                Notification::make()
                    ->title('تم تعيين التصنيف الرئيسي بنجاح')
                    ->success()
                    ->send();
            });
    }
}
