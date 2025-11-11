<?php

namespace App\Filament\Imports;

use App\Models\Customer;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class CustomerImporter extends Importer
{
    protected static ?string $model = Customer::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->label('اسم العميل')
                ->requiredMapping()
                ->rules(['required', 'max:255'])
                ->exampleHeader('اسم العميل')
                ->examples(['أحمد محمد', 'محمد علي', 'فاطمة حسن'])
                ->guess(['الاسم', 'اسم العميل', 'customer name', 'full name']),

            ImportColumn::make('phone')
                ->label('رقم الهاتف')
                ->requiredMapping()
                ->rules(['required', 'max:20', 'unique:customers,phone'])
                ->exampleHeader('رقم الهاتف')
                ->examples(['01234567890', '01098765432', '01155443322'])
                ->guess(['الهاتف', 'رقم الهاتف', 'التليفون', 'mobile', 'telephone']),

            ImportColumn::make('has_whatsapp')
                ->label('لديه واتساب')
                ->boolean()
                ->rules(['nullable', 'boolean'])
                ->exampleHeader('لديه واتساب')
                ->examples(['نعم', 'لا', '1'])
                ->helperText('يمكن استخدام: نعم/لا، 1/0، true/false')
                ->guess(['واتساب', 'whatsapp', 'has whatsapp']),

            ImportColumn::make('address')
                ->label('العنوان')
                ->rules(['max:500'])
                ->exampleHeader('العنوان')
                ->examples([
                    'شارع الجامعة، المعادي، القاهرة',
                    'شارع النصر، مدينة نصر',
                    'شارع التحرير، الدقي'
                ])
                ->guess(['العنوان', 'address', 'location']),

            ImportColumn::make('region')
                ->label('المنطقة')
                ->rules(['max:255'])
                ->exampleHeader('المنطقة')
                ->examples(['المعادي', 'مدينة نصر', 'الدقي'])
                ->guess(['المنطقة', 'region', 'area', 'district']),

            ImportColumn::make('delivery_cost')
                ->label('تكلفة التوصيل')
                ->numeric(decimalPlaces: 2)
                ->rules(['numeric', 'min:0'])
                ->exampleHeader('تكلفة التوصيل (ج.م)')
                ->examples(['15.00', '20.00', '18.50'])
                ->guess(['تكلفة التوصيل', 'التوصيل', 'delivery cost', 'delivery fee']),
        ];
    }

    public function resolveRecord(): ?Customer
    {
        if($this->data['has_whatsapp'] === null){
            $this->data['has_whatsapp'] = 0;
        }
        // Update existing customer if phone number matches, otherwise create new
        if (!empty($this->data['phone'])) {
            return Customer::firstOrNew([
                'phone' => $this->data['phone'],
            ]);
        }

        return new Customer();
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'تم إكمال استيراد العملاء بنجاح وتم استيراد ' . number_format($import->successful_rows) . ' ' . ($import->successful_rows === 1 ? 'عميل' : 'عميل') . '.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' فشل استيراد ' . number_format($failedRowsCount) . ' ' . ($failedRowsCount === 1 ? 'عميل' : 'عميل') . '.';
        }

        return $body;
    }

    public function getValidationMessages(): array
    {
        return [
            'name.required' => 'اسم العميل مطلوب',
            'name.max' => 'اسم العميل يجب ألا يتجاوز 255 حرفاً',
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.max' => 'رقم الهاتف يجب ألا يتجاوز 20 حرفاً',
            'phone.unique' => 'رقم الهاتف مستخدم بالفعل',
            'has_whatsapp.boolean' => 'قيمة واتساب يجب أن تكون نعم/لا',
            'address.max' => 'العنوان يجب ألا يتجاوز 500 حرفاً',
            'region.max' => 'المنطقة يجب ألا يتجاوز 255 حرفاً',
            'delivery_cost.numeric' => 'تكلفة التوصيل يجب أن تكون رقماً',
            'delivery_cost.min' => 'تكلفة التوصيل يجب أن تكون أكبر من أو تساوي صفر',
        ];
    }
}
