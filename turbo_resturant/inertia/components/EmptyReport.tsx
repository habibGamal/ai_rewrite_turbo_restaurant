import { Empty } from 'antd'
import React from 'react'

export default function EmptyReport({
  condition,
  children,
}: {
  condition: boolean
  children: React.ReactNode
}) {
  if (condition)
    return (
      <div className="isolate w-64 aspect-square grid place-items-center mx-auto my-24">
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />
      </div>
    )
  else return <>{children}</>
}
