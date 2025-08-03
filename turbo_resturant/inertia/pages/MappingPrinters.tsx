import { router } from '@inertiajs/react'
import type { CheckboxProps } from 'antd'
import { Button, Checkbox, Divider, Row } from 'antd'
import React, { useEffect, useState } from 'react'
import PageTitle from '../components/PageTitle.js'
import { Category, Printer } from '../types/Models.js'
import SelectCategory from '~/components/SelectCategory.js'

const CheckboxGroup = Checkbox.Group


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
