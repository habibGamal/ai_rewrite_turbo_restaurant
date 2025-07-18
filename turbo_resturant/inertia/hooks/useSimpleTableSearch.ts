import { useState } from 'react'

export default function useSimpleTableSearch<T>({
  dataSource,
  options,
}: {
  dataSource: any[]
  options: { label: string; value: T }[]
}): {
  data: any[]
  setAttribute: (value: T) => void
  onSearch: (value: string) => void
} {
  const [data, setData] = useState(dataSource)
  const [attribute, setAttribute] = useState<T>(options[0].value)
  const onSearch = (value: string) => {
    const filteredData = dataSource.filter((item) => item[attribute].toString().includes(value))
    setData(filteredData)
  }
  return { data, setAttribute, onSearch }
}
