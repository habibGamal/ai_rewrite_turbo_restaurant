import { router } from '@inertiajs/react'
import html2canvas from 'html2canvas'
import { ReactNode } from 'react'
import { renderToString } from 'react-dom/server'
import PartialReceiptTemplate, { PartType } from '~/components/Print/PartialReceiptTemplate'
import ReceiptTemplate from '~/components/Print/ReceiptTemplate'
import ShiftSummaryTemplate from '~/components/Print/ShiftSummaryTemplate'
import { Order } from '~/types/Models'
import { OrderItemT } from '~/types/Types'
export default async function printTemplate(id: string, element: React.ReactNode) {
  const printContainer = document.getElementById('print_container')!
  printContainer.innerHTML = renderToString(element)
  const receipt = document.getElementById(id)!
  const canvas = await html2canvas(receipt, {
    scale: 1,
  })
  console.log(canvas)
  return canvas.toDataURL('image/png', 1)
}

export async function printOrder(order: Order, orderItems: OrderItemT[], footer: string) {
  const maxItemsInPrint = 5
  const images: string[] = []
  let itemsCount = orderItems.length
  if (itemsCount <= maxItemsInPrint) {
    const image = await printTemplate(
      'receipt',
      <ReceiptTemplate receiptFooter={footer} order={order} orderItems={orderItems} />
    )
    images.push(image)
  }
  if (itemsCount > maxItemsInPrint) {
    let index = 0
    let slice = 0
    let type = PartType.Header
    while (itemsCount > 0) {
      if (itemsCount <= maxItemsInPrint) type = PartType.Footer
      const image = await printTemplate(
        `receipt_${index}`,
        <PartialReceiptTemplate
          receiptFooter={footer}
          order={order}
          orderItems={orderItems.slice(slice, slice + maxItemsInPrint)}
          partType={type}
          index={index}
        />
      )
      type = PartType.Body
      images.push(image)
      itemsCount -= maxItemsInPrint
      index++
      slice += maxItemsInPrint
    }
  }
  console.log(images)
  router.post(
    `/print/${order.id}`,
    {
      images,
    },
    {
      preserveState: true,
    }
  )
}

export async function printShiftSummery(
  shiftNumber: number,
  info: { title: React.ReactNode; value: string }[]
) {
  const image = await printTemplate(
    'receipt',
    <ShiftSummaryTemplate shiftNumber={shiftNumber} info={info} />
  )
  router.post(
    `/print/shift-summary`,
    {
      image,
    },
    {
      preserveState: true,
    }
  )
}
