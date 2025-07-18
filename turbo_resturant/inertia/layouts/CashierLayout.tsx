import { InboxOutlined, LockOutlined } from '@ant-design/icons'
import { router, usePage } from '@inertiajs/react'
import { Col, FloatButton, Row } from 'antd'
import { ArrangeHorizontalCircle, LogoutCurve } from 'iconsax-react'
import IsAdmin from '../components/IsAdmin.js'
import ThemeLayer from './ThemeLayer.js'

export default function CashierLayout(props: { children: JSX.Element }) {
  const logout = () => {
    router.get('/logout')
  }

  const openCashierDrawer = () => {
    router.get('/open-drawer')
  }

  const toAdmin = () => {
    router.get('/raw-products')
  }
  const { dayIsOpen } = usePage().props

  const openDay = () => {
    router.get('/snapshot/open-day')
  }

  return (
    <ThemeLayer>
      <Row wrap={false}>
        <Col flex="auto">{props.children}</Col>

        <FloatButton.Group shape="circle" style={{ left: 24 }}>
          <IsAdmin>
            <FloatButton
              tooltip="الادارة"
              icon={<ArrangeHorizontalCircle size={21} />}
              onClick={() => toAdmin()}
            />
          </IsAdmin>
          {!dayIsOpen && (
            <FloatButton tooltip="فتح يوم" icon={<LockOutlined />} onClick={() => openDay()} />
          )}
          <FloatButton icon={<InboxOutlined />} onClick={() => openCashierDrawer()} />
          <FloatButton icon={<LogoutCurve />} onClick={logout} />
        </FloatButton.Group>
      </Row>
    </ThemeLayer>
  )
}
