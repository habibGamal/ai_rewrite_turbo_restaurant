import { Select } from 'antd'
import Search from 'antd/es/input/Search.js'

export default function SimpleTableSearch<T>({
  options,
  onSearch,
  setAttribute,
}: {
  options: { label: string; value: T }[]
  onSearch: (value: string) => void
  setAttribute: (value: T) => void
}) {
  return (
    <Search
      allowClear
      addonBefore={
        options && (
          <Select
            className='min-w-[100px] md:min-w-[150px]'
            defaultValue={options[0].value}
            onChange={(value) => setAttribute(value)}
            options={options}
          />
        )
      }
      placeholder="بحث"
      className="placeholder:font-tajawal my-4"
      onChange={(e) => {
        onSearch(e.target.value)
      }}
      enterButton
    />
  )
}
