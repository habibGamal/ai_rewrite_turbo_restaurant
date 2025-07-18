import React from 'react'

export default function GridCards({ children }: { children: React.ReactNode[] }) {
  return (
    <div className="grid gap-8 mb-8 grid-cols-1 md:grid-cols-2 2xl:grid-cols-4">{children}</div>
  )
}
