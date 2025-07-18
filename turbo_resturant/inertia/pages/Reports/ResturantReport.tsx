import { router } from '@inertiajs/react'
import { Col, message, Modal, Row, Table, Typography } from 'antd'
import { ArrowRotateRight, Box, Money, ReceiptText } from 'iconsax-react'
import { useEffect, useState } from 'react'
import useModal from '~/hooks/useModal.js'
import ResturantRService, { Data } from '~/services/Reports/ResturantRService.js'
import EmptyReport from '../../components/EmptyReport.js'
import ReportCard from '../../components/ReportCard.js'
import ReportHeader from '../../components/ReportHeader.js'
import { Product } from '../../types/Models.js'
import useSimpleTableSearch from '~/hooks/useSimpleTableSearch.js'
import SimpleTableSearch from '~/components/SimpleTableSearch.js'
type RecipeRecord = { name: string; quantity: number; recipeQuantity: number; productId: string }
type Attribute = 'name'
export default function ResturantReport(data: Data) {
  useEffect(() => {
    if (data.notes) message.info(data.notes)
  }, [data])
  const actions = {
    showDetails: (product: Product) => showDetails(product),
  }
  const service = new ResturantRService(data)
  const serviceUi = service.serviceUi(actions)

  const showDetails = (product: Product) => {
    setDetailsData({
      productName: product.name,
      data: service.productDetails(product),
    })
    detailsModal.showModal()
  }

  const getResults = (from: string, to: string) => {
    router.get(`/reports/stock-report`, {
      from,
      to,
    })
  }

  const options: { label: string; value: Attribute }[] = [{ label: 'الاسم', value: 'name' }]

  const {
    data: dataSource,
    setAttribute,
    onSearch,
  } = useSimpleTableSearch({ dataSource: service.dataSource, options })

  const detailsModal = useModal()
  const [detailsData, setDetailsData] = useState<{
    productName: string
    data: RecipeRecord[]
  } | null>(null)

  return (
    <Row gutter={[0, 25]} className="m-8">
      <Modal
        className="!w-[90%]"
        title={detailsData?.productName}
        destroyOnClose
        {...detailsModal}
        footer={null}
      >
        <h1>
          الاجمالي :{' '}
          {detailsData?.data
            .reduce((acc, item) => acc + item.quantity * item.recipeQuantity, 0)
            .toFixed(2)}
        </h1>
        <div className="isolate">
          <Table
            columns={serviceUi.detailsColumns}
            dataSource={detailsData?.data}
            pagination={false}
          />
        </div>
      </Modal>
      <ReportHeader
        title="تقرير المخزون"
        getResults={getResults}
        columns={serviceUi.columns.filter((col) => col.key !== 'showDetails')}
        dataSource={service.dataSource}
      />
      <EmptyReport condition={service.dataSource.length === 0}>
        <Typography.Text>
          من {data.startDate} إلى {data.endDate}
        </Typography.Text>
        <Col span="24">
          <div className="grid gap-8 grid-cols-4">
            <ReportCard
              title={<>{service.startBalance.toFixed(2)} EGP</>}
              mainText={<>الرصيد الابتدائي</>}
              secondaryText={<></>}
              icon={<Money className="text-sky-600" />}
              color="bg-sky-200"
            />
            <ReportCard
              title={<>{service.endBalance.toFixed(2)} EGP</>}
              mainText={<>الرصيد النهائي</>}
              secondaryText={<></>}
              icon={<Money className="text-blue-600" />}
              color="bg-blue-200"
            />
            <ReportCard
              title={<>{(service.wastes + service.returns).toFixed(2)} EGP</>}
              mainText={<>الفاقد والمرتجع</>}
              secondaryText={<></>}
              icon={<ArrowRotateRight className="text-red-600" />}
              color="bg-red-200"
            />
            <ReportCard
              title={<>{service.purchases.toFixed(2)} EGP</>}
              mainText={<>المشتريات</>}
              secondaryText={<></>}
              icon={<Box className="text-yellow-600" />}
              color="bg-yellow-200"
            />
            <ReportCard
              title={<>{service.sales.toFixed(2)} EGP</>}
              mainText={<>المبيعات</>}
              secondaryText={<></>}
              icon={<ReceiptText className="text-green-600" />}
              color="bg-green-200"
            />
          </div>
        </Col>
        <Col span="24" className="isolate">
          <SimpleTableSearch<Attribute>
            options={options}
            onSearch={onSearch}
            setAttribute={setAttribute}
          />
          <Table columns={serviceUi.columns} dataSource={dataSource} scroll={{ y: 600 }} />
        </Col>
      </EmptyReport>
    </Row>
  )
}
