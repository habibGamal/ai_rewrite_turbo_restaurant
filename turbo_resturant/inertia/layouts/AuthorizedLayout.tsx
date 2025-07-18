import {
  DeliveredProcedureOutlined,
  InboxOutlined,
  LockOutlined,
  UnlockOutlined,
} from '@ant-design/icons'
import { router, usePage } from '@inertiajs/react'
import { Col, FloatButton, Modal, QRCode, Row } from 'antd'
import React from 'react'
import Navbar from '../components/Navbar.js'
import useModal from '../hooks/useModal.js'
import ThemeLayer from './ThemeLayer.js'

export default function AuthorizedLayout(props: { children: JSX.Element }) {
  const qrNewScannerModal = useModal()
  const [qrScannerCode, setQrScannerCode] = React.useState<string | null>(null)
  const requestScannerCode = async () => {
    qrNewScannerModal.showModal()
    const response = await fetch('/get-scanner-code', {
      headers: {
        'Content-Type': 'application/json',
      },
    })
    const data = await response.json()

    setQrScannerCode(JSON.stringify(data))
  }
  const openCashierDrawer = () => {
    router.get('/open-drawer')
  }
  const openDay = () => {
    router.get('/snapshot/open-day')
  }
  const startAccounting = () => {
    router.get('/snapshot/start-accounting')
  }
  const closeDay = () => {
    router.get('/snapshot/close-day')
  }
  const { dayIsOpen, allowStartAccounting } = usePage().props

  return (
    <ThemeLayer>
      <Row wrap={false}>
        <Modal
          title="QR Code Scanner"
          open={qrNewScannerModal.open}
          onOk={qrNewScannerModal.onOK}
          onCancel={qrNewScannerModal.closeModal}
        >
          <QRCode
            className="mx-auto my-8"
            value={qrScannerCode || ''}
            status={qrScannerCode ? 'active' : 'loading'}
          />
        </Modal>
        <Col>
          <Navbar />
        </Col>
        <Col flex="auto">{props.children}</Col>
        <FloatButton.Group shape="circle" style={{ left: 24 }}>
          <FloatButton
            tooltip="فتح خزينة الكاشير"
            icon={<InboxOutlined />}
            onClick={() => openCashierDrawer()}
          />
          {dayIsOpen ? (
            <FloatButton
              tooltip="اغلاق اليوم"
              icon={<UnlockOutlined />}
              onClick={() => closeDay()}
            />
          ) : (
            <FloatButton tooltip="فتح يوم" icon={<LockOutlined />} onClick={() => openDay()} />
          )}
          {(allowStartAccounting as boolean) && (
            <FloatButton
              tooltip="حفظ مستويات المخزن كرصيد افتتاحي"
              icon={<DeliveredProcedureOutlined />}
              onClick={() => startAccounting()}
            />
          )}
        </FloatButton.Group>
      </Row>
    </ThemeLayer>
  )
}
