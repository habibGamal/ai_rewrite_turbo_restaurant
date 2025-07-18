import React from 'react'
import InvoiceForm from '../../../components/InvoiceForm.js'
import { PurchaseInvoice } from '../../../types/Models.js'

export default function Edit({
  invoice,
  products,
  suppliers,
}: {
  invoice: PurchaseInvoice
  products: { id: number; name: string; cost: number }[]
  suppliers: { id: number; name: string }[]
}) {
  return (
    <InvoiceForm
      title="فاتورة شراء"
      invoiceNumber={invoice.id}
      route="purchase-invoices"
      mapper={(values) => values}
      products={products}
      initialValues={{
        supplierId: invoice.supplier.id,
        paid: invoice.paid,
        invoiceItems: invoice.items.map((item) => ({
          key: item.id.toString(),
          productId: item.product.id,
          productName: item.product.name,
          quantity: item.quantity,
          cost: item.cost,
          total: item.cost * item.quantity,
        })),
      }}
      mode="edit"
      suppliers={suppliers}
    />
  )
}
