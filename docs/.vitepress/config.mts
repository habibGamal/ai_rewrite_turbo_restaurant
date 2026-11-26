import { defineConfig, type DefaultTheme } from 'vitepress'
import { withMermaid } from 'vitepress-plugin-mermaid'
import { productSwitcher } from './theme/products'

const isPublic = process.env.DOCS_PUBLIC === '1'

const productUrls = {
  pos: process.env.DOCS_POS_URL,
  website: process.env.DOCS_WEBSITE_URL,
  platform: process.env.DOCS_PLATFORM_URL,
}

const arabicSidebar: DefaultTheme.Sidebar = [
  {
    text: 'البداية',
    items: [
      { text: 'نظرة عامة', link: '/start/' },
      { text: 'أول تشغيل وتسجيل الدخول', link: '/start/first-run' },
      { text: 'قاموس المصطلحات', link: '/start/glossary' },
    ],
  },
  {
    text: 'دليل الاستخدام',
    items: [
      { text: 'الوردية: فتح وإغلاق', link: '/guide/shifts' },
      { text: 'شاشة الكاشير وأنواع الطلبات', link: '/guide/cashier' },
      { text: 'البيع المباشر (الباركود)', link: '/guide/instant-sale' },
      { text: 'الدفع والآجل', link: '/guide/payment' },
      { text: 'المرتجعات والاستبدال', link: '/guide/returns' },
      { text: 'الرسوم الإضافية', link: '/guide/fees' },
      { text: 'المعدات', link: '/guide/equipment' },
      { text: 'عدد الأفراد', link: '/guide/guests' },
      { text: 'طلبات الموقع', link: '/guide/web-orders' },
      { text: 'شاشة المطبخ', link: '/guide/kitchen' },
      { text: 'الملصقات والطباعة (Turbo Label)', link: '/guide/labels' },
      { text: 'أسئلة شائعة', link: '/guide/faq' },
    ],
  },
  {
    text: 'الإدارة',
    items: [
      { text: 'المنتجات والفئات', link: '/admin/products' },
      { text: 'المقاسات والألوان (الموديلات)', link: '/admin/variants' },
      { text: 'أنواع الطلبات', link: '/admin/order-types' },
      { text: 'المخزون والمشتريات', link: '/admin/inventory' },
      { text: 'المصروفات والإيرادات', link: '/admin/expenses-revenues' },
      { text: 'العملاء والتوصيل', link: '/admin/customers-delivery' },
      { text: 'التقارير', link: '/admin/reports' },
      { text: 'الإعدادات', link: '/admin/settings' },
      { text: 'المستخدمون والصلاحيات', link: '/admin/users' },
      { text: 'المساعد الذكي', link: '/admin/ai-assistant' },
    ],
  },
  {
    text: 'التكامل',
    items: [
      { text: 'ربط الموقع الإلكتروني', link: '/integrations/website' },
      { text: 'الربط بالمنصة المركزية', link: '/integrations/platform' },
      { text: 'برنامج الطباعة Turbo Label', link: '/integrations/turbo-label' },
      { text: 'ربط المساعد الذكي (MCP)', link: '/integrations/mcp' },
    ],
  },
]

const devSidebar: DefaultTheme.Sidebar = [
  {
    text: 'Developer',
    items: [
      { text: 'Overview', link: '/dev/' },
      { text: 'Local setup', link: '/dev/setup' },
      { text: 'System architecture', link: '/dev/architecture' },
      { text: 'Modules & business types', link: '/dev/modules' },
      { text: 'Order lifecycle & domain events', link: '/dev/orders-and-events' },
      { text: 'Real-time, queue & Reverb', link: '/dev/realtime' },
      { text: 'Money: charges, returns, shifts', link: '/dev/money' },
      { text: 'Vendor account & console', link: '/dev/vendor' },
      { text: 'Settings reference', link: '/dev/settings' },
      { text: 'Roles & permissions', link: '/dev/permissions' },
      { text: 'Artisan commands', link: '/dev/commands' },
      { text: 'Website integration API', link: '/dev/website-integration' },
      { text: 'Branches (master / slave)', link: '/dev/branches' },
      { text: 'Platform sync', link: '/dev/platform-sync' },
      { text: 'Printing, labels & Turbo Label', link: '/dev/printing' },
      { text: 'AI assistant (MCP server)', link: '/dev/ai-mcp' },
      { text: 'Testing & conventions', link: '/dev/testing' },
      { text: 'Documentation style guide', link: '/dev/style-guide' },
    ],
  },
  {
    text: 'Deployment and operations',
    items: [
      { text: 'Deployment', link: '/dev/ops/deployment' },
      { text: 'Runbooks', link: '/dev/ops/runbooks' },
      { text: 'E2E test stack', link: '/dev/ops/e2e-stack' },
      { text: 'Changelog', link: '/changelog' },
    ],
  },
]

const redirects: Record<string, string> = {
  '/ar': '/',
  '/ar/index': '/',
  '/ar/guide': '/start/',
  '/ar/guide/index': '/start/',
  '/ar/guide/first-run': '/start/first-run',
  '/ar/guide/shifts': '/guide/shifts',
  '/ar/guide/cashier': '/guide/cashier',
  '/ar/guide/instant-sale': '/guide/instant-sale',
  '/ar/guide/payment': '/guide/payment',
  '/ar/guide/returns': '/guide/returns',
  '/ar/guide/fees': '/guide/fees',
  '/ar/guide/equipment': '/guide/equipment',
  '/ar/guide/guests': '/guide/guests',
  '/ar/guide/web-orders': '/guide/web-orders',
  '/ar/guide/kitchen': '/guide/kitchen',
  '/ar/guide/labels': '/guide/labels',
  '/ar/guide/faq': '/guide/faq',
  '/ar/guide/products': '/admin/products',
  '/ar/guide/variants': '/admin/variants',
  '/ar/guide/order-types': '/admin/order-types',
  '/ar/guide/inventory': '/admin/inventory',
  '/ar/guide/expenses-revenues': '/admin/expenses-revenues',
  '/ar/guide/customers-delivery': '/admin/customers-delivery',
  '/ar/guide/reports': '/admin/reports',
  '/ar/guide/settings': '/admin/settings',
  '/ar/guide/users': '/admin/users',
  '/ar/guide/ai-assistant': '/admin/ai-assistant',
  '/ar/guide/website': '/integrations/website',
  '/technical': '/dev/',
  '/technical/index': '/dev/',
  '/technical/setup': '/dev/setup',
  '/technical/architecture': '/dev/architecture',
  '/technical/modules': '/dev/modules',
  '/technical/orders-and-events': '/dev/orders-and-events',
  '/technical/realtime': '/dev/realtime',
  '/technical/money': '/dev/money',
  '/technical/vendor': '/dev/vendor',
  '/technical/settings': '/dev/settings',
  '/technical/permissions': '/dev/permissions',
  '/technical/commands': '/dev/commands',
  '/technical/branches': '/dev/branches',
  '/technical/printing': '/dev/printing',
  '/technical/ai-mcp': '/dev/ai-mcp',
  '/technical/testing': '/dev/testing',
  '/technical/website-integration': '/dev/website-integration',
  '/technical/platform-sync': '/dev/platform-sync',
  '/technical/deployment': '/dev/ops/deployment',
}

const rootNav: DefaultTheme.NavItem[] = [
  { text: 'البداية', link: '/start/', activeMatch: '^/start/' },
  { text: 'دليل الاستخدام', link: '/guide/cashier', activeMatch: '^/guide/' },
  { text: 'الإدارة', link: '/admin/products', activeMatch: '^/admin/' },
  { text: 'التكامل', link: '/integrations/website', activeMatch: '^/integrations/' },
  ...(isPublic ? [] : [{ text: 'Developer', link: '/dev/', activeMatch: '^/dev/' }]),
  productSwitcher('pos', productUrls),
]

export default withMermaid(
  defineConfig({
    title: 'Turbo POS',
    description: 'دليل استخدام نظام Turbo POS ووثائق المطورين',
    cleanUrls: true,
    lastUpdated: true,
    srcExclude: isPublic ? ['dev/**'] : [],
    head: [
      ['link', { rel: 'icon', type: 'image/svg+xml', href: '/logo.svg' }],
      ['style', {}, ':root { --brand: #0f766e; }'],
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
      isPublic,
      redirects,
      search: {
        provider: 'local',
        options: {
          locales: {
            root: {
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
    } as DefaultTheme.Config & { isPublic: boolean; redirects: Record<string, string> },
    locales: {
      root: {
        label: 'العربية',
        lang: 'ar',
        dir: 'rtl',
        themeConfig: {
          nav: rootNav,
          sidebar: arabicSidebar,
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
      ...(isPublic
        ? {}
        : {
            dev: {
              label: 'Developer (English)',
              lang: 'en',
              dir: 'ltr',
              link: '/dev/',
              themeConfig: {
                nav: [
                  { text: 'Handbook', link: '/dev/', activeMatch: '^/dev/(?!ops/)' },
                  { text: 'Operations', link: '/dev/ops/deployment', activeMatch: '^/dev/ops/' },
                  { text: 'Website API', link: '/dev/website-integration' },
                  { text: 'Style guide', link: '/dev/style-guide' },
                  { text: 'دليل المستخدم', link: '/' },
                  productSwitcher('pos', productUrls),
                ],
                sidebar: { '/dev/': devSidebar },
                outline: { level: [2, 3], label: 'On this page' },
                docFooter: { prev: 'Previous', next: 'Next' },
                lastUpdated: { text: 'Last updated' },
                footer: {
                  message: 'Turbo POS developer handbook. End-user Arabic guide lives at /.',
                },
              },
            },
          }),
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
    vite: {
      css: { postcss: {} },
      build: { chunkSizeWarningLimit: 4000 },
    },
  }),
)
