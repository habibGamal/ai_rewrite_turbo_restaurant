import { Col, List, Row, Timeline, Typography } from 'antd'
import React from 'react'
import PageTitle from '~/components/PageTitle'
import ReportHeader from '~/components/ReportHeader'
import { Shift } from '~/types/Models'
import dayjs from 'dayjs'

interface LogEntry {
  level: number
  time: string
  pid: number
  hostname: string
  user?: string
  name?: string
  action?: string
  actions?: string[]
}

export default function LogsReport({ shift, logs }: { shift: Shift; logs: string }) {
  const logLines = logs.split('\n')
  const logEntries: LogEntry | null[] = logLines.map((line) => {
    try {
      return JSON.parse(line)
    } catch (error) {
      console.log(line)
      return null
    }
  })
  const logsCleaned = logEntries.filter((entry) => entry !== null) as LogEntry[]
  return (
    <Row gutter={[0, 25]} className="m-8">
      <PageTitle name={`سجلات وردية رقم ${shift.id} بتاريخ ${shift.startAt}`} />
      <Col span="24" className="isolate">
        <List
          dataSource={logsCleaned}
          renderItem={(item) => {
            return (
              <List.Item>
                {item.actions ? (
                  <div>
                    <Typography.Text type="secondary" className="ml-2">
                      {dayjs(item.time).format('hh:mm:ss A')}
                    </Typography.Text>
                    <ul>
                      {item.actions.map((action, index) => (
                        <li key={index}>
                          {action.includes('حذف') ? (
                            <Typography.Text type="danger">{action}</Typography.Text>
                          ) : (
                            <Typography.Text>{action}</Typography.Text>
                          )}
                        </li>
                      ))}
                    </ul>
                  </div>
                ) : (
                  <Typography.Text>
                    <Typography.Text type="secondary" className="ml-2">
                      {dayjs(item.time).format('hh:mm:ss A')}
                    </Typography.Text>
                    قام {item.user} بـ {item.action}
                  </Typography.Text>
                )}
                {/* {item.actions && (
                  <Timeline>
                    {item.actions.map((action, index) => (
                      <Timeline.Item key={index}>
                        <Typography.Text>{action}</Typography.Text>
                      </Timeline.Item>
                    ))}
                  </Timeline>
                )} */}
              </List.Item>
            )
          }}
          // items={logsCleaned.map((entry) => {
          //   return {
          //     label: !entry.actions && dayjs(entry.time).format('hh:mm:ss A'),
          //     color: entry.level === 30 ? 'green' : entry.level === 40 ? 'red' : 'blue',
          //     children: entry.actions ? (
          //       <Timeline
          //        items={entry.actions.map((action, index) => ({
          //         children: <Typography.Text>{action}</Typography.Text>,
          //        }))}
          //       />
          //     ) : (
          //       <div>
          //         <Typography.Text>
          //           قام {entry.user} بـ {entry.action}
          //         </Typography.Text>
          //       </div>
          //     ),
          //   }
          // })}
        />
      </Col>
    </Row>
  )
}
