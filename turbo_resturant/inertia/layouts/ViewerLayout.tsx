import { Link, router } from '@inertiajs/react'
import { Col, ConfigProvider, FloatButton, Menu, MenuProps, Row } from 'antd'
import { Chart, LogoutCurve } from 'iconsax-react'
import React from 'react'
import ThemeLayer from './ThemeLayer.js'
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
const routes: {
  name: React.ReactNode
  id: string
  icon?: React.JSX.Element
  route?: string
  children?: {
    name: string
    id: string
    route?: string
  }[]
}[] = [
  {
    name:<img src="/images/logo.jpg" className="h-10 mx-auto block rounded-full" alt="logo" />,
    id:'logo',
    route:'/reports/current-shift-report'
  },
  {
    name: 'التقارير',
    id: 'viewer.repoting',
    icon: <Chart />,
    children: [
      {
        name: 'تقرير اليوم',
        id: 'viewer.current_shift_report',
        route: '/reports/current-shift-report',
      },
      {
        name: 'تقرير شامل للورديات',
        id: 'viewer.full_shifts_report',
        route: '/reports/full-shifts-report',
      },
      {
        name: 'تقارير العملاء',
        id: 'viewer.clients_reports',
        route: '/reports/clients-report',
      },
      {
        name: 'تقارير المنتجات',
        id: 'viewer.products_reports',
        route: '/reports/products-report',
      },
    ],
  },
]
export default function ViewerLayout(props: { children: JSX.Element }) {
  const logout = () => {
    router.get('/logout')
  }
  const items: MenuProps['items'] = routes.map((route) => {
    if (route.children) {
      return getItem(
        route.name,
        route.id,
        route.icon,
        route.children.map((child) => {
          return getItem(
            <Link
              className="block min-w-fit"
              href={(child.route) || '#'}
            >
              {child.name}
            </Link>,
            child.id
          )
        })
        // 'group'
      )
    }
    return getItem(
      <Link href={(route.route) || '#'}>{route.name}</Link>,
      route.id,
      route.icon
    )
  })
  return (
    <ThemeLayer>
      <ConfigProvider
        theme={{
          token: {
            fontSize: 16,
          },
        }}
      >
        <Menu className='py-4' mode="horizontal" items={items} />
        <Row wrap={false}>
          <Col flex="auto">{props.children}</Col>

          <FloatButton.Group shape="circle" style={{ left: 24 }}>
            <FloatButton icon={<LogoutCurve />} onClick={logout} />
          </FloatButton.Group>
        </Row>
      </ConfigProvider>
    </ThemeLayer>
  )
}
