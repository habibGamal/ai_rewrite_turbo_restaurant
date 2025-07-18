import { Excel } from 'antd-table-saveas-excel'
import { Button, Col, Descriptions, Row, Table } from 'antd/es'
import { DocumentDownload } from 'iconsax-react'
import { useRef } from 'react'
import PageTitle from '../../components/PageTitle.js'
import { Waste } from '../../types/Models.js'

const columns = [
  {
    title: 'أسم الصنف',
    dataIndex: 'name',
    key: 'name',
  },
  {
    title: 'تكلفة الوحدة',
    dataIndex: 'cost',
    key: 'cost',
  },
  {
    title: 'الكمية الهالكة',
    dataIndex: 'quantity',
    key: 'quantity',
  },
  {
    title: 'فرق النقدية',
    dataIndex: 'total',
    key: 'total',
    render: (text: any) => text.toFixed(2),
  },
]
const remapData = (waste: Waste) =>
  waste.items.map((item) => ({
    name: item.product.name,
    cost: item.cost,
    quantity: item.quantity,
    total: item.total,
  }))

export default function Show({ waste }: { waste: Waste }) {

  const dataSource = remapData(waste)
  const componentRef = useRef()
  return (
    <Row gutter={[0, 25]} className="m-8" ref={componentRef}>
      <div className="flex justify-between w-full">
        <PageTitle name="عرض الهالك" />
        <Button
          onClick={() => {
            const excel = new Excel()
            excel
              .addSheet('export')
              .addColumns(columns)
              .addDataSource(dataSource)
              .saveAs(`wastes_no_${waste.id}.xlsx`)
          }}
          className="flex items-center"
          icon={<DocumentDownload size="18" />}
        >
          استخراج csv
        </Button>
      </div>
      <Col span="24" className="isolate-2">
        <Descriptions className="w-full" bordered>
          <Descriptions.Item label="رقم الهالك">{waste.id}</Descriptions.Item>
          <Descriptions.Item label="محصلة الهالك">{waste.total}</Descriptions.Item>
          <Descriptions.Item label="تاريخ الهالك">{waste.createdAt}</Descriptions.Item>
        </Descriptions>
      </Col>
      <Col span="24" className="isolate">
        <Table columns={columns} dataSource={dataSource} pagination={false} />
      </Col>
    </Row>
  )
}
