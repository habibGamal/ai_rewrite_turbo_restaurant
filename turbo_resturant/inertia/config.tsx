import moment from 'moment'
import {
  ArchiveBox,
  ArrangeHorizontalCircle,
  Calculator,
  Chart,
  KeySquare,
  Login,
  People,
  ReceiptItem,
  Setting,
  ShoppingBag,
} from 'iconsax-react'
import { NodeType } from '#enums/SettingsEnums'
type Route = {
  name: React.ReactNode
  id: string
  icon: React.JSX.Element
  route?: string
  children?: {
    name: string
    id: string
    route?: string
  }[]
}

export const WATI_TIME_FOR_SEARCH = 500
export const configMomentLocaleAr = () =>
  moment.locale('ar', {
    months: 'يناير_فبراير_مارس_أبريل_مايو_يونيو_يوليو_أغسطس_سبتمبر_أكتوبر_نوفمبر_ديسمبر'.split('_'),
    monthsShort: 'يناير_فبراير_مارس_أبريل_مايو_يونيو_يوليو_أغسطس_سبتمبر_أكتوبر_نوفمبر_ديسمبر'.split(
      '_'
    ),
    monthsParseExact: true,
    weekdays: 'الأحد_الاثنين_الثلاثاء_الأربعاء_الخميس_الجمعة_السبت'.split('_'),
    weekdaysShort: 'الأحد_الاثنين_الثلاثاء_الأربعاء_الخميس_الجمعة_السبت'.split('_'),
    weekdaysMin: 'ح_ن_ث_ر_خ_ج_س'.split('_'),
    weekdaysParseExact: true,
    longDateFormat: {
      LT: 'HH:mm',
      LTS: 'HH:mm:ss',
      L: 'YYYY/MM/DD',
      LL: 'D MMMM YYYY',
      LLL: 'D MMMM YYYY HH:mm',
      LLLL: 'dddd D MMMM YYYY HH:mm',
    },
    calendar: {
      sameDay: '[اليوم في] LT',
      nextDay: '[غدًا في] LT',
      nextWeek: 'dddd [في] LT',
      lastDay: '[أمس في] LT',
      lastWeek: 'dddd [الماضي في] LT',
      sameElse: 'L',
    },
    relativeTime: {
      future: 'بعد %s',
      past: 'منذ %s',
      s: 'ثوانٍ',
      m: 'دقيقة',
      mm: '%d دقائق',
      h: 'ساعة',
      hh: '%d ساعات',
      d: 'يوم',
      dd: '%d أيام',
      M: 'شهر',
      MM: '%d أشهر',
      y: 'سنة',
      yy: '%d سنوات',
    },
    dayOfMonthOrdinalParse: /\d{1,2}(th|st|nd|rd)/,
    ordinal: function (number) {
      var b = number % 10
      var output =
        ~~((number % 100) / 10) === 1
          ? 'th'
          : b === 1
            ? 'st'
            : b === 2
              ? 'nd'
              : b === 3
                ? 'rd'
                : 'th'
      return number + output
    },
    meridiemParse: /ص|م/,
    isPM: function (input) {
      return input === 'م'
    },
    meridiem: function (hours, minutes, isLower) {
      if (hours < 12) {
        return 'ص'
      } else {
        return 'م'
      }
    },
    week: {
      dow: 0, // Sunday is the first day of the week.
      doy: 6, // Used to determine first week of the year.
    },
  })

export const masterRoutes: Route[] = [
  {
    name: 'الاصناف',
    id: 'products_section',
    icon: <ShoppingBag />,
    children: [
      {
        name: 'الاصناف الخام',
        id: 'raw_products',
        route: '/raw-products',
      },
      {
        name: 'الاصناف الاستهلاكية',
        id: 'consumable_products',
        route: '/consumable-products',
      },
      {
        name: 'الاصناف المصنعة',
        id: 'manifactured_products',
        route: '/manifactured-products',
      },
      {
        name: 'الفئات',
        id: 'categories',
        route: '/categories',
      },
    ],
  },
  {
    name: 'الاعدادات',
    id: 'settings',
    icon: <Setting />,
    route: '/settings',
  },
  {
    name: 'تسجيل خروج',
    id: 'logout',
    icon: <Login />,
    route: '/logout',
  },
]

const commonRoutes: Route[] = [
  {
    name: 'ادارة المخازن',
    id: 'stock_section',
    icon: <ArchiveBox />,
    children: [
      {
        name: 'مستويات المخزن',
        id: 'stock_levels_index',
        route: '/stock-levels',
      },
      {
        name: 'جرد المخزن',
        id: 'stocktaking_create',
        route: '/stocktaking/create',
      },
      {
        name: 'سجل جرد المخزن',
        id: 'stocktaking_index',
        route: '/stocktaking',
      },
      {
        name: 'الهالك',
        id: 'wastes_create',
        route: '/wastes/create',
      },
      {
        name: 'سجل الهالك',
        id: 'wastes_index',
        route: '/wastes',
      },
    ],
  },
  {
    name: <span className="block min-w-fit">الموردين والعملاء</span>,
    id: 'people',
    icon: <People />,
    children: [
      {
        name: 'الموردين',
        id: 'suppliers',
        route: '/suppliers',
      },
      {
        name: 'العملاء',
        id: 'customers',
        route: '/customers',
      },
    ],
  },
  {
    name: 'الفواتير',
    id: 'invoices',
    icon: <ReceiptItem />,
    children: [
      {
        name: 'عرض فواتير شراء',
        id: 'show_purchase_invoice',
        route: '/purchase-invoices',
      },
      {
        name: 'عرض مرتجع فواتير شراء',
        id: 'show_return_buying_invoice',
        route: '/return-purchase-invoices',
      },
      {
        name: 'انشاء فاتورة شراء',
        id: 'create_purchase_invoice',
        route: '/purchase-invoices/create',
      },
      {
        name: 'انشاء فاتورة مرتجع شراء',
        id: 'create_return_buying_invoice',
        route: '/return-purchase-invoices/create',
      },
    ],
  },
  {
    name: 'الحسابات',
    id: 'accounting',
    icon: <Calculator />,
    children: [
      {
        name: 'حسابات الموردين',
        id: 'suppliers_accounting',
        route: '/accounting/suppliers-accounting',
      },
      {
        name: 'حسابات الشركات',
        id: 'customers_accounting',
        route: '/accounting/customers-accounting',
      },
    ],
  },
  {
    name: 'الادارة',
    id: 'managment',
    icon: <KeySquare />,
    children: [
      {
        name: 'الطابعات',
        id: 'printers',
        route: '/printers',
      },
      {
        name: 'المناطق',
        id: 'regions',
        route: '/regions',
      },
      {
        name: 'انواع المصروفات',
        id: 'expnese_types',
        route: '/expense-types',
      },
      {
        name: 'المستخدمين',
        id: 'users',
        route: '/users',
      },
    ],
  },
  {
    name: 'التقارير',
    id: 'repoting',
    icon: <Chart />,
    children: [
      {
        name: 'تقرير اليوم',
        id: 'current_shift_report',
        route: '/reports/current-shift-report',
      },
      {
        name: 'تقارير المخزون',
        id: 'stock_reports',
        route: '/reports/stock-report',
      },
      {
        name: 'تقارير الورديات',
        id: 'shifts_reports',
        route: '/reports/shifts-report',
      },
      {
        name: 'تقرير شامل للورديات',
        id: 'full_shifts_report',
        route: '/reports/full-shifts-report',
      },
      {
        name: 'تقرير المصروفات',
        id: 'expenses_reports',
        route: '/reports/expenses-report',
      },
      {
        name: 'تقرير السائقين',
        id: 'drivers_reports',
        route: '/reports/drivers-report',
      },
      {
        name: 'تقارير العملاء',
        id: 'clients_reports',
        route: '/reports/clients-report',
      },
      {
        name: 'تقارير المنتجات',
        id: 'products_reports',
        route: '/reports/products-report',
      },
      {
        name: 'تقرير مفصل',
        id: 'detailed_reports',
        route: '/reports/detailed-report',
      },
    ],
  },
  {
    name: 'الاعدادات',
    id: 'settings',
    icon: <Setting />,
    route: '/settings',
  },
  {
    name: 'الكاشير',
    id: 'cashier_screen',
    icon: <ArrangeHorizontalCircle />,
    route: '/orders',
  },
  {
    name: 'تسجيل خروج',
    id: 'logout',
    icon: <Login />,
    route: '/logout',
  },
]

export const slaveRoutes: Route[] = [
  {
    name: 'الاصناف',
    id: 'products_section',
    icon: <ShoppingBag />,
    children: [
      {
        name: 'اصناف النقطة الرئيسية',
        id: 'master_products',
        route: '/import-from-master',
      },
      {
        name: 'الاصناف الخام',
        id: 'raw_products',
        route: '/raw-products',
      },
      {
        name: 'الاصناف الاستهلاكية',
        id: 'consumable_products',
        route: '/consumable-products',
      },
      {
        name: 'الاصناف المصنعة',
        id: 'manifactured_products',
        route: '/manifactured-products',
      },
      {
        name: 'الفئات',
        id: 'categories',
        route: '/categories',
      },
    ],
  },
  ...commonRoutes,
]

export const standaloneRoutes: Route[] = [
  {
    name: 'الاصناف',
    id: 'products_section',
    icon: <ShoppingBag />,
    children: [
      {
        name: 'الاصناف الخام',
        id: 'raw_products',
        route: '/raw-products',
      },
      {
        name: 'الاصناف الاستهلاكية',
        id: 'consumable_products',
        route: '/consumable-products',
      },
      {
        name: 'الاصناف المصنعة',
        id: 'manifactured_products',
        route: '/manifactured-products',
      },
      {
        name: 'الفئات',
        id: 'categories',
        route: '/categories',
      },
    ],
  },
  ...commonRoutes,
]

export const navRoutes = {
  [NodeType.Master]: masterRoutes,
  [NodeType.Slave]: slaveRoutes,
  [NodeType.Standalone]: standaloneRoutes,
}
