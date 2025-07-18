import React from 'react'
import InvoiceForm from '../../../components/InvoiceForm.js'
import { ReturnPurchaseInvoice } from '../../../types/Models.js'

export default function PurchaseInvoice({
  invoice,
  products,
  suppliers,
}: {
  invoice: ReturnPurchaseInvoice
  products: { id: number; name: string; cost: number }[]
  suppliers: { id: number; name: string }[]
}) {
  return (
    <InvoiceForm
      title="مرتجع فاتورة شراء"
      invoiceNumber={invoice.id}
      route="return-purchase-invoices"
      mapper={(values) => ({
        supplierId: values.supplierId,
        received: values.paid,
        close: values.close,
        items: values.items.map((item) => ({
          productId: item.productId,
          quantity: item.quantity,
          price: item.cost,
        })),
      })}
      products={products}
      mode="edit"
      suppliers={suppliers}
      initialValues={{
        supplierId: invoice.supplier.id,
        paid: invoice.received,
        invoiceItems: invoice.items.map((item) => ({
          key: item.id.toString(),
          productId: item.product.id,
          productName: item.product.name,
          quantity: item.quantity,
          cost: item.price,
          total: item.price * item.quantity,
        })),
      }}
    />
  )
}
