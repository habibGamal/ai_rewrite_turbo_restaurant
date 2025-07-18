import React, { useState } from 'react'
import { Button, ConfigProvider, Divider, Radio, RadioChangeEvent, Typography } from 'antd'
import { Category, Product, User } from '../../types/Models.js'
import { OrderItemsReducerActions } from '../../types/Types.js'
import Search from 'antd/es/input/Search'
import { usePage } from '@inertiajs/react'

export default function Categories({
  categories,
  dispatch,
  disabled,
}: {
  categories: Category[]
  dispatch: React.Dispatch<OrderItemsReducerActions>
  disabled?: boolean
}) {
  const user = usePage().props.user as User
  const allProducts = categories.flatMap((category) => category.products)
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null)
  const [products, setProducts] = useState(allProducts)

  const onChangeCategory = ({ target: { value } }: RadioChangeEvent) => {
    if (value === 'all') {
      setProducts(allProducts)
      setSelectedCategory(null)
    } else {
      const category = categories.find((category) => category.id === value)
      setProducts(category?.products || [])
      setSelectedCategory(category.id)
    }
  }

  const onAddProduct = (product: Product) => {
    dispatch({
      type: 'add',
      orderItem: {
        productId: product.id,
        name: product.name,
        price: product.price,
        quantity: 1,
        initialQuantity: null,
      },
      user,
    })
  }

  return (
    <div className="isolate">
      <div className="flex justify-between mb-4">
        <Typography.Title className="mt-0" level={5}>
          الاصناف
        </Typography.Title>
        <Search
          style={{ width: 200 }}
          placeholder="بحث"
          onChange={(e) => {
            const products =
              selectedCategory === null
                ? allProducts
                : categories.find((category) => category.id === selectedCategory).products
            setProducts(products.filter((product) => product.name.includes(e.target.value)))
          }}
        />
      </div>
      <ConfigProvider
        theme={{
          token: {
            borderRadius: 4,
          },
        }}
      >
        <Radio.Group
          className="grid grid-cols-4 text-center gap-4"
          onChange={onChangeCategory}
          size="large"
          defaultValue="all"
          buttonStyle="solid"
        >
          <Radio.Button className="rounded before:!hidden border-none" value="all">
            الكل
          </Radio.Button>
          {categories.map((category) => (
            <Radio.Button
              disabled={disabled}
              key={category.id}
              className="rounded whitespace-nowrap overflow-hidden text-ellipsis before:!hidden border-none"
              value={category.id}
            >
              {category.name}
            </Radio.Button>
          ))}
        </Radio.Group>
      </ConfigProvider>
      <Divider />
      <div className="grid grid-cols-4 gap-4">
        {products.map((product) => (
          <Button
            disabled={disabled}
            key={product.id}
            type="primary"
            onClick={() => onAddProduct(product)}
            className="w-full h-full min-h-[100px] text-3xl whitespace-normal bg-dark-500 "
          >
            {product.name}
          </Button>
        ))}
      </div>
    </div>
  )
}
