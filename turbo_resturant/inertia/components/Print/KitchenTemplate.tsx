import { OrderType } from '#enums/OrderEnums'
import { Order } from '~/types/Models'
import { KitchenItemForPrint } from '../ManageOrder/PrintInKitchen'

export default function KitchenTemplate({
  printerId,
  order,
  orderItems,
}: {
  printerId: string
  order: Order
  orderItems: KitchenItemForPrint[]
}) {
  return (
    <div id={'printer_' + printerId} className="w-[500px] font-bold text-2xl">
      <p className="text-3xl text-center">Order #{order.orderNumber}</p>
      <p>نوع الطلب : {order.typeString}</p>
      <p>التاريخ : {new Date().toLocaleString('ar-EG', { hour12: true })}</p>
      {order.type === OrderType.DineIn && <p>طاولة رقم {order.dineTableNumber}</p>}
      <table className="w-full table-fixed border-collapse border-solid border border-black">
        <thead>
          <tr>
            <th className="p-2 border border-solid border-black">المنتج</th>
            <th className="p-2 border border-solid border-black">الكمية</th>
          </tr>
        </thead>
        <tbody>
          {orderItems!.map((item, index) => (
            <>
              <tr key={index}>
                <td className="px-2 py-4 border border-solid border-black">{item.name}</td>
                <td className="px-2 py-4 border border-solid border-black">{item.quantity}</td>
              </tr>
              {item.notes && (
                <tr>
                  <td colSpan={2} className="px-2 py-4 border border-solid border-black">
                    ملاحظات : {" "}
                    {item.notes}
                  </td>
                </tr>
              )}
            </>
          ))}
        </tbody>
      </table>
    </div>
  )
}
