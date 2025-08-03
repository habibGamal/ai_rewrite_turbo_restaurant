import type { CheckboxProps } from 'antd'
import { Checkbox, Divider } from 'antd'
import React, { ReactNode } from 'react'
import { Category } from '../types/Models.js'

const CheckboxGroup = Checkbox.Group

const SelectCategory = ({
  category,
  selectedProducts,
  setSelectedProducts,
  options,
}: {
  category: Category
  selectedProducts: { catgoryId: number; selected: string[] }[]
  setSelectedProducts: React.Dispatch<
    React.SetStateAction<{ catgoryId: number; selected: string[] }[]>
  >
  options?: { label: ReactNode; value: string }[]
}) => {
  const plainOptions =
    options ||
    category.products.map((product) => ({
      label: product.name,
      value: product.id.toString(),
    }))

  const checkedList =
    selectedProducts.find((item) => item.catgoryId === category.id)?.selected || []
  const checkAll = plainOptions.length === checkedList.length
  const indeterminate = checkedList.length > 0 && checkedList.length < plainOptions.length
  const onChange = (list: string[]) => {
    setSelectedProducts((prev) => {
      const index = prev.findIndex((item) => item.catgoryId === category.id)
      if (index === -1) {
        return [...prev, { catgoryId: category.id, selected: list }]
      }
      prev[index].selected = list
      return [...prev]
    })
  }

  const onCheckAllChange: CheckboxProps['onChange'] = (e) => {
    const list = e.target.checked ? plainOptions.map((option) => option.value) : []

    setSelectedProducts((prev) => {
      const index = prev.findIndex((item) => item.catgoryId === category.id)
      if (index === -1) {
        return [...prev, { catgoryId: category.id, selected: list }]
      }
      prev[index].selected = list
      return [...prev]
    })
  }
  return (
    <div>
      <Checkbox indeterminate={indeterminate} onChange={onCheckAllChange} checked={checkAll}>
        {category.name}
      </Checkbox>
      <Divider className="my-2" />
      <CheckboxGroup
        options={plainOptions}
        value={checkedList}
        onChange={onChange}
        className="grid grid-cols-1"
      />
    </div>
  )
}

export default SelectCategory
