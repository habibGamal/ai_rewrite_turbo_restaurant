import { Order, OrderItemData } from "@/types";
import { usePage } from "@inertiajs/react";

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
    order: Order;
    orderItems: OrderItemData[];
    receiptFooter: string;
    partType: PartType;
    index: number;
}) {
    return (
        <div
            id={`receipt_${index}`}
            className="w-[500px] font-bold text-xl space-y-4"
        >
            {partType === PartType.Header && (
                <>
                    <img
                        className="block mx-auto w-[50mm]"
                        src="/images/logo.png"
                        alt=""
                    />
                    <p className="text-5xl text-center">
                        Order #{order.order_number}
                    </p>
                    <p>الكاشير : {order.user?.email}</p>
                    <p>
                        تاريخ الطلب :{" "}
                        {new Date(order.created_at).toLocaleString("ar-EG", {
                            hour12: true,
                        })}
                    </p>
                    <p>
                        تاريخ الطباعة :{" "}
                        {new Date().toLocaleString("ar-EG", { hour12: true })}
                    </p>
                    <p>نوع الطلب : {order.display?.label ?? order.type}</p>
                    {order.display?.showTable && (
                        <p>طاولة رقم {order.dine_table_number}</p>
                    )}
                    {order.display?.showCustomer && (
                        <>
                            <p>رقم الهاتف : {order.customer?.phone || "-"}</p>
                            <p>اسم العميل : {order.customer?.name || "-"}</p>
                        </>
                    )}
                    {order.display?.showAddress && (
                        <p>العنوان : {order.customer?.address || "-"}</p>
                    )}
                    {order.display?.showDriver && (
                        <p>السائق : {order.driver?.name || "-"}</p>
                    )}
                </>
            )}
            <table className="w-full mt-4 table-fixed border-collapse border-solid border border-black">
                <thead>
                    <tr>
                        <th className="p-2 border border-solid border-black">
                            المنتج
                        </th>
                        <th className="p-2 border border-solid border-black">
                            الكمية
                        </th>
                        <th className="p-2 border border-solid border-black">
                            السعر
                        </th>
                        <th className="p-2 border border-solid border-black">
                            الاجمالي
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {orderItems.map((item, index) => (
                        <tr key={index}>
                            <td className="px-2 py-4 border border-solid border-black">
                                {item.name}
                            </td>
                            <td className="px-2 border border-solid border-black">
                                {item.quantity}
                            </td>
                            <td className="px-2 border border-solid border-black">
                                {Number(item.price).toFixed(2)}
                            </td>
                            <td className="px-2 border border-solid border-black">
                                {(item.quantity * item.price).toFixed(2)}
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
                                <td className="p-2 py-4 border border-solid border-black">
                                    اجمالي الطلب
                                </td>
                                <td className="p-2 py-4 border border-solid border-black">
                                    {Number(order.sub_total).toFixed(2)}
                                </td>
                            </tr>
                            <tr>
                                <td className="p-2 py-4 border border-solid border-black">
                                    الخصم
                                </td>
                                <td className="p-2 py-4 border border-solid border-black">
                                    {Number(order.discount).toFixed(2)}
                                </td>
                            </tr>
                            <tr>
                                <td className="p-2 py-4 border border-solid border-black">
                                    الخدمة
                                </td>
                                <td className="p-2 py-4 border border-solid border-black">
                                    {Number(order.service).toFixed(2)}
                                </td>
                            </tr>
                            <tr>
                                <td className="p-2 py-4 border border-solid border-black">
                                    الضريبة
                                </td>
                                <td className="p-2 py-4 border border-solid border-black">
                                    {Number(order.tax).toFixed(2)}
                                </td>
                            </tr>
                            <tr>
                                <td className="p-2 py-4 border border-solid border-black">
                                    الاجمالي النهائي
                                </td>
                                <td className="p-2 py-4 border border-solid border-black">
                                    {Number(order.total).toFixed(2)}
                                </td>
                            </tr>
                            {order.payment_status === "partial_paid" && order.receiptPayment && (
                                <>
                                    <tr>
                                        <td className="p-2 py-4 border border-solid border-black">
                                            المدفوع
                                        </td>
                                        <td className="p-2 py-4 border border-solid border-black">
                                            {Number(order.receiptPayment.paid).toFixed(2)}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td className="p-2 py-4 border border-solid border-black">
                                            المتبقي
                                        </td>
                                        <td className="p-2 py-4 border border-solid border-black">
                                            {Number(order.receiptPayment.remaining).toFixed(2)}
                                        </td>
                                    </tr>
                                </>
                            )}
                        </tbody>
                    </table>
                    <p className="whitespace-pre-line mt-4 text-center">
                        {receiptFooter}
                    </p>
                    <img
                        className="block mx-auto w-[50mm] mt-4"
                        src="/images/turbo.png"
                        alt=""
                    />
                    <p className="text-xl text-center">Turbo Software Space</p>
                    <p className="text-center">
                        {" "}
                        {new Date().toLocaleString("ar-EG", { hour12: true })}
                    </p>
                </>
            )}
        </div>
    );
}
