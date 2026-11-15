import { ReceiptView } from "@/types";
import { useTerms } from "@/lib/wording";

export default function ExternalReceipt({ receipt }: { receipt?: ReceiptView }) {
    const terms = useTerms();
    if (!receipt || receipt.external_orders.length === 0) return null;

    return (
        <div>
            {receipt.external_orders.map((seller, index) => (
                <section key={index}>
                    <p>طلب خارجي — {seller.seller_name}</p>
                    <table className="w-full border border-black">
                        <tbody>
                            {seller.items.map((item, itemIndex) => (
                                <tr key={itemIndex}>
                                    <td>{item.name}</td><td>{item.quantity}</td>
                                    <td>{Number(item.price).toFixed(2)}</td><td>{Number(item.total).toFixed(2)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            ))}
            <p>{terms.hostTotal}: {Number(receipt.restaurant_total).toFixed(2)}</p>
            <p>إجمالي الطلبات الخارجية: {Number(receipt.external_total).toFixed(2)}</p>
            <p>المطلوب من العميل: {Number(receipt.guest_total).toFixed(2)}</p>
        </div>
    );
}
