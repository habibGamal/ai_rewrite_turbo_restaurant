import { usePage } from '@inertiajs/react'
import { Select } from 'antd'
import React, { useState } from 'react'
export type SetOptions = React.Dispatch<
  React.SetStateAction<
    {
      value: string
      label: string
    }[]
  >
>
interface SelectSearchProps {
  id?: string
  onChange?: (value: string) => void
  placeholder?: React.ReactNode
  onSearch: (value: string, setOptions: SetOptions) => void
  onSelect?: (
    key: string,
    option: {
      value: string
      label: string
    },
    clear: () => void
  ) => void
  style?: React.CSSProperties
  defaultValue?: string | null | undefined
  disabled?: boolean
  initOptions?: {
    value: string
    label: string
  }[]
  value?: any
  selectRef?: React.Ref<HTMLInputElement>
}

export default function SelectSearch({
  id,
  onChange,
  onSearch,
  onSelect,
  placeholder,
  style,
  defaultValue,
  disabled,
  initOptions = [],
  selectRef,
  ...props
}: SelectSearchProps) {
  const [options, setOptions] = useState<
    {
      value: string
      label: string
    }[]
  >(initOptions)

  const clear = () => setOptions([])
  return (
    <Select
      id={id}
      {...props}
      ref={selectRef}
      allowClear
      showSearch
      placeholder={placeholder}
      optionFilterProp="children"
      onChange={onChange}
      onSelect={(key, option) => onSelect && onSelect(key, option, clear)}
      onSearch={(value) => (value.length > 1 ? onSearch(value, setOptions) : null)}
      filterOption={(input, option) =>
        (option?.label ?? '').toLowerCase().includes(input.toLowerCase())
      }
      options={options}
      style={style}
      defaultValue={defaultValue}
      disabled={disabled}
    />
  )
}
