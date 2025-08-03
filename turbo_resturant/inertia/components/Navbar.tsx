import { NodeType } from '#enums/SettingsEnums'
import { MenuFoldOutlined, MenuUnfoldOutlined } from '@ant-design/icons'
import { Link } from '@inertiajs/react'
import { Button, Menu, MenuProps } from 'antd'
import React, { useRef, useState } from 'react'
import { masterRoutes, navRoutes, slaveRoutes } from '~/config'
import useNodeType from '~/hooks/useNodeType'
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

const DEFALUT_SELECTED_KEY = ['products']

const Navbar = () => {
  const nodeType = useNodeType()
  const items: MenuProps['items'] = navRoutes[nodeType].map((route) => {
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
