import { PlusCircleOutlined } from '@ant-design/icons'
import { Button, Form, Select } from 'antd'
import Search from 'antd/es/input/Search'

interface TableComponentProps {
  options?:
    | {
        label: string
        value: string
      }[]
    | undefined
  defaultValue?: string | null | undefined
  addButtonText?: string | null
  addButtonAction?: () => void
  searchButtonAction: () => void
  setSearch: (value: string) => void
  setAttribute: (value: string) => void
  exitSearchMode: () => void
}

export default function TableController({
  options,
  addButtonText,
  addButtonAction,
  searchButtonAction,
  setSearch,
  setAttribute,
  exitSearchMode,
}: TableComponentProps) {
  return (
    <Form className="flex gap-6 mb-6 w-full">
      <Search
        allowClear
        // style={{ maxWidth: "250px" }}
        addonBefore={
          options && (
            <Select
              defaultValue={options[0].value}
              onChange={(value) => setAttribute(value)}
              options={options}
            />
          )
        }
        placeholder="بحث"
        className="placeholder:font-tajawal"
        onChange={(e) => {
          setSearch(e.target.value)
          if (e.target.value) searchButtonAction()
          else exitSearchMode()
        }}
        enterButton
      />
      {addButtonText && (
        <Button type="primary" icon={<PlusCircleOutlined />} onClick={addButtonAction}>
          {addButtonText}
        </Button>
      )}
    </Form>
  )
}
