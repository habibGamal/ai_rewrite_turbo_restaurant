import { router } from '@inertiajs/react'
import { Col, Row, Table } from 'antd'
import SimpleTableSearch from '~/components/SimpleTableSearch.js'
import useSimpleTableSearch from '~/hooks/useSimpleTableSearch.js'
import ClientsRService from '~/services/Reports/ClientsRService.js'
import EmptyReport from '../../components/EmptyReport.js'
import ReportCard from '../../components/ReportCard.js'
import ReportHeader from '../../components/ReportHeader.js'
import { Customer } from '../../types/Models.js'
import { useMemo } from 'react'
type Attribute = 'name' | 'phone'
export default function ClientsReport({ customers }: { customers: Customer[] }) {
  const { columns, dataSource, cardsData } = useMemo(
    () => new ClientsRService(customers),
    [customers]
  )
  const getResults = (from: string, to: string) => {
    router.get(`/reports/clients-report`, {
      from,
      to,
    })
  }

  const options: { label: string; value: Attribute }[] = [
    { label: 'الاسم', value: 'name' },
    { label: 'رقم الهاتف', value: 'phone' },
  ]

  const { data, setAttribute, onSearch } = useSimpleTableSearch({ dataSource, options })

  return (
    <Row gutter={[0, 25]} className="m-8">
      <ReportHeader
        title="تقرير العملاء"
        getResults={getResults}
        columns={columns}
        dataSource={dataSource}
      />
      <EmptyReport condition={dataSource.length === 0}>
        <Col span="24">
          <div className="lg-cards-grid gap-8">
            {cardsData.map((card, index) => (
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
            options={options}
            onSearch={onSearch}
            setAttribute={setAttribute}
          />
          <Table columns={columns} dataSource={data} scroll={{ x: true }} />
        </Col>
      </EmptyReport>
    </Row>
  )
}
