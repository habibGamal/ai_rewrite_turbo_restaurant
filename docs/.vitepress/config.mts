import { defineConfig } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'

const enSidebar = [
  {
    text: 'Getting started',
    items: [
      { text: 'Overview', link: '/technical/' },
      { text: 'Local setup', link: '/technical/setup' },
      { text: 'Deployment', link: '/technical/deployment' },
    ],
  },
  {
    text: 'Architecture',
    items: [
      { text: 'System architecture', link: '/technical/architecture' },
      { text: 'Modules & business types', link: '/technical/modules' },
      { text: 'Order lifecycle & domain events', link: '/technical/orders-and-events' },
      { text: 'Real-time, queue & Reverb', link: '/technical/realtime' },
      { text: 'Money: charges, returns, shifts', link: '/technical/money' },
    ],
  },
  {
    text: 'Administration',
    items: [
      { text: 'Vendor account & console', link: '/technical/vendor' },
      { text: 'Settings reference', link: '/technical/settings' },
      { text: 'Roles & permissions', link: '/technical/permissions' },
      { text: 'Artisan commands', link: '/technical/commands' },
    ],
  },
  {
    text: 'Integrations',
    items: [
      { text: 'Website integration API', link: '/technical/website-integration' },
      { text: 'Branches (master / slave)', link: '/technical/branches' },
      { text: 'Platform sync', link: '/technical/platform-sync' },
    ],
  },
  {
    text: 'Contributing',
    items: [
      { text: 'Testing & conventions', link: '/technical/testing' },
    ],
  },
]

const arSidebar = [
  {
    text: 'البداية',
    items: [
      { text: 'مرحباً بك', link: '/ar/guide/' },
      { text: 'أول تشغيل وتسجيل الدخول', link: '/ar/guide/first-run' },
      { text: 'المستخدمون والصلاحيات', link: '/ar/guide/users' },
    ],
  },
  {
    text: 'الكاشير',
    items: [
      { text: 'الوردية: فتح وإغلاق', link: '/ar/guide/shifts' },
      { text: 'شاشة الكاشير وأنواع الطلبات', link: '/ar/guide/cashier' },
      { text: 'البيع المباشر (الباركود)', link: '/ar/guide/instant-sale' },
      { text: 'الدفع والآجل', link: '/ar/guide/payment' },
      { text: 'المرتجعات والاستبدال', link: '/ar/guide/returns' },
      { text: 'الرسوم الإضافية', link: '/ar/guide/fees' },
      { text: 'المعدات', link: '/ar/guide/equipment' },
      { text: 'عدد الأفراد', link: '/ar/guide/guests' },
      { text: 'طلبات الموقع', link: '/ar/guide/web-orders' },
      { text: 'شاشة المطبخ', link: '/ar/guide/kitchen' },
    ],
  },
  {
    text: 'الإدارة',
    items: [
      { text: 'المنتجات والفئات', link: '/ar/guide/products' },
      { text: 'المقاسات والألوان (الموديلات)', link: '/ar/guide/variants' },
      { text: 'أنواع الطلبات', link: '/ar/guide/order-types' },
      { text: 'المخزون والمشتريات', link: '/ar/guide/inventory' },
      { text: 'المصروفات والإيرادات', link: '/ar/guide/expenses-revenues' },
      { text: 'العملاء والتوصيل', link: '/ar/guide/customers-delivery' },
      { text: 'التقارير', link: '/ar/guide/reports' },
      { text: 'الإعدادات', link: '/ar/guide/settings' },
      { text: 'ربط الموقع الإلكتروني', link: '/ar/guide/website' },
    ],
  },
  {
    text: 'مساعدة',
    items: [
      { text: 'أسئلة شائعة', link: '/ar/guide/faq' },
    ],
  },
]

export default withMermaid(
  defineConfig({
    title: 'Turbo POS',
    description: 'Multi-purpose point of sale for restaurants, cafés, fashion and grocery stores',
    cleanUrls: true,
    lastUpdated: true,
    head: [
      ['link', { rel: 'icon', type: 'image/svg+xml', href: '/logo.svg' }],
      ['link', { rel: 'preconnect', href: 'https://fonts.googleapis.com' }],
      ['link', { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' }],
      [
        'link',
        {
          rel: 'stylesheet',
          href: 'https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap',
        },
      ],
    ],
    themeConfig: {
      logo: '/logo.svg',
      search: {
        provider: 'local',
        options: {
          locales: {
            ar: {
              translations: {
                button: { buttonText: 'بحث', buttonAriaLabel: 'بحث' },
                modal: {
                  noResultsText: 'لا توجد نتائج لـ',
                  resetButtonTitle: 'مسح',
                  footer: { selectText: 'اختيار', navigateText: 'تنقل', closeText: 'إغلاق' },
                },
              },
            },
          },
        },
      },
      socialLinks: [{ icon: 'github', link: 'https://github.com/habibGamal/ai_rewrite_turbo_restaurant' }],
    },
    locales: {
      root: {
        label: 'English (technical)',
        lang: 'en',
        dir: 'ltr',
        themeConfig: {
          nav: [
            { text: 'Technical handbook', link: '/technical/', activeMatch: '/technical/' },
            { text: 'Website API', link: '/technical/website-integration' },
            { text: 'دليل المستخدم', link: '/ar/guide/' },
          ],
          sidebar: { '/technical/': enSidebar },
          outline: { level: [2, 3] },
          footer: {
            message: 'Turbo POS documentation. The Arabic user guide lives under /ar/.',
          },
        },
      },
      ar: {
        label: 'العربية (دليل المستخدم)',
        lang: 'ar',
        dir: 'rtl',
        link: '/ar/',
        themeConfig: {
          nav: [
            { text: 'دليل المستخدم', link: '/ar/guide/', activeMatch: '/ar/guide/' },
            { text: 'التقارير', link: '/ar/guide/reports' },
            { text: 'Technical', link: '/technical/' },
          ],
          sidebar: { '/ar/guide/': arSidebar },
          outline: { level: [2, 3], label: 'في هذه الصفحة' },
          docFooter: { prev: 'السابق', next: 'التالي' },
          lastUpdated: { text: 'آخر تحديث' },
          darkModeSwitchLabel: 'المظهر',
          lightModeSwitchTitle: 'الوضع الفاتح',
          darkModeSwitchTitle: 'الوضع الداكن',
          sidebarMenuLabel: 'القائمة',
          returnToTopLabel: 'العودة للأعلى',
          langMenuLabel: 'اللغة',
          notFound: { title: 'الصفحة غير موجودة', linkText: 'العودة للرئيسية', quote: '' },
          footer: { message: 'دليل استخدام Turbo POS' },
        },
      },
    },
    mermaid: {
      theme: 'base',
      themeVariables: {
        primaryColor: '#ccfbf1',
        primaryBorderColor: '#0f766e',
        primaryTextColor: '#134e4a',
        lineColor: '#0d9488',
        secondaryColor: '#fef3c7',
        tertiaryColor: '#f8fafc',
        clusterBkg: '#f0fdfa',
        clusterBorder: '#99f6e4',
        fontFamily: 'Inter, IBM Plex Sans Arabic, sans-serif',
      },
    },
  }),
)
