<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string|max:500',
            'items.*.item_discount' => 'nullable|numeric|min:0',
            'items.*.item_discount_type' => 'nullable|string|in:percent,value',
            'items.*.item_discount_percent' => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'يجب إضافة عناصر للطلب',
            'items.*.product_id.required' => 'معرف المنتج مطلوب',
            'items.*.product_id.exists' => 'المنتج غير موجود',
            'items.*.quantity.required' => 'الكمية مطلوبة',
            'items.*.quantity.numeric' => 'الكمية يجب أن تكون رقم',
            'items.*.quantity.min' => 'الكمية يجب أن تكون أكبر من صفر',
            'items.*.price.required' => 'السعر مطلوب',
            'items.*.price.numeric' => 'السعر يجب أن يكون رقم',
            'items.*.price.min' => 'السعر يجب أن يكون أكبر من أو يساوي صفر',
            'items.*.notes.max' => 'الملاحظات يجب ألا تتجاوز 500 حرف',
            'items.*.item_discount.numeric' => 'الخصم يجب أن يكون رقم',
            'items.*.item_discount.min' => 'الخصم يجب أن يكون أكبر من أو يساوي صفر',
            'items.*.item_discount_type.in' => 'نوع الخصم يجب أن يكون نسبة أو قيمة',
            'items.*.item_discount_percent.numeric' => 'نسبة الخصم يجب أن تكون رقم',
            'items.*.item_discount_percent.min' => 'نسبة الخصم يجب أن تكون أكبر من أو يساوي صفر',
            'items.*.item_discount_percent.max' => 'نسبة الخصم يجب ألا تتجاوز 100',
        ];
    }
}
