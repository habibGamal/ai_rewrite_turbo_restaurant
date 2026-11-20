import { ReceiptView } from "@/types";
import { useTerms } from "@/lib/wording";

const cell = "p-2 py-4 border border-solid border-black";

export default function ExternalReceipt({ receipt }: { receipt?: ReceiptView }) {
    const terms = useTerms();
    if (!receipt || receipt.external_orders.length === 0) return null;

    return (
        <div className="mt-4">
            {receipt.external_orders.map((seller, index) => (
                <section key={index} className="mb-4">
                    <p className="text-lg font-bold">طلب خارجي — {seller.seller_name}</p>
                    <table className="w-full border-collapse border border-solid border-black">
                        <thead>
                            <tr>
                                <th className={cell}>الصنف</th>
                                <th className={cell}>الكمية</th>
                                <th className={cell}>السعر</th>
                                <th className={cell}>الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>
                            {seller.items.map((item, itemIndex) => (
                                <tr key={itemIndex}>
                                    <td className={cell}>{item.name}</td>
                                    <td className={cell}>{item.quantity}</td>
                                    <td className={cell}>{Number(item.price).toFixed(2)}</td>
                                    <td className={cell}>{Number(item.total).toFixed(2)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            ))}
            <table className="w-full border-collapse border border-solid border-black">
                <tbody>
                    <tr>
                        <td className={cell}>{terms.hostTotal}</td>
                        <td className={cell}>{Number(receipt.restaurant_total).toFixed(2)}</td>
                    </tr>
                    <tr>
                        <td className={cell}>إجمالي الطلبات الخارجية</td>
                        <td className={cell}>{Number(receipt.external_total).toFixed(2)}</td>
                    </tr>
                    <tr className="border-t-[3px] border-solid border-black">
                        <td className={`${cell} text-2xl font-bold`}>المطلوب من العميل</td>
                        <td className={`${cell} text-3xl font-bold`}>
                            {Number(receipt.guest_total).toFixed(2)}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}
