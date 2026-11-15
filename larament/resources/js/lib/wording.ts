import { useModule } from '@/hooks/useModule';

/**
 * Business-aware POS words. Generic components read their restaurant words
 * from here so a shop install never shows them. Strings that only render on
 * restaurant-only screens (kitchen, floor designer, table picker) may stay
 * inline in those files.
 *
 * The restaurant words live ONLY between the RESTAURANT-WORDS markers; the
 * PosRestaurantWordsTest allows them there and nowhere else in generic files.
 */
export interface PosTerms {
    /** "the kitchen" as a destination: print in the kitchen, sent to the kitchen. */
    printInKitchen: string;
    sentToKitchen: string;
    sendToKitchenFailed: string;
    changesSentToKitchen: string;
    sendChangesFailed: string;
    saveAndPrintInKitchen: string;
    kitchenItemsReady: string;
    /** Table wording used by generic order screens for dine-in style order types. */
    table: string;
    tableNumber: string;
    tableType: string;
    chooseTableNumber: string;
    chooseTableType: string;
    tablesOutsideLayout: string;
    tableOutsideLayout: string;
    addTable: string;
    tableAlreadyInLayout: string;
    fetchTableTypesFailed: string;
    defaultTableType: string;
    /** External seller receipt: the host business share. */
    hostTotal: string;
}

// RESTAURANT-WORDS:BEGIN
const RESTAURANT_TERMS: PosTerms = {
    printInKitchen: 'طباعة في المطبخ',
    sentToKitchen: 'تم إرسال الطلب للمطبخ بنجاح',
    sendToKitchenFailed: 'حدث خطأ أثناء إرسال الطلب للمطبخ',
    changesSentToKitchen: 'تم إرسال التغييرات للمطبخ بنجاح',
    sendChangesFailed: 'حدث خطأ أثناء إرسال التغييرات للمطبخ',
    saveAndPrintInKitchen: 'حفظ وطباعة في المطبخ',
    kitchenItemsReady: 'تم الانتهاء من تحضير أصناف الطلب في المطبخ',
    table: 'طاولة',
    tableNumber: 'رقم الطاولة',
    tableType: 'نوع الطاولة',
    chooseTableNumber: 'يرجى اختيار رقم الطاولة',
    chooseTableType: 'يرجى اختيار نوع الطاولة',
    tablesOutsideLayout: 'طاولات خارج المخطط',
    tableOutsideLayout: 'طاولة خارج المخطط',
    addTable: 'إضافة طاولة جديدة',
    tableAlreadyInLayout: 'الطاولة موجودة في المخطط',
    fetchTableTypesFailed: 'حدث خطأ أثناء جلب أنواع الطاولات',
    defaultTableType: 'صالة',
    hostTotal: 'إجمالي المطعم',
};
// RESTAURANT-WORDS:END

const NEUTRAL_TERMS: PosTerms = {
    printInKitchen: 'طباعة التحضير',
    sentToKitchen: 'تم إرسال الطلب للتحضير بنجاح',
    sendToKitchenFailed: 'حدث خطأ أثناء إرسال الطلب للتحضير',
    changesSentToKitchen: 'تم إرسال التغييرات للتحضير بنجاح',
    sendChangesFailed: 'حدث خطأ أثناء إرسال التغييرات للتحضير',
    saveAndPrintInKitchen: 'حفظ وطباعة التحضير',
    kitchenItemsReady: 'تم الانتهاء من تحضير أصناف الطلب',
    table: 'رقم',
    tableNumber: 'الرقم',
    tableType: 'النوع',
    chooseTableNumber: 'يرجى اختيار الرقم',
    chooseTableType: 'يرجى اختيار النوع',
    tablesOutsideLayout: 'أرقام خارج المخطط',
    tableOutsideLayout: 'رقم خارج المخطط',
    addTable: 'إضافة رقم جديد',
    tableAlreadyInLayout: 'الرقم موجود في المخطط',
    fetchTableTypesFailed: 'حدث خطأ أثناء جلب الأنواع',
    defaultTableType: 'عام',
    hostTotal: 'إجمالي المتجر',
};

export function termsFor(restaurant: boolean): PosTerms {
    return restaurant ? RESTAURANT_TERMS : NEUTRAL_TERMS;
}

export function useTerms(): PosTerms {
    return termsFor(useModule('restaurant'));
}
