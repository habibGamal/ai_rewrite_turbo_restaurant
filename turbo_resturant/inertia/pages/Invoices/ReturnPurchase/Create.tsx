import React from 'react'
import InvoiceForm from '../../../components/InvoiceForm.js'

export default function PurchaseInvoice({
  invoiceNumber,
  products,
  suppliers,
}: {
  invoiceNumber: number
  products: { id: number; name: string; cost: number }[]
  suppliers: { id: number; name: string }[]
}) {
  return (
    <InvoiceForm
      title="مرتجع فاتورة شراء"
      invoiceNumber={invoiceNumber}
      route="return-purchase-invoices"
      mapper={(values) => ({
        supplierId: values.supplierId,
        received: values.paid,
        items: values.items.map((item) => ({
          productId: item.productId,
          quantity: item.quantity,
          price: item.cost,
        })),
      })}
      products={products}
      mode='create'
      suppliers={suppliers}
    />
  )
}
