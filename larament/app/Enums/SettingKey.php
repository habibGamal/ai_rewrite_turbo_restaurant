<?php

namespace App\Enums;

use App\Costing\CostingMethod;
use App\Modules\BusinessType;
use App\Modules\Module;
use App\Modules\Wording;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

enum SettingKey: string
{
    case WEBSITE_URL = 'website_url';
    case CASHIER_PRINTER_IP = 'cashier_printer_ip';
    case RECEIPT_FOOTER = 'receipt_footer';
    case DINE_IN_SERVICE_CHARGE = 'dine_in_service_charge';
    case RESTAURANT_NAME = 'restaurant_name';
    case RESTAURANT_PRINT_LOGO = 'restaurant_print_logo';
    case RESTAURANT_OFFICIAL_LOGO = 'restaurant_official_logo';
    case RESTAURANT_QR_LOGO = 'restaurant_qr_logo';
    case NODE_TYPE = 'node_type';
    case MASTER_NODE_LINK = 'master_node_link';
    case SCALE_BARCODE_PREFIX = 'scale_barcode_prefix';
    case ALLOW_WEB_ORDERS_SHIFT_TRANSFER = 'allow_web_orders_shift_transfer';
    case CASHIER_TABS = 'cashier_tabs';
    case CASHIER_TABS_ORIENTATION = 'cashier_tabs_orientation';
    case COSTING_METHOD = 'costing_method';
    case KDS_ITEM_TRACKING = 'kds_item_tracking';
    case KDS_DEFAULT_PREP_MINUTES = 'kds_default_prep_minutes';
    case KDS_ORDER_TYPE_TARGETS = 'kds_order_type_targets';
    case KDS_WARNING_PERCENT = 'kds_warning_percent';
    case KDS_AUTO_FINISH_ON_COMPLETE = 'kds_auto_finish_on_complete';
    case KDS_CASHIER_STATUS_ENABLED = 'kds_cashier_status_enabled';
    case KDS_RECALL_MINUTES = 'kds_recall_minutes';
    case KDS_NEW_ORDER_SOUND = 'kds_new_order_sound';
    case KDS_CHANGE_SOUND = 'kds_change_sound';
    case KDS_CANCEL_SOUND = 'kds_cancel_sound';
    case KDS_SOUND_VOLUME = 'kds_sound_volume';
    case KDS_REMINDER_SECONDS = 'kds_reminder_seconds';
    case FEATURE_REVENUES_ENABLED = 'feature_revenues_enabled';
    case FEATURE_CUSTOM_FEES_ENABLED = 'feature_custom_fees_enabled';
    case FEATURE_OUTSOURCED_ORDERS_ENABLED = 'feature_outsourced_orders_enabled';
    case FEATURE_EQUIPMENT_ENABLED = 'feature_equipment_enabled';
    case FEATURE_VARIANTS_ENABLED = 'feature_variants_enabled';
    case FEATURE_MULTI_TERMINAL_ENABLED = 'feature_multi_terminal_enabled';
    case FEATURE_PURCHASE_BARCODES_ENABLED = 'feature_purchase_barcodes_enabled';
    case FEATURE_LABEL_PRINTING_ENABLED = 'feature_label_printing_enabled';
    case FEATURE_DOCUMENT_BARCODES_ENABLED = 'feature_document_barcodes_enabled';
    case PURCHASE_BARCODE_PREFIX = 'purchase_barcode_prefix';
    case PURCHASE_BARCODE_DIGITS = 'purchase_barcode_digits';
    case PURCHASE_BARCODE_NEXT_NUMBER = 'purchase_barcode_next_number';
    case MAX_TERMINALS = 'max_terminals';
    case BUSINESS_TYPE = 'business_type';
    case ENABLED_MODULES = 'enabled_modules';
    case SETUP_COMPLETED = 'setup_completed';
    case RETURNS_MAX_DAYS = 'returns_max_days';
    case WEB_API_KEY = 'web_api_key';
    case WEBSITE_INTEGRATION_TOKEN = 'website_integration_token';

    /**
     * Get default value for this setting
     */
    public function defaultValue(): string
    {
        return match ($this) {
            self::WEBSITE_URL => 'http://127.0.0.1:38794',
            self::CASHIER_PRINTER_IP => '192.168.1.100',
            self::RECEIPT_FOOTER => 'شكراً لزيارتكم، نتطلع لخدمتكم مرة أخرى',
            self::DINE_IN_SERVICE_CHARGE => '0.12',
            self::RESTAURANT_NAME => '-------',
            self::RESTAURANT_PRINT_LOGO => '',
            self::RESTAURANT_OFFICIAL_LOGO => '/images/logo.jpg',
            self::RESTAURANT_QR_LOGO => '',
            self::NODE_TYPE => 'independent',
            self::MASTER_NODE_LINK => 'http://127.0.0.1:38794',
            self::SCALE_BARCODE_PREFIX => '23',
            self::ALLOW_WEB_ORDERS_SHIFT_TRANSFER => 'false',
            self::CASHIER_TABS => self::cashierTabsDefault(),
            self::CASHIER_TABS_ORIENTATION => 'horizontal',
            self::COSTING_METHOD => 'immediate',
            self::KDS_ITEM_TRACKING => 'true',
            self::KDS_DEFAULT_PREP_MINUTES => '10',
            self::KDS_ORDER_TYPE_TARGETS => '{}',
            self::KDS_WARNING_PERCENT => '75',
            self::KDS_AUTO_FINISH_ON_COMPLETE => 'true',
            self::KDS_CASHIER_STATUS_ENABLED => 'true',
            self::KDS_RECALL_MINUTES => '15',
            self::KDS_NEW_ORDER_SOUND => 'chime',
            self::KDS_CHANGE_SOUND => 'bell',
            self::KDS_CANCEL_SOUND => 'alert',
            self::KDS_SOUND_VOLUME => '80',
            self::KDS_REMINDER_SECONDS => '30',
            self::FEATURE_REVENUES_ENABLED => 'false',
            self::FEATURE_CUSTOM_FEES_ENABLED => 'false',
            self::FEATURE_OUTSOURCED_ORDERS_ENABLED => 'false',
            self::FEATURE_EQUIPMENT_ENABLED => 'false',
            self::FEATURE_VARIANTS_ENABLED => 'false',
            self::FEATURE_MULTI_TERMINAL_ENABLED => 'false',
            self::FEATURE_PURCHASE_BARCODES_ENABLED => 'false',
            self::FEATURE_LABEL_PRINTING_ENABLED => 'false',
            self::FEATURE_DOCUMENT_BARCODES_ENABLED => 'false',
            self::PURCHASE_BARCODE_PREFIX => '',
            self::PURCHASE_BARCODE_DIGITS => '4',
            self::PURCHASE_BARCODE_NEXT_NUMBER => '1',
            self::MAX_TERMINALS => '2',
            self::BUSINESS_TYPE => BusinessType::RESTAURANT->value,
            self::ENABLED_MODULES => self::enabledModulesDefault(),
            self::SETUP_COMPLETED => 'false',
            self::RETURNS_MAX_DAYS => '0',
            self::WEB_API_KEY => '',
            self::WEBSITE_INTEGRATION_TOKEN => '',
        };
    }

    /**
     * Get validation rules for this setting
     */
    public function validationRules(): array
    {
        return match ($this) {
            self::WEBSITE_URL => ['required', 'url', 'max:255'],
            self::CASHIER_PRINTER_IP => ['required', 'ip', 'max:15'],
            self::RECEIPT_FOOTER => ['nullable', 'string', 'max:500'],
            self::DINE_IN_SERVICE_CHARGE => ['required', 'numeric', 'min:0', 'max:1'],
            self::RESTAURANT_NAME => ['required', 'string', 'max:255'],
            self::RESTAURANT_PRINT_LOGO => ['nullable', 'string', 'max:255'],
            self::RESTAURANT_OFFICIAL_LOGO => ['nullable', 'string', 'max:255'],
            self::RESTAURANT_QR_LOGO => ['nullable', 'string', 'max:255'],
            self::NODE_TYPE => ['required', 'in:master,slave,independent'],
            self::MASTER_NODE_LINK => ['nullable', 'url', 'max:255'],
            self::SCALE_BARCODE_PREFIX => ['required', 'string', 'regex:/^\d{1,4}$/', 'max:4'],
            self::ALLOW_WEB_ORDERS_SHIFT_TRANSFER => ['required', 'boolean'],
            self::CASHIER_TABS => ['nullable', 'json'],
            self::CASHIER_TABS_ORIENTATION => ['required', 'string', 'in:horizontal,vertical'],
            self::COSTING_METHOD => ['required', 'in:off,immediate,weighted_average'],
            // Settings are stored as strings. Laravel's `boolean` rule rejects
            // "true"/"false", so these accept the stored form and real booleans.
            self::KDS_ITEM_TRACKING => self::storedBooleanRules(),
            self::KDS_DEFAULT_PREP_MINUTES => ['required', 'integer', 'min:1', 'max:240'],
            self::KDS_ORDER_TYPE_TARGETS => ['nullable', 'json'],
            self::KDS_WARNING_PERCENT => ['required', 'integer', 'min:1', 'max:100'],
            self::KDS_AUTO_FINISH_ON_COMPLETE => self::storedBooleanRules(),
            self::KDS_CASHIER_STATUS_ENABLED => self::storedBooleanRules(),
            self::KDS_RECALL_MINUTES => ['required', 'integer', 'min:1', 'max:240'],
            self::KDS_NEW_ORDER_SOUND => self::kdsSoundRules(),
            self::KDS_CHANGE_SOUND => self::kdsSoundRules(),
            self::KDS_CANCEL_SOUND => self::kdsSoundRules(),
            self::KDS_SOUND_VOLUME => ['required', 'integer', 'min:0', 'max:100'],
            self::KDS_REMINDER_SECONDS => ['required', 'integer', 'min:0', 'max:600'],
            self::FEATURE_REVENUES_ENABLED => self::storedBooleanRules(),
            self::FEATURE_CUSTOM_FEES_ENABLED => self::storedBooleanRules(),
            self::FEATURE_OUTSOURCED_ORDERS_ENABLED => self::storedBooleanRules(),
            self::FEATURE_EQUIPMENT_ENABLED => self::storedBooleanRules(),
            self::FEATURE_VARIANTS_ENABLED => self::storedBooleanRules(),
            self::FEATURE_MULTI_TERMINAL_ENABLED => self::storedBooleanRules(),
            self::FEATURE_PURCHASE_BARCODES_ENABLED => self::storedBooleanRules(),
            self::FEATURE_LABEL_PRINTING_ENABLED => self::storedBooleanRules(),
            self::FEATURE_DOCUMENT_BARCODES_ENABLED => self::storedBooleanRules(),
            self::PURCHASE_BARCODE_PREFIX => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]*$/'],
            self::PURCHASE_BARCODE_DIGITS => ['required', 'integer', 'min:1', 'max:12'],
            self::PURCHASE_BARCODE_NEXT_NUMBER => ['required', 'integer', 'min:0', 'max:999999999999'],
            self::MAX_TERMINALS => ['required', 'integer', 'min:1', 'max:50'],
            self::BUSINESS_TYPE => ['required', Rule::enum(BusinessType::class)],
            self::ENABLED_MODULES => ['nullable', 'json'],
            self::SETUP_COMPLETED => self::storedBooleanRules(),
            self::RETURNS_MAX_DAYS => ['required', 'integer', 'min:0', 'max:3650'],
            self::WEB_API_KEY => ['nullable', 'string', 'min:16', 'max:255', 'regex:/^\S*$/'],
            self::WEBSITE_INTEGRATION_TOKEN => ['nullable', 'string', 'min:16', 'max:255', 'regex:/^\S*$/'],
        };
    }

    /**
     * Get Arabic label for this setting
     */
    public function label(): string
    {
        // A shop's wording profile renames restaurant words (see Wording).
        return Wording::text(match ($this) {
            self::WEBSITE_URL => 'رابط الموقع',
            self::CASHIER_PRINTER_IP => 'عنوان IP لطابعة الكاشير',
            self::RECEIPT_FOOTER => 'تذييل الفاتورة',
            self::DINE_IN_SERVICE_CHARGE => 'رسوم الخدمة للطعام الداخلي',
            self::RESTAURANT_NAME => 'اسم المطعم',
            self::RESTAURANT_PRINT_LOGO => 'شعار المطعم للطباعة',
            self::RESTAURANT_OFFICIAL_LOGO => 'الشعار الرسمي للمطعم',
            self::RESTAURANT_QR_LOGO => 'شعار QR للمطعم',
            self::NODE_TYPE => 'نوع النقطة الحالية',
            self::MASTER_NODE_LINK => 'رابط النقطة الرئيسية',
            self::SCALE_BARCODE_PREFIX => 'بادئة باركود الميزان',
            self::ALLOW_WEB_ORDERS_SHIFT_TRANSFER => 'نقل طلبات الويب المفتوحة إلى الوردية التالية',
            self::CASHIER_TABS => 'تبويبات الكاشير',
            self::CASHIER_TABS_ORIENTATION => 'طريقة عرض تبويبات الكاشير',
            self::COSTING_METHOD => 'طريقة تقييم التكلفة',
            self::KDS_ITEM_TRACKING => 'تتبع الأصناف بشكل منفصل',
            self::KDS_DEFAULT_PREP_MINUTES => 'وقت التحضير الافتراضي (دقائق)',
            self::KDS_ORDER_TYPE_TARGETS => 'الوقت المستهدف لكل نوع طلب',
            self::KDS_WARNING_PERCENT => 'نسبة التحذير من الوقت المستهدف',
            self::KDS_AUTO_FINISH_ON_COMPLETE => 'إنهاء تذكرة المطبخ عند إنهاء الطلب',
            self::KDS_CASHIER_STATUS_ENABLED => 'عرض حالة المطبخ للكاشير',
            self::KDS_RECALL_MINUTES => 'مدة استرجاع التذاكر المنتهية',
            self::KDS_NEW_ORDER_SOUND => 'صوت الطلب الجديد',
            self::KDS_CHANGE_SOUND => 'صوت تعديل الطلب',
            self::KDS_CANCEL_SOUND => 'صوت إلغاء الطلب',
            self::KDS_SOUND_VOLUME => 'مستوى الصوت',
            self::KDS_REMINDER_SECONDS => 'تكرار التنبيه (ثواني، 0 = إيقاف)',
            self::FEATURE_REVENUES_ENABLED => 'تفعيل الإيرادات الأخرى للوردية',
            self::FEATURE_CUSTOM_FEES_ENABLED => 'تفعيل الرسوم الإضافية المخصصة على الطلبات',
            self::FEATURE_OUTSOURCED_ORDERS_ENABLED => 'تفعيل الطلبات الخارجية والمتاجر المتعاونة',
            self::FEATURE_EQUIPMENT_ENABLED => 'تفعيل المعدات وتأجيرها على الطلبات',
            self::FEATURE_VARIANTS_ENABLED => 'تفعيل المقاسات والألوان (متغيرات المنتجات)',
            self::FEATURE_MULTI_TERMINAL_ENABLED => 'تفعيل أكثر من منفذ بيع في الوردية',
            self::FEATURE_PURCHASE_BARCODES_ENABLED => 'تفعيل توليد الباركود من فاتورة الشراء',
            self::FEATURE_LABEL_PRINTING_ENABLED => 'تفعيل تصميم وطباعة ملصقات الباركود',
            self::FEATURE_DOCUMENT_BARCODES_ENABLED => 'إظهار الباركود وطباعة الملصقات في المستندات',
            self::PURCHASE_BARCODE_PREFIX => 'بادئة الباركود المولد',
            self::PURCHASE_BARCODE_DIGITS => 'عدد أرقام المسلسل',
            self::PURCHASE_BARCODE_NEXT_NUMBER => 'الرقم التالي في المسلسل',
            self::MAX_TERMINALS => 'أقصى عدد لمنافذ البيع',
            self::BUSINESS_TYPE => 'نوع النشاط',
            self::ENABLED_MODULES => 'الوحدات المفعلة',
            self::SETUP_COMPLETED => 'اكتمل الإعداد الأولي',
            self::RETURNS_MAX_DAYS => 'أقصى عدد أيام للمرتجع',
            self::WEB_API_KEY => 'مفتاح API للموقع والفروع',
            self::WEBSITE_INTEGRATION_TOKEN => 'رمز التكامل من الموقع',
        });
    }

    /**
     * Get helper text for this setting
     */
    public function helperText(): string
    {
        // A shop's wording profile renames restaurant words (see Wording).
        return Wording::text(match ($this) {
            self::WEBSITE_URL => 'الرابط الأساسي للموقع الإلكتروني',
            self::CASHIER_PRINTER_IP => 'عنوان IP الخاص بطابعة الكاشير لطباعة الفواتير',
            self::RECEIPT_FOOTER => 'النص الذي يظهر في نهاية كل فاتورة مطبوعة',
            self::DINE_IN_SERVICE_CHARGE => 'نسبة رسوم الخدمة للطعام الداخلي (مثال: 0.12 تعني 12%)',
            self::RESTAURANT_NAME => 'اسم المطعم الذي سيظهر في الفواتير والتقارير',
            self::RESTAURANT_PRINT_LOGO => 'شعار المطعم للطباعة (يفضل ملف خفيف وبالأبيض والأسود PNG للطابعات)',
            self::RESTAURANT_OFFICIAL_LOGO => 'الشعار الرسمي للمطعم (سيتم حفظه في public/images/logo.jpg)',
            self::RESTAURANT_QR_LOGO => 'شعار QR للمطعم (يظهر في الفواتير بجانب شعار النظام)',
            self::NODE_TYPE => 'تحديد نوع النقطة الحالية في شبكة الفروع',
            self::MASTER_NODE_LINK => 'رابط النقطة الرئيسية (مطلوب فقط إذا كان النوع عبارة عن فرع)',
            self::SCALE_BARCODE_PREFIX => 'البادئة المستخدمة لتحديد باركود المنتجات الموزونة (مثال: 23)',
            self::ALLOW_WEB_ORDERS_SHIFT_TRANSFER => 'عند تفعيل هذا الخيار، طلبات الويب (دليفري وتيك أواي) المفتوحة سيتم نقلها تلقائياً للوردية الجديدة عند إغلاق الوردية الحالية',
            self::CASHIER_TABS => 'اسحب التبويبات لإعادة ترتيبها، واستخدم مفتاح «مرئي» لإظهار التبويب أو إخفائه في شاشة الكاشير.',
            self::CASHIER_TABS_ORIENTATION => 'اختر ظهور تبويبات شاشة الكاشير بشكل أفقي (في الأعلى) أو عمودي (على الجانب).',
            self::COSTING_METHOD => 'اختر طريقة تحديث تكلفة المنتجات عند استلام المخزون. يمكن تغيير الطريقة عند إغلاق جميع الورديات.',
            self::KDS_ITEM_TRACKING => 'عند التفعيل يستطيع الطاهي تعليم كل صنف كمنتهٍ بمفرده. عند الإيقاف يُنهى الطلب كاملاً فقط، وتأخذ الأصناف وقت التذكرة.',
            self::KDS_DEFAULT_PREP_MINUTES => 'الوقت المستهدف المستخدم إذا لم يُحدد وقت للصنف أو للتصنيف أو لنوع الطلب.',
            self::KDS_ORDER_TYPE_TARGETS => 'كائن JSON يربط نوع الطلب بالدقائق المستهدفة، ويُستخدم عندما لا يحدد الصنف أو التصنيف وقتاً. اتركه {} إذا لم ترد تخصيصاً.',
            self::KDS_WARNING_PERCENT => 'تتحول بطاقة الطلب إلى حالة تحذير عند بلوغ هذه النسبة من الوقت المستهدف، وإلى متأخر بعد تجاوز الوقت.',
            self::KDS_AUTO_FINISH_ON_COMPLETE => 'عند إكمال الطلب أو تحصيله تُنهى تذكرة المطبخ تلقائياً، وتُعلَّم الأصناف بأنها أُنهيت تلقائياً ولا تُحتسب في إحصاءات وقت التحضير.',
            self::KDS_CASHIER_STATUS_ENABLED => 'عرض حالة الطلب في المطبخ (جاهز أو قيد التحضير) للكاشير عند متابعة الطلبات وإشعاره عند اكتمال الطلب.',
            self::KDS_RECALL_MINUTES => 'عدد الدقائق التي تبقى فيها التذاكر المنتهية ظاهرة وقابلة للاسترجاع أو للتراجع عن إنهاء صنف.',
            self::KDS_NEW_ORDER_SOUND => 'النغمة التي تُشغَّل عند وصول طلب جديد إلى شاشة المطبخ. تُولَّد النغمات في المتصفح دون ملفات صوت.',
            self::KDS_CHANGE_SOUND => 'النغمة التي تُشغَّل عند تعديل أصناف طلب موجود على شاشة المطبخ.',
            self::KDS_CANCEL_SOUND => 'النغمة التي تُشغَّل عند إلغاء طلب معروض على شاشة المطبخ.',
            self::KDS_SOUND_VOLUME => 'مستوى صوت تنبيهات شاشة المطبخ من 0 (صامت) إلى 100.',
            self::KDS_REMINDER_SECONDS => 'يعيد تشغيل التنبيه طالما توجد تذاكر جديدة أو معدّلة لم يطّلع عليها المطبخ. ضع 0 لإيقاف التكرار.',
            self::FEATURE_REVENUES_ENABLED => 'تسجيل إيرادات الوردية التي ليست طلبات (مثل إيجار قاعة أو بيع كرتون) ببنود إيرادات، نقداً أو فيزا. يدخلها المدير فقط ولا تظهر للكاشير.',
            self::FEATURE_CUSTOM_FEES_ENABLED => 'السماح للكاشير بإضافة رسوم إضافية على الطلب (اسم وقيمة)، مثل رسوم دخول تورتة. تدخل في إجمالي الفاتورة وتُحسب ضمن الإيرادات.',
            self::FEATURE_OUTSOURCED_ORDERS_ENABLED => 'إدارة المتاجر المتعاونة، أصنافها، إضافة الطلبات الخارجية في الكاشير، وتسوية حسابات الموردين.',
            self::FEATURE_EQUIPMENT_ENABLED => 'متابعة المعدات والأدوات وتأجيرها على الطلبات وإرجاعها أو تسجيل التالف منها.',
            self::FEATURE_VARIANTS_ENABLED => 'تجميع المنتجات في موديلات لها خيارات (مقاس، لون، ...) لكل تركيبة منها مخزون وباركود وسعر.',
            self::FEATURE_MULTI_TERMINAL_ENABLED => 'لكل جهاز كاشير منفذ بيع برصيد افتتاحي وطابعة خاصين به داخل الوردية نفسها، ويمكن تصفية التقارير حسب منفذ البيع.',
            self::FEATURE_PURCHASE_BARCODES_ENABLED => 'زر في فاتورة الشراء يعطي الأصناف التي ليس لها باركود باركوداً مسلسلاً (مثال: 0001، 0002، ...). البداية والبادئة وعدد الأرقام من شاشة الإعدادات.',
            self::FEATURE_LABEL_PRINTING_ENABLED => 'تصميمات ملصقات بمقاسات المتجر، وطباعتها على أي طابعة ملصقات عبر برنامج الطباعة على ويندوز أو من المتصفح.',
            self::FEATURE_DOCUMENT_BARCODES_ENABLED => 'عمود الباركود في فواتير الشراء ومرتجع المشتريات وأذون الصرف وأذون التحويل وسجلات التالف، وزر «طباعة ملصقات» في الأربعة الأولى. زر الطباعة يحتاج تفعيل طباعة ملصقات الباركود.',
            self::PURCHASE_BARCODE_PREFIX => 'حروف أو أرقام اختيارية قبل المسلسل (مثال: CL يعطي CL0001). اتركها فارغة لباركود أرقام فقط.',
            self::PURCHASE_BARCODE_DIGITS => 'يُكمل المسلسل بأصفار على اليسار لهذا العدد (4 تعني 0001).',
            self::PURCHASE_BARCODE_NEXT_NUMBER => 'رقم أول باركود سيتم توليده. يزيد تلقائياً بعد كل توليد، والأرقام المستخدمة بالفعل يتم تخطيها.',
            self::MAX_TERMINALS => 'عدد منافذ البيع المفعلة التي يستطيع المدير إضافتها في شاشة «منافذ البيع».',
            self::BUSINESS_TYPE => 'يحدده الدعم الفني قبل أول طلب ولا يمكن تغييره بعد ذلك.',
            self::ENABLED_MODULES => 'الوحدات التي يفعلها الدعم الفني لهذه النسخة.',
            self::SETUP_COMPLETED => 'يتم ضبطه عند إنهاء معالج الإعداد الأولي.',
            self::RETURNS_MAX_DAYS => 'لا يُقبل مرتجع لطلب أقدم من هذا العدد من الأيام إلا لمن لديه صلاحية مرتجع الطلبات القديمة. ضع 0 لإلغاء الحد.',
            self::WEB_API_KEY => 'يرسله الموقع الإلكتروني والفروع في ترويسة X-Api-Key. ضع نفس القيمة في إعدادات الموقع وفي كل فرع. إذا تُرك فارغاً تُستخدم قيمة WEB_API_KEY من ملف .env.',
            self::WEBSITE_INTEGRATION_TOKEN => 'ترسله نقطة البيع للموقع عند تحديث حالة طلبات الموقع (Authorization: Bearer). أنشئه من لوحة تحكم الموقع: الطلبات ← «رمز التكامل»، ثم الصقه هنا.',
        });
    }

    /**
     * Get placeholder text for this setting
     */
    public function placeholder(): string
    {
        // A shop's wording profile renames restaurant words (see Wording).
        return Wording::text(match ($this) {
            self::WEBSITE_URL => 'http://127.0.0.1:38794',
            self::CASHIER_PRINTER_IP => '192.168.1.100',
            self::RECEIPT_FOOTER => 'أدخل النص الذي تريد أن يظهر في نهاية الفاتورة...',
            self::DINE_IN_SERVICE_CHARGE => '0.12',
            self::RESTAURANT_NAME => 'أدخل اسم المطعم...',
            self::RESTAURANT_PRINT_LOGO => 'رفع شعار للطباعة',
            self::RESTAURANT_OFFICIAL_LOGO => '/images/logo.jpg',
            self::RESTAURANT_QR_LOGO => 'رفع شعار QR',
            self::NODE_TYPE => 'اختر نوع النقطة',
            self::MASTER_NODE_LINK => 'http://127.0.0.1:38794',
            self::SCALE_BARCODE_PREFIX => '23',
            self::ALLOW_WEB_ORDERS_SHIFT_TRANSFER => 'تفعيل',
            self::CASHIER_TABS => self::cashierTabsDefault(),
            self::CASHIER_TABS_ORIENTATION => 'اختر طريقة العرض',
            self::COSTING_METHOD => 'اختر طريقة تقييم التكلفة',
            self::KDS_ITEM_TRACKING => 'تفعيل',
            self::KDS_DEFAULT_PREP_MINUTES => '10',
            self::KDS_ORDER_TYPE_TARGETS => '{}',
            self::KDS_WARNING_PERCENT => '75',
            self::KDS_AUTO_FINISH_ON_COMPLETE => 'تفعيل',
            self::KDS_CASHIER_STATUS_ENABLED => 'تفعيل',
            self::KDS_RECALL_MINUTES => '15',
            self::KDS_NEW_ORDER_SOUND => 'chime',
            self::KDS_CHANGE_SOUND => 'bell',
            self::KDS_CANCEL_SOUND => 'alert',
            self::KDS_SOUND_VOLUME => '80',
            self::KDS_REMINDER_SECONDS => '30',
            self::FEATURE_REVENUES_ENABLED => 'تفعيل',
            self::FEATURE_CUSTOM_FEES_ENABLED => 'تفعيل',
            self::FEATURE_OUTSOURCED_ORDERS_ENABLED => 'تفعيل',
            self::FEATURE_EQUIPMENT_ENABLED => 'تفعيل',
            self::FEATURE_VARIANTS_ENABLED => 'تفعيل',
            self::FEATURE_MULTI_TERMINAL_ENABLED => 'تفعيل',
            self::FEATURE_PURCHASE_BARCODES_ENABLED => 'تفعيل',
            self::FEATURE_LABEL_PRINTING_ENABLED => 'تفعيل',
            self::FEATURE_DOCUMENT_BARCODES_ENABLED => 'تفعيل',
            self::PURCHASE_BARCODE_PREFIX => 'بدون بادئة',
            self::PURCHASE_BARCODE_DIGITS => '4',
            self::PURCHASE_BARCODE_NEXT_NUMBER => '1',
            self::MAX_TERMINALS => '2',
            self::BUSINESS_TYPE => 'restaurant',
            self::ENABLED_MODULES => '[]',
            self::SETUP_COMPLETED => 'false',
            self::RETURNS_MAX_DAYS => '0',
            self::WEB_API_KEY => 'اضغط توليد لإنشاء مفتاح جديد',
            self::WEBSITE_INTEGRATION_TOKEN => 'الصق رمز التكامل من الموقع',
        });
    }

    /**
     * Validate the value for this setting
     */
    public function validate(mixed $value): bool
    {
        return match ($this) {
            self::WEBSITE_URL => filter_var($value, FILTER_VALIDATE_URL) !== false,
            self::CASHIER_PRINTER_IP => filter_var($value, FILTER_VALIDATE_IP) !== false,
            self::RECEIPT_FOOTER => true, // Always valid for text
            self::DINE_IN_SERVICE_CHARGE => is_numeric($value) && $value >= 0 && $value <= 1,
            self::RESTAURANT_NAME => is_string($value) && strlen($value) > 0,
            self::RESTAURANT_PRINT_LOGO => true, // Always valid for file path
            self::RESTAURANT_OFFICIAL_LOGO => true, // Always valid for file path
            self::RESTAURANT_QR_LOGO => true, // Always valid for file path
            self::NODE_TYPE => in_array($value, ['master', 'slave', 'independent']),
            self::MASTER_NODE_LINK => ! $value || filter_var($value, FILTER_VALIDATE_URL) !== false,
            self::SCALE_BARCODE_PREFIX => is_string($value) && preg_match('/^\d{1,4}$/', $value),
            self::ALLOW_WEB_ORDERS_SHIFT_TRANSFER => is_bool($value) || in_array($value, ['true', 'false', '1', '0', 1, 0]),
            self::CASHIER_TABS => self::isJsonValue($value),
            self::CASHIER_TABS_ORIENTATION => in_array($value, ['horizontal', 'vertical'], true),
            self::COSTING_METHOD => is_string($value) && CostingMethod::tryFrom($value) !== null,
            self::KDS_ITEM_TRACKING,
            self::KDS_AUTO_FINISH_ON_COMPLETE,
            self::KDS_CASHIER_STATUS_ENABLED,
            self::FEATURE_REVENUES_ENABLED,
            self::FEATURE_CUSTOM_FEES_ENABLED,
            self::FEATURE_OUTSOURCED_ORDERS_ENABLED,
            self::FEATURE_EQUIPMENT_ENABLED,
            self::FEATURE_VARIANTS_ENABLED,
            self::FEATURE_MULTI_TERMINAL_ENABLED,
            self::FEATURE_PURCHASE_BARCODES_ENABLED => self::isStoredBoolean($value),
            self::FEATURE_LABEL_PRINTING_ENABLED => self::isStoredBoolean($value),
            self::FEATURE_DOCUMENT_BARCODES_ENABLED => self::isStoredBoolean($value),
            self::PURCHASE_BARCODE_PREFIX => $value === null || (is_string($value) && preg_match('/^[A-Za-z0-9]{0,10}$/', $value) === 1),
            self::PURCHASE_BARCODE_DIGITS => self::isIntInRange($value, 1, 12),
            self::PURCHASE_BARCODE_NEXT_NUMBER => self::isIntInRange($value, 0, 999_999_999_999),
            self::MAX_TERMINALS => self::isIntInRange($value, 1, 50),
            self::BUSINESS_TYPE => is_string($value) && BusinessType::tryFrom($value) !== null,
            self::ENABLED_MODULES => self::isModuleList($value),
            self::SETUP_COMPLETED => self::isStoredBoolean($value),
            self::KDS_DEFAULT_PREP_MINUTES, self::KDS_RECALL_MINUTES => self::isIntInRange($value, 1, 240),
            self::KDS_WARNING_PERCENT => self::isIntInRange($value, 1, 100),
            self::KDS_SOUND_VOLUME => self::isIntInRange($value, 0, 100),
            self::KDS_REMINDER_SECONDS => self::isIntInRange($value, 0, 600),
            self::RETURNS_MAX_DAYS => self::isIntInRange($value, 0, 3650),
            self::WEB_API_KEY, self::WEBSITE_INTEGRATION_TOKEN => $value === null || $value === '' || (is_string($value) && strlen($value) >= 16 && strlen($value) <= 255 && preg_match('/^\S+$/', $value) === 1),
            self::KDS_ORDER_TYPE_TARGETS => self::isJsonValue($value),
            self::KDS_NEW_ORDER_SOUND, self::KDS_CHANGE_SOUND, self::KDS_CANCEL_SOUND => is_string($value) && KdsSound::tryFrom($value) !== null,
        };
    }

    public function isBooleanSetting(): bool
    {
        return match ($this) {
            self::ALLOW_WEB_ORDERS_SHIFT_TRANSFER,
            self::KDS_ITEM_TRACKING,
            self::KDS_AUTO_FINISH_ON_COMPLETE,
            self::KDS_CASHIER_STATUS_ENABLED,
            self::FEATURE_REVENUES_ENABLED,
            self::FEATURE_CUSTOM_FEES_ENABLED,
            self::FEATURE_OUTSOURCED_ORDERS_ENABLED,
            self::FEATURE_EQUIPMENT_ENABLED,
            self::FEATURE_VARIANTS_ENABLED,
            self::FEATURE_MULTI_TERMINAL_ENABLED,
            self::FEATURE_PURCHASE_BARCODES_ENABLED,
            self::FEATURE_LABEL_PRINTING_ENABLED,
            self::FEATURE_DOCUMENT_BARCODES_ENABLED,
            self::SETUP_COMPLETED => true,
            default => false,
        };
    }

    public function isFeatureFlag(): bool
    {
        return match ($this) {
            self::FEATURE_REVENUES_ENABLED,
            self::FEATURE_CUSTOM_FEES_ENABLED,
            self::FEATURE_OUTSOURCED_ORDERS_ENABLED,
            self::FEATURE_EQUIPMENT_ENABLED,
            self::FEATURE_VARIANTS_ENABLED,
            self::FEATURE_MULTI_TERMINAL_ENABLED,
            self::FEATURE_PURCHASE_BARCODES_ENABLED => true,
            self::FEATURE_LABEL_PRINTING_ENABLED => true,
            self::FEATURE_DOCUMENT_BARCODES_ENABLED => true,
            default => false,
        };
    }

    /**
     * Settings only the vendor account may change (vendor console or
     * artisan commands). The customer Settings page never reads, saves
     * or resets them.
     */
    public function isVendorOwned(): bool
    {
        return $this->isFeatureFlag() || match ($this) {
            self::BUSINESS_TYPE,
            self::ENABLED_MODULES,
            self::MAX_TERMINALS,
            self::SETUP_COMPLETED => true,
            default => false,
        };
    }

    public static function parseStoredBoolean(string $key, mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return true;
        }

        if ($value === false || $value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        $message = __('validation.boolean', ['attribute' => $key]);

        throw ValidationException::withMessages([
            'data.'.$key => $message,
            $key => $message,
        ]);
    }

    private static function enabledModulesDefault(): string
    {
        return json_encode(array_map(
            fn (Module $module): string => $module->value,
            BusinessType::RESTAURANT->defaultModules(),
        ));
    }

    private static function isModuleList(mixed $value): bool
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return false;
        }

        foreach ($decoded as $item) {
            if (! is_string($item) || Module::tryFrom($item) === null) {
                return false;
            }
        }

        return true;
    }

    private static function cashierTabsDefault(): string
    {
        return json_encode([
            ['key' => 'type:dine_in', 'visible' => true],
            ['key' => 'type:takeaway', 'visible' => true],
            ['key' => 'type:delivery', 'visible' => true],
            ['key' => 'type:talabat', 'visible' => true],
            ['key' => 'type:companies', 'visible' => true],
            ['key' => 'type:web_delivery', 'visible' => true],
            ['key' => 'type:web_takeaway', 'visible' => true],
            ['key' => 'system:receivables', 'visible' => true],
            ['key' => 'system:expenses', 'visible' => true],
            ['key' => 'system:cancelled', 'visible' => true],
            ['key' => 'system:completed', 'visible' => true],
        ], JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * Boolean settings are stored as the strings "true" and "false".
     * Laravel's boolean rule rejects those strings, so accept the stored form too.
     *
     * @return array<int, string|Closure(string, mixed, Closure): void>
     */
    private static function storedBooleanRules(): array
    {
        return [
            'required',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::isStoredBoolean($value)) {
                    $fail(__('validation.boolean', ['attribute' => $attribute]));
                }
            },
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function kdsSoundRules(): array
    {
        $sounds = implode(',', array_map(fn (KdsSound $sound): string => $sound->value, KdsSound::cases()));

        return ['required', 'in:'.$sounds];
    }

    private static function isStoredBoolean(mixed $value): bool
    {
        return is_bool($value) || in_array($value, ['true', 'false', '1', '0', 1, 0], true);
    }

    private static function isIntInRange(mixed $value, int $min, int $max): bool
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int !== false && $int >= $min && $int <= $max;
    }

    private static function isJsonValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }
}
