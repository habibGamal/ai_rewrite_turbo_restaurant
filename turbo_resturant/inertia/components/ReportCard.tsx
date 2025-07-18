import React from 'react'
import { Typography } from 'antd'

type Props = {
  title: React.ReactNode
  mainText: React.ReactNode
  secondaryText: React.ReactNode
  icon: React.ReactNode
  color: string
  onLine?: boolean
  onClick?: () => void
}

export default function ReportCard({
  title,
  mainText,
  secondaryText,
  icon,
  color,
  onLine,
  onClick,
}: Props) {
  return (
    <div className="isolate px-4 flex items-center gap-4 xl:gap-8 hover:cursor-pointer hover:scale-105 transition-transform" onClick={onClick}>
      <div className={`rounded-full w-16 aspect-square grid place-items-center ${color}`}>
        {icon}
      </div>
      <div className="flex flex-col">
        <Typography.Title className={`!mt-0 ${onLine ? '!mb-0' : ''}`} level={4}>
          {title}
        </Typography.Title>
        <Typography.Text className="mb-2">{mainText}</Typography.Text>
        <Typography.Text type="secondary">{secondaryText}</Typography.Text>
      </div>
    </div>
  )
}
