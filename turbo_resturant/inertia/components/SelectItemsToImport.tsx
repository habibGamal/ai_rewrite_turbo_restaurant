import React, { useEffect, useState } from 'react'
import { InventoryItem, Product } from '../types/Models.js'
import useModal from '../hooks/useModal.js'
import { Checkbox, Modal, Transfer } from 'antd'

import type { TransferProps } from 'antd'

interface RecordType {
  key: string
  id: number
  product_id: number
  quantity: number
  product: Product
}

export default function SelectItemsToImport({
  selectModal,
  inventoryItems,
  onSelect,
}: {
  selectModal: ReturnType<typeof useModal>
  inventoryItems: InventoryItem[]
  onSelect: (key: string) => void
}) {
  const [mockData, setMockData] = useState<RecordType[]>(
    inventoryItems.map((item) => ({
      key: item.productId.toString(),
      id: item.id,
      product_id: item.productId,
      quantity: item.quantity,
      product: item.product,
    }))
  )

  const [targetKeys, setTargetKeys] = useState<string[]>([])

  const filterOption = (inputValue: string, option: InventoryItem) =>
    option.product.name.indexOf(inputValue) > -1

  const handleChange = (newTargetKeys: string[]) => {
    setTargetKeys(newTargetKeys)
  }

  const handleSearch = (dir, value) => {

  }
  return (
    <Modal
      {...selectModal}
      title="اختر الاصناف المراد جردها"
      className="min-w-[800px]"
      okText="اختيار"
      cancelText="الغاء"
      onOk={() => {
        targetKeys.forEach((key) => onSelect(key))
        selectModal.closeModal()
      }}
    >
      <Transfer
        titles={['الاصناف', 'الاصناف المحددة']}
        dataSource={mockData}
        showSearch
        filterOption={filterOption}
        targetKeys={targetKeys}
        onChange={handleChange}
        onSearch={handleSearch}
        render={(item) => item?.product?.name}
        listStyle={{
          width: 400,
          height: 500,
        }}
      />
    </Modal>
  )
}
