import { MenuFoldOutlined, MenuUnfoldOutlined } from '@ant-design/icons'
import { Link } from '@inertiajs/react'
import { Button, Menu, MenuProps } from 'antd'
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
import React, { useRef, useState } from 'react'
type MenuItem = Required<MenuProps>['items'][number]

function getItem(
  label: React.ReactNode,
  key: React.Key,
  icon?: React.ReactNode,
  children?: MenuItem[],
  type?: 'group'
): MenuItem {
  return {
    key,
    icon,
    children,
    label,
    type,
  } as MenuItem
}

// make type that containes all the keys of the items manually
export type MenuKeys =
  | 'add_products'
  | 'product_groups'
  | 'product_details'
  | 'expired_products'
  | 'openning_stock'
  | 'add_stock'
  | 'tracking_stocks'
  | 'stock_waste'
  | 'stock_transfer'
  | 'supporters'
  | 'invoices'
  | 'accounting'
  | 'repoting'
  | 'managment'
  | 'notifications'
  | 'logout'

const routes: {
  name: React.ReactNode
  id: string
  icon: React.JSX.Element
  route?: string
  children?: {
    name: string
    id: string
    route?: string
  }[]
}[] = [
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

const DEFALUT_SELECTED_KEY = ['products']

const Navbar = () => {
  const items: MenuProps['items'] = routes.map((route) => {
    if (route.children) {
      return getItem(
        route.name,
        route.id,
        route.icon,
        route.children.map((child) => {
          return getItem(
            <Link className="block min-w-fit" href={(child.route && child.route) || '#'}>
              {child.name}
            </Link>,
            child.id
          )
        })
        // 'group'
      )
    }
    return getItem(
      <Link href={(route.route && route.route) || '#'}>{route.name}</Link>,
      route.id,
      route.icon
    )
  })
  const onClick: MenuProps['onClick'] = (e) => {}
  const [collapsed, setCollapsed] = useState(true)
  const menu = useRef<HTMLDivElement>(null)
  const toggleCollapsed = () => {
    setCollapsed(!collapsed)
  }
  const logoStyle = collapsed ? 'nav-collapsed' : 'nav-non-collapsed'
  return (
    <div
      ref={menu}
      className="min-h-screen border-l sticky top-0 border-[#e5e7eb] dark:border-dark-700 bg-white dark:bg-dark-900"
    >
      <div className="grid place-items-center cursor-pointer">
        <div
          className={`aspect-square my-16 ${logoStyle} transition-all overflow-hidden rounded-full border-0 bg-white grid place-items-center`}
        >
          <img className="w-full object-cover object-center" src="/images/logo.jpg" />
        </div>
      </div>
      <Menu
        onClick={onClick}
        defaultSelectedKeys={DEFALUT_SELECTED_KEY}
        mode="inline"
        items={items}
        style={{ borderInlineEnd: 'none' }}
        inlineCollapsed={collapsed}
      />
      <Button
        type="primary"
        onClick={toggleCollapsed}
        className="absolute bottom-4 left-0 w-full rounded-none"
      >
        {collapsed ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}
      </Button>
    </div>
  )
}

export default Navbar
