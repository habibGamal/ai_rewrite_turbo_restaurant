import React from 'react'
import InvoiceForm from '../../../components/InvoiceForm.js'

export default function Create({
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
      title="فاتورة شراء"
      invoiceNumber={invoiceNumber}
      route="purchase-invoices"
      mapper={(values) => values}
      products={products}
      mode="create"
      suppliers={suppliers}
    />
  )
}
