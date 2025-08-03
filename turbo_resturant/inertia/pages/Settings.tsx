import { NodeType, SettingKeys } from '#enums/SettingsEnums'
import { router } from '@inertiajs/react'
import {
  Button,
  Col,
  Input,
  Modal,
  QRCode,
  Radio,
  Row,
  Select,
  Space,
  Switch,
  Tooltip,
  Typography,
} from 'antd'
import { InfoCircle } from 'iconsax-react'
import { useContext, useEffect, useMemo, useState } from 'react'
import { io } from 'socket.io-client'
import testPrinter from '~/helpers/testPrinter.js'
import PageTitle from '../components/PageTitle.js'
import useModal from '../hooks/useModal.js'
import { themeToggler } from '../layouts/ThemeLayer.js'

export default function Settings({ settings }: { settings: { [key: string]: string } }) {
  const theme = useContext(themeToggler)
  const qrLinkToPhone = useModal()
  const socket = useMemo(
    () =>
      io({
        autoConnect: false,
      }),
    []
  )

  useEffect(() => {
    socket.connect()
    const onReceiveInterfaces = (data: { name: string; address: string }[]) => {
      setSocketData(() => ({
        printers: [],
        scanProgress: null,
        interfaces: data,
      }))
    }
    const onReceivePrinters = (data: string[]) => {
      setSocketData((prev) => ({ ...prev, printers: data }))
    }
    const onScanProgress = (data: string) => {
      setSocketData((prev) => ({ ...prev, scanProgress: data }))
    }
    socket.on('interfaces', onReceiveInterfaces)
    socket.on('printers', onReceivePrinters)
    socket.on('scan progress', onScanProgress)
    return () => {
      socket.off('interfaces', onReceiveInterfaces)
      socket.off('printers', onReceivePrinters)
      socket.off('scan progress', onScanProgress)
      socket.disconnect()
    }
  }, [])

  const [socketData, setSocketData] = useState<{
    interfaces: { name: string; address: string }[]
    printers: string[]
    scanProgress: string | null
  }>({
    interfaces: [],
    printers: [],
    scanProgress: null,
  })

  const darkTheme = (checked: boolean) => {
    theme?.toggleTheme(checked ? 'dark' : 'light')
  }
  return (
    <Row gutter={[0, 25]} className="m-8">
      <PageTitle name="الاعدادات" />
      <Col span="24" className="isolate">
        <div className="grid gap-8 grid-cols-1 xl:grid-cols-2 items-start">
          <div className="flex gap-4">
            <Typography.Text>تفعيل الوضع المظلم</Typography.Text>
            <Switch defaultChecked={theme?.currentTheme === 'dark'} onChange={darkTheme} />
          </div>
          <div className="flex gap-4 items-center">
            <Typography.Text>ربط النظام بالهاتف</Typography.Text>
            <Button type="primary" onClick={() => qrLinkToPhone.showModal()}>
              عرض QR
            </Button>
          </div>
          <div className="isolate-0 flex flex-col gap-4">
            <SettingInput
              route={SettingKeys.NodeType}
              title="نوع النقطة"
              name={SettingKeys.NodeType}
              defaultValue={settings?.[SettingKeys.NodeType]}
              select
              options={[
                { label: 'رئيسي', value: NodeType.Master },
                { label: 'فرع', value: NodeType.Slave },
                { label: 'مستقل', value: NodeType.Standalone },
              ]}
            />
            <SettingInput
              route={SettingKeys.MasterLink}
              title="رابط النقطة الرئيسية"
              name={SettingKeys.MasterLink}
              tooltip="format: https://localsys.turboplus.online"
              defaultValue={settings?.[SettingKeys.MasterLink]}
            />
            <SettingInput
              route={SettingKeys.WebsiteLink}
              title="رابط الموقع"
              name={SettingKeys.WebsiteLink}
              tooltip="format: https://localsys.turboplus.online"
              defaultValue={settings?.[SettingKeys.WebsiteLink]}
            />
          </div>
          <div className="flex gap-4 items-center">
            <SettingInput
              route="casheir-printer"
              title="طابعة الكاشير"
              name="cashierPrinter"
              defaultValue={settings?.cashierPrinter}
            />
            <Button
              onClick={() => {
                testPrinter(settings?.cashierPrinter)
              }}
            >
              اختبار الطابعة
            </Button>
          </div>
          <SettingInput
            route={SettingKeys.ReceiptFooter}
            title="بيان الطباعة"
            name={SettingKeys.ReceiptFooter}
            defaultValue={settings?.[SettingKeys.ReceiptFooter]}
            textArea
            tooltip="هذا البيان يتم طباعته في نهاية الفاتورة"
          />
          <div className="isolate-0 grid gird-cols-2 gap-4">
            <Typography.Text>البحث عن الطابعات</Typography.Text>
            <Typography.Text>{socketData.scanProgress}</Typography.Text>

            {socketData.scanProgress === null && (
              <Radio.Group>
                <Space direction="vertical">
                  {socketData.interfaces.map((inter) => (
                    <Radio.Button
                      onClick={() => {
                        socket.emit('scan for printers', { address: inter.address })
                      }}
                      value={inter.address}
                    >
                      {inter.name}
                    </Radio.Button>
                  ))}
                </Space>
              </Radio.Group>
            )}
            <Space direction="vertical">
              {socketData.printers.map((printer) => (
                <div className="flex items-center gap-4">
                  <Typography.Text copyable>{printer}</Typography.Text>
                  <Button
                    onClick={() => {
                      testPrinter('tcp://' + printer)
                    }}
                    value={printer}
                  >
                    اختبار
                  </Button>
                </div>
              ))}
            </Space>
            <div className="flex gap-4 justify-center">
              <Button
                type="primary"
                onClick={() => {
                  socket.emit('scan for interfaces')
                }}
              >
                بدء البحث
              </Button>
              <Button
                type="primary"
                danger
                onClick={() => {
                  socket.emit('stop scan for printers')
                }}
              >
                إيقاف البحث
              </Button>
            </div>
          </div>
        </div>
      </Col>
      <Modal
        title="QR Code Scanner"
        open={qrLinkToPhone.open}
        onOk={qrLinkToPhone.onOK}
        onCancel={qrLinkToPhone.closeModal}
      >
        <QRCode className="mx-auto my-8" value={settings?.dns} />
      </Modal>
    </Row>
  )
}

const SettingInput = ({
  route,
  title,
  name,
  defaultValue,
  textArea,
  select,
  options,
  tooltip,
}: {
  route: string
  title: string
  name: string
  defaultValue: string
  textArea?: boolean
  select?: boolean
  options?: { label: string; value: string }[]
  tooltip?: string
}) => {
  const [loading, setLoading] = useState(false)
  const update = () => {
    setLoading(true)
    router.post(
      '/settings/' + route,
      {
        [name]: value,
      },
      {
        onSuccess: () => setLoading(false),
      }
    )
  }
  const [value, setValue] = useState(defaultValue)

  if (select)
    return (
      <div className="flex flex-wrap gap-4 items-center">
        <Typography.Text>
          {title}
          {tooltip && (
            <Tooltip title={tooltip}>
              <InfoCircle className="align-bottom mx-2" />
            </Tooltip>
          )}
        </Typography.Text>
        <Select
          value={value}
          options={options}
          className="min-w-[300px]"
          onChange={(value) => {
            setValue(value)
          }}
        />
        <Button loading={loading} onClick={update} type="primary">
          تحديث
        </Button>
      </div>
    )

  if (textArea)
    return (
      <div className="isolate-0 flex flex-wrap gap-4 items-center">
        <Typography.Text>
          {title}
          {tooltip && (
            <Tooltip title={tooltip}>
              <InfoCircle className="align-bottom mx-2" />
            </Tooltip>
          )}
        </Typography.Text>
        <Input.TextArea
          value={value}
          className="max-w-[300px]"
          onChange={(e) => {
            setValue(e.target.value)
          }}
        />
        <Button loading={loading} onClick={update} type="primary">
          تحديث
        </Button>
      </div>
    )
  return (
    <div className="flex gap-4 items-center">
      <Typography.Text className="text-nowrap">
        {title}
        {tooltip && (
          <Tooltip title={tooltip}>
            <InfoCircle className="align-bottom mx-2" />
          </Tooltip>
        )}
      </Typography.Text>
      <Input
        value={value}
        className="max-w-[300px]"
        onChange={(e) => {
          setValue(e.target.value)
        }}
      />
      <Button loading={loading} onClick={update} type="primary">
        تحديث
      </Button>
    </div>
  )
}
