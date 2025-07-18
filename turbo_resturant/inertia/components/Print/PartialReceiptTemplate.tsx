import { OrderType } from '#enums/OrderEnums'
import { Order } from '~/types/Models'
import { OrderItemT } from '~/types/Types'

export enum PartType {
  Header,
  Body,
  Footer,
}

export default function PartialReceiptTemplate({
  order,
  orderItems,
  receiptFooter,
  partType,
  index,
}: {
  order: Order
  orderItems: OrderItemT[]
  receiptFooter: string
  partType: PartType
  index: number
}) {
  return (
    <div id={`receipt_${index}`} className="w-[500px] font-bold text-xl">
      {partType === PartType.Header && (
        <>
          <img className="block mx-auto w-[50mm]" src="/images/logo.png" alt="" />
          <p className="text-5xl text-center">Order #{order.orderNumber}</p>
          <p>نوع الطلب : {order.typeString}</p>
          {order.type === OrderType.DineIn && <p>طاولة رقم {order.dineTableNumber}</p>}
          {order.type === OrderType.Delivery && (
            <>
              <p>رقم الهاتف : {order.customer?.phone || '-'}</p>
              <p>اسم العميل : {order.customer?.name || '-'}</p>
              <p>العنوان : {order.customer?.address || '-'}</p>
              <p>السائق : {order.driver?.name || '-'}</p>
            </>
          )}
          {order.user && <p>الكاشير : {order.user.email}</p>}
        </>
      )}
      <table className="w-full table-fixed border-collapse border-solid border border-black">
        <thead>
          <tr>
            <th className="p-2 border border-solid border-black">المنتج</th>
            <th className="p-2 border border-solid border-black">الكمية</th>
            <th className="p-2 border border-solid border-black">السعر</th>
            <th className="p-2 border border-solid border-black">الاجمالي</th>
          </tr>
        </thead>
        <tbody>
          {orderItems!.map((item, index) => (
            <tr key={index}>
              <td className="px-2 py-4 border border-solid border-black">{item.name}</td>
              <td className="px-2 border border-solid border-black">{item.quantity}</td>
              <td className="px-2 border border-solid border-black">{item.price}</td>
              <td className="px-2 border border-solid border-black">
                {item.quantity * item.price}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {partType === PartType.Footer && (
        <>
          <table className="mt-4 w-full table-fixed border-collapse border-solid border border-black">
            <tbody>
              <tr>
                <td className="p-2 py-4 border border-solid border-black">اجمالي الطلب</td>
                <td className="p-2 py-4 border border-solid border-black">
                  {order.subTotal.toFixed(2)}
                </td>
              </tr>
              <tr>
                <td className="p-2 py-4 border border-solid border-black">الخصم</td>
                <td className="p-2 py-4 border border-solid border-black">
                  {order.discount.toFixed(2)}
                </td>
              </tr>
              <tr>
                <td className="p-2 py-4 border border-solid border-black">الخدمة</td>
                <td className="p-2 py-4 border border-solid border-black">
                  {order.service.toFixed(2)}
                </td>
              </tr>
              <tr>
                <td className="p-2 py-4 border border-solid border-black">الضريبة</td>
                <td className="p-2 py-4 border border-solid border-black">
                  {order.tax.toFixed(2)}
                </td>
              </tr>
              <tr>
                <td className="p-2 py-4 border border-solid border-black">الاجمالي النهائي</td>
                <td className="p-2 py-4 border border-solid border-black">
                  {order.total.toFixed(2)}
                </td>
              </tr>
            </tbody>
          </table>
          <p className="whitespace-pre-line">{receiptFooter}</p>
          <img className="block mx-auto w-[50mm]" src="/images/turbo.png" alt="" />
          <p className="text-xl text-center">Turbo Software Space</p>
          <p className="text-center"> {new Date().toLocaleString('ar-EG', { hour12: true })}</p>
        </>
      )}
    </div>
  )
}
