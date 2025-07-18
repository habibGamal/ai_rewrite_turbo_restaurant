import { UploadOutlined } from '@ant-design/icons'
import { App, Button, Row, Upload, UploadProps } from 'antd/es'
import { DocumentDownload } from 'iconsax-react'
import PageTitle from '../PageTitle.js'
import { useState } from 'react'
import { router, usePage } from '@inertiajs/react'
import { Props } from '~/pages/RenderModel.js'
import { PageProps } from '@adonisjs/inertia/types'

export default function Header() {
  const { title, importRoute } = usePage().props as unknown as Props & PageProps
  const [disableExport, setDisableExport] = useState(false)
  const exportCSV = (e: React.MouseEvent<HTMLElement, MouseEvent>) => {

    e.preventDefault()
    router.reload({
      only: ['exportCSV'],
      preserveState: true,
      onStart: () => setDisableExport(true),
      onFinish: () => setDisableExport(false),
      onSuccess: (page) => {
        const { path } = page.props.exportCSV as { success: boolean; path?: string }
        if (!path) return
        const a = document.createElement('a')
        a.href = '/exports/' + path
        a.download = `${path}.csv`
        a.click()
      },
    })
  }

  const { message } = App.useApp()

  const props: UploadProps = {
    name: 'file',
    action: '/categories/import-from-excel',
    maxCount: 1,
    onChange(info) {
      router.post(importRoute!, {
        excelFile: info.file.originFileObj,
      })
      if (info.file.status !== 'uploading') {

      }
      if (info.file.status === 'done') {
        message.success(`${info.file.name} file uploaded successfully`)
      } else if (info.file.status === 'error') {
        message.error(`${info.file.name} file upload failed.`)
      }
    },
  }
  return (
    <Row justify="space-between" className="w-full">
      <PageTitle name={title} />
      <div className="flex gap-4">
        <Button
          disabled={disableExport}
          onClick={exportCSV}
          className="flex items-center"
          icon={<DocumentDownload size="18" />}
        >
          استخراج CSV
        </Button>
        {importRoute && (
          <Upload {...props}>
            <Button icon={<UploadOutlined />}>ادراج Excel</Button>
          </Upload>
        )}
      </div>
    </Row>
  )
}
