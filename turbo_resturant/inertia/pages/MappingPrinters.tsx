import { router } from '@inertiajs/react'
import type { CheckboxProps } from 'antd'
import { Button, Checkbox, Divider, Row } from 'antd'
import React, { useEffect, useState } from 'react'
import PageTitle from '../components/PageTitle.js'
import { Category, Printer } from '../types/Models.js'

const CheckboxGroup = Checkbox.Group

const SelectCategory = ({
  category,
  selectedProducts,
  setSelectedProducts,
}: {
  category: Category
  selectedProducts: { catgoryId: number; selected: string[] }[]
  setSelectedProducts: React.Dispatch<
    React.SetStateAction<{ catgoryId: number; selected: string[] }[]>
  >
}) => {
  const plainOptions = category.products.map((product) => ({
    label: product.name,
    value: product.id.toString(),
  }))
  const defaultCheckedList = selectedProducts.find(
    (item) => item.catgoryId === category.id
  )?.selected

  const [checkedList, setCheckedList] = useState<string[]>(defaultCheckedList)
  const checkAll = plainOptions.length === checkedList.length
  const indeterminate = checkedList.length > 0 && checkedList.length < plainOptions.length

  const onChange = (list: string[]) => {
    setCheckedList(list)
  }

  const onCheckAllChange: CheckboxProps['onChange'] = (e) => {
    setCheckedList(e.target.checked ? plainOptions.map((option) => option.value) : [])
  }

  useEffect(() => {
    setSelectedProducts((prev) => {
      const index = prev.findIndex((item) => item.catgoryId === category.id)
      if (index === -1) {
        return [...prev, { catgoryId: category.id, selected: checkedList }]
      }
      prev[index].selected = checkedList
      return prev
    })
  }, [checkedList])
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

export default function MappingPrinters({
  printer,
  categories,
}: {
  printer: Printer
  categories: Category[]
}) {
  const defaultSelectedProducts = (category: Category) =>
    category.products
      .filter(
        (product) =>
          product.printers.findIndex((productPrinter) => productPrinter.id === printer.id) !== -1
      )
      .map((product) => product.id.toString())

  const [selectedProducts, setSelectedProducts] = useState<
    { catgoryId: number; selected: string[] }[]
  >(
    categories
      .filter((category) => category.products.length !== 0)
      .map((category) => ({
        catgoryId: category.id,
        selected: defaultSelectedProducts(category),
      }))
  )

  const save = () => {
    router.post('/mapping-printer-products', {
      printerId: printer.id,
      products: selectedProducts
        .map((item) => item.selected)
        .reduce((acc, cur) => acc.concat(cur), []),
    })
  }

  return (
    <Row gutter={[0, 25]} className="m-8">
      <div className="flex justify-between w-full">
        <PageTitle name={`طباعة في ${printer.name}`} />
        <Button type="primary" onClick={save}>
          حفظ
        </Button>
      </div>
      <div className="grid grid-cols-3 gap-8">
        {categories
          .filter((category) => category.products.length !== 0)
          .map((category) => (
            <SelectCategory
              key={category.id}
              category={category}
              selectedProducts={selectedProducts}
              setSelectedProducts={setSelectedProducts}
            />
          ))}
      </div>
    </Row>
  )
}
