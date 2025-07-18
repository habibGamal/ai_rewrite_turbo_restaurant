import React from 'react'
import ReportCard from '../ReportCard.js'
import { Divider } from 'antd'

export default function MoneyInfo({
  mainText,
  value,
  icon,
  color,
  onClick,
}: {
  mainText: React.ReactNode
  value: number
  icon: React.ReactNode
  color: string
  onClick?: () => void
}) {
  return (
    <ReportCard
      title={
        <>
          {value.toFixed(2)}
          <Divider type="vertical" className="mx-4" />
          جنية
        </>
      }
      mainText={mainText}
      secondaryText={<></>}
      icon={icon}
      color={color}
      onClick={onClick}
    />
  )
}
