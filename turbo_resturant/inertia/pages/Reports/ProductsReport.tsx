import { router } from '@inertiajs/react'
import { Col, Row, Table } from 'antd'
import SimpleTableSearch from '~/components/SimpleTableSearch.js'
import useSimpleTableSearch from '~/hooks/useSimpleTableSearch.js'
import ProductsRService from '~/services/Reports/ProductsRService.js'
import EmptyReport from '../../components/EmptyReport.js'
import ReportCard from '../../components/ReportCard.js'
import ReportHeader from '../../components/ReportHeader.js'
import { Product } from '../../types/Models.js'
import { useMemo } from 'react'
type Attribute = 'name'

export default function ProductsReport({ products }: { products: Product[] }) {
  const service = useMemo(() => new ProductsRService(products), [products])
  const serviceUi = service.serviceUi()

  const getResults = (from: string, to: string) => {
    router.get(`/reports/products-report`, {
      from,
      to,
    })
  }
  const options: {
    label: string
    value: Attribute
  }[] = [
    {
      label: 'الاسم',
      value: 'name',
    },
  ]
  const { data, setAttribute, onSearch } = useSimpleTableSearch<Attribute>({
    dataSource: service.dataSource,
    options,
  })
  return (
    <Row gutter={[0, 25]} className="m-8">
      <ReportHeader
        title="تقرير المنتجات"
        getResults={getResults}
        columns={serviceUi.columns}
        dataSource={service.dataSource}
      />
      <EmptyReport condition={service.dataSource.length === 0}>
        <Col span="24">
          <div className="lg-cards-grid">
            {serviceUi.cardsData.map((card, index) => (
              <ReportCard
                key={index}
                title={card.title}
                mainText={card.mainText}
                secondaryText={card.secondaryText}
                icon={card.icon}
                color={card.color}
              />
            ))}
          </div>
        </Col>
        <Col span="24" className="isolate">
          <SimpleTableSearch<Attribute>
            data={data}
            setAttribute={setAttribute}
            onSearch={onSearch}
          />
          <Table columns={serviceUi.columns} dataSource={data} scroll={{ x: true }} />
        </Col>
      </EmptyReport>
    </Row>
  )
}
