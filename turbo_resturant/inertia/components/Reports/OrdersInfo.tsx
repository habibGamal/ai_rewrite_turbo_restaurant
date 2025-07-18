import React from 'react'
import ReportCard from '../ReportCard.js'
import { Divider } from 'antd'

export default function OrdersInfo({
  count,
  mainText,
  value,
  profit,
  icon,
  color,
  onClick,
}: {
  count: number
  mainText: string
  value: number
  profit: number
  icon: React.ReactNode
  color: string
  onClick?: () => void
}) {
  return (
    <ReportCard
      title={
        <>
          {count}
          <Divider type="vertical" className="mx-4" />
          اوردر
        </>
      }
      mainText={mainText}
      secondaryText={
        <>
          بقيمة
          <Divider type="vertical" className="mx-4" />
          {value.toFixed(2)}
          <br />
          ربح
          <Divider type="vertical" className="mx-4" />
          {profit.toFixed(2)}
        </>
      }
      icon={icon}
      color={color}
      onClick={onClick}
    />
  )
}
