# Excel Import Feature - استيراد ملفات Excel

## Overview - نظرة عامة

تم تطوير ميزة استيراد ملفات Excel لاستيراد البيانات من ملفات Excel متعددة الأوراق إلى قاعدة البيانات. هذه الميزة تدعم استيراد البيانات التالية:

- الطابعات (Printers)
- الفئات (Categories)  
- المواد الخام (Raw Materials)
- المنتجات المصنعة (Production Products)
- المنتجات الاستهلاكية (Consumables)
- مكونات المنتجات (Product Components/Recipes)

## File Structure - هيكل الملف

يجب أن يحتوي ملف Excel على الأوراق التالية بالأسماء المحددة:

### 1. Printer Sheet
- **Columns**: `printer_name`, `ipAddr`
- **Description**: معلومات الطابعات والعناوين IP الخاصة بها

### 2. Categories Sheet  
- **Columns**: `category`
- **Description**: أسماء فئات المنتجات

### 3. Raw Sheet
- **Columns**: `product_name`, `cost`, `unit`, `category`
- **Description**: المواد الخام مع التكلفة والوحدة والفئة

### 4. Production Sheet
- **Columns**: `product_name`, `price`, `category`, `printer`
- **Description**: المنتجات المصنعة مع السعر والفئة والطابعة

### 5. Consumables Sheet
- **Columns**: `product_name`, `cost`, `price`, `unit`, `category`, `printer`
- **Description**: المنتجات الاستهلاكية مع جميع المعلومات

### 6. Standard Sheet
- **Columns**: `final_product_name`, `component_name`, `quantity`
- **Description**: وصفات المنتجات (المكونات المطلوبة لكل منتج)

## How to Use - كيفية الاستخدام

1. **Access the Import Page**: اذهب إلى "رفع ملف Excel" في لوحة التحكم
2. **Upload File**: اختر ملف Excel (.xlsx) من جهازك
3. **Analyze**: اضغط على "تحليل الملف" لمراجعة البيانات
4. **Import**: اضغط على "استيراد البيانات" لبدء عملية الاستيراد

## Processing Order - ترتيب المعالجة

يتم معالجة الأوراق بالترتيب التالي لضمان استيفاء التبعيات:

1. **Printer** - الطابعات أولاً
2. **Categories** - الفئات ثانياً  
3. **Raw** - المواد الخام ثالثاً
4. **Production** - المنتجات المصنعة رابعاً
5. **Consumables** - المنتجات الاستهلاكية خامساً
6. **Standard** - مكونات المنتجات أخيراً

## Data Validation - التحقق من البيانات

- **Required Fields**: بعض الحقول مطلوبة (مثل أسماء المنتجات)
- **Data Types**: يتم التحقق من أنواع البيانات (أرقام للأسعار والكميات)
- **Relationships**: يتم التحقق من وجود العلاقات (مثل الفئات والطابعات)
- **Duplicates**: يتم التعامل مع التكرارات بالتحديث أو الإنشاء

## Error Handling - معالجة الأخطاء

- يتم عرض الأخطاء لكل ورقة منفصلة
- يتم تجاهل الصفوف الفارغة
- يتم المتابعة حتى لو فشلت بعض الصفوف
- يتم عرض ملخص شامل للنتائج

## Technical Details - التفاصيل التقنية

### Service Classes
- `ExcelImportService`: الخدمة الأساسية لمعالجة ملفات Excel
- `SpecificDataImportService`: خدمة متخصصة للبيانات متعددة الأوراق

### Models Used
- `Product`: المنتجات
- `Category`: الفئات
- `Printer`: الطابعات  
- `ProductComponent`: مكونات المنتجات

### Package Used
- `maatwebsite/excel`: لمعالجة ملفات Excel
- `phpoffice/phpspreadsheet`: المحرك الأساسي

## File Size Limits - حدود حجم الملف

- الحد الأقصى: 10 ميجابايت
- الصيغة المدعومة: .xlsx فقط

## Security Features - ميزات الأمان

- التحقق من نوع الملف
- تنظيف البيانات المدخلة
- استخدام المعاملات (Transactions) لضمان سلامة البيانات
- التحقق من الصلاحيات

## Troubleshooting - حل المشاكل

### Common Issues:
1. **ملف غير مدعوم**: تأكد من أن الملف بصيغة .xlsx
2. **أسماء أوراق خاطئة**: تأكد من أسماء الأوراق تطابق المطلوب
3. **بيانات ناقصة**: تأكد من وجود البيانات المطلوبة في كل ورقة
4. **أخطاء العلاقات**: تأكد من وجود الفئات والطابعات قبل المنتجات
