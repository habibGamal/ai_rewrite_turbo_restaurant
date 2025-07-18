import { router } from '@inertiajs/react'
import { Button, Checkbox, Divider, InputNumber, message, Modal } from 'antd'
import { CheckboxChangeEvent } from 'antd/es/checkbox'
import { CheckboxValueType } from 'antd/es/checkbox/Group'
import axios from 'axios'
import { useEffect, useState } from 'react'
import printTemplate from '~/helpers/printTemplate.js'
import useModal from '../../hooks/useModal.js'
import { Order } from '../../types/Models.js'
import { OrderItemT } from '../../types/Types.js'
import KitchenTemplate from '../Print/KitchenTemplate.js'

export type KitchenItemForPrint = {
  productId: number
  name: string
  price: number
  quantity: number
  notes: string | null
  initialQuantity: number
}
export default function PrintInKitchen({
  printInKitchenModal,
  order,
  orderItems,
}: {
  printInKitchenModal: ReturnType<typeof useModal>
  order: Order
  orderItems: OrderItemT[]
}) {
  const [itemsQuantity, setItemsQuantity] = useState<KitchenItemForPrint[]>([])
  useEffect(() => {
    setItemsQuantity(JSON.parse(JSON.stringify(orderItems)))
  }, [orderItems])
  const defaultList = orderItems.map((item) => ({
    id: item.productId.toString(),
    label: (
      <div className="my-2">
        {item.name}
        <InputNumber
          className="mr-2"
          defaultValue={item.quantity}
          onChange={(value) =>
            setItemsQuantity((state) => {
              const index = state.findIndex((i) => i.productId === item.productId)
              if (index !== -1) {
                state[index].quantity = value!
              }
              return [...state]
            })
          }
        />
      </div>
    ),
    value: item.productId,
  }))
  const [checkedList, setCheckedList] = useState<CheckboxValueType[]>(
    defaultList.map((item) => item.value)
  )

  const checkAll = defaultList.length === checkedList.length
  const indeterminate = checkedList.length > 0 && checkedList.length < defaultList.length

  const onChange = (list: CheckboxValueType[]) => {
    setCheckedList(list)
  }

  const onCheckAllChange = (e: CheckboxChangeEvent) => {
    setCheckedList(e.target.checked ? defaultList.map((item) => item.value) : [])
  }

  const disablePrint = checkedList.length === 0
  const itemsToPrint = itemsQuantity.filter((item) => checkedList.includes(item.productId))

  const mappingItemsToPrinters = async () => {
    printInKitchenModal.closeModal()
    message.loading('جاري الطباعة')
    const result = await axios.post<
      {
        id: number // product id
        printers: {
          id: number
        }[]
      }[]
    >('/printers-of-products', {
      ids: itemsToPrint.map((item) => item.productId),
    })
    const itemsByPrinterMap: {
      [key: string]: {
        items: typeof itemsToPrint
      }
    } = {}
    for (const item of itemsToPrint) {
      for (const printer of result.data.find((product) => product.id === item.productId)!
        .printers) {
        if (!itemsByPrinterMap[printer.id]) {
          itemsByPrinterMap[printer.id] = {
            items: [],
          }
        }
        itemsByPrinterMap[printer.id].items.push(item)
      }
    }
    const images: {
      printerId: string
      image: string
    }[] = []
    for (const [printerId, printer] of Object.entries(itemsByPrinterMap)) {
      const image = await printTemplate(
        'printer_' + printerId,
        <KitchenTemplate
          key={printerId}
          printerId={printerId}
          order={order}
          orderItems={printer.items}
        />
      )
      images.push({
        printerId,
        image,
      })
    }
    router.post('/print-in-kitchen', {
      images,
    })
  }

  return (
    <Modal
      {...printInKitchenModal}
      title="طباعة في المطبخ"
      footer={
        <Button
          disabled={disablePrint}
          onClick={mappingItemsToPrinters}
          className="my-4"
          htmlType="submit"
          type="primary"
        >
          طباعة
        </Button>
      }
      destroyOnClose
    >
      <Checkbox indeterminate={indeterminate} onChange={onCheckAllChange} checked={checkAll}>
        طباعة الكل
      </Checkbox>
      <Divider />
      <Checkbox.Group
        className="flex-col text-xl"
        options={defaultList}
        value={checkedList}
        onChange={onChange}
      />
    </Modal>
  )
}
