import React, { useEffect, useState } from "react";
import { Head } from "@inertiajs/react";

import { formatCurrency } from "@/utils/orderCalculations";
import {
    CustomerDisplayState,
    readCustomerDisplay,
    subscribeCustomerDisplay,
} from "@/lib/customerDisplay";

interface CustomerDisplayProps {
    shopName: string;
}

/**
 * Full-screen page for the customer-facing monitor. Open it from the cashier
 * screen ("شاشة العميل") and drag the window to the second screen.
 */
export default function CustomerDisplay({ shopName }: CustomerDisplayProps) {
    const [state, setState] = useState<CustomerDisplayState>(() => readCustomerDisplay());

    useEffect(() => subscribeCustomerDisplay(setState), []);

    return (
        <div dir="rtl" className="min-h-screen bg-slate-900 text-white flex flex-col select-none">
            <Head title="شاشة العميل" />

            <header className="px-10 py-6 border-b border-slate-700 flex items-center justify-between">
                <h1 className="text-4xl font-bold m-0 text-white">{shopName}</h1>
                {state.status === "cart" && (
                    <span className="text-2xl text-slate-300">{state.lines.length} صنف</span>
                )}
            </header>

            {state.status === "cart" && state.lines.length > 0 ? (
                <main className="flex-1 flex flex-col">
                    <div className="flex-1 overflow-auto px-10 py-6">
                        <table className="w-full text-2xl">
                            <thead className="text-slate-400 text-xl">
                                <tr>
                                    <th className="text-right pb-4 font-normal">الصنف</th>
                                    <th className="text-center pb-4 font-normal">الكمية</th>
                                    <th className="text-left pb-4 font-normal">السعر</th>
                                    <th className="text-left pb-4 font-normal">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                                {state.lines.map((line, index) => (
                                    <tr
                                        key={index}
                                        className={`border-t border-slate-800 ${
                                            index === state.lines.length - 1 ? "text-amber-300" : ""
                                        }`}
                                    >
                                        <td className="py-3">{line.name}</td>
                                        <td className="py-3 text-center" dir="ltr">
                                            {line.quantity}
                                        </td>
                                        <td className="py-3 text-left" dir="ltr">
                                            {formatCurrency(line.price)}
                                        </td>
                                        <td className="py-3 text-left" dir="ltr">
                                            {formatCurrency(line.total)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <footer className="px-10 py-8 bg-slate-800 grid grid-cols-3 gap-6 text-center">
                        <div>
                            <div className="text-xl text-slate-400">المجموع</div>
                            <div className="text-3xl" dir="ltr">
                                {formatCurrency(state.subTotal)}
                            </div>
                        </div>
                        <div>
                            <div className="text-xl text-slate-400">الخصم</div>
                            <div className="text-3xl" dir="ltr">
                                {formatCurrency(state.discount)}
                            </div>
                        </div>
                        <div>
                            <div className="text-xl text-slate-400">الإجمالي</div>
                            <div className="text-6xl font-bold text-emerald-400" dir="ltr">
                                {formatCurrency(state.total)}
                            </div>
                        </div>
                    </footer>
                </main>
            ) : state.status === "paid" ? (
                <main className="flex-1 flex flex-col items-center justify-center gap-8 text-center">
                    <div className="text-5xl font-bold text-emerald-400">شكراً لزيارتكم</div>
                    <div className="grid grid-cols-3 gap-12 text-3xl">
                        <div>
                            <div className="text-xl text-slate-400">الإجمالي</div>
                            <div dir="ltr">{formatCurrency(state.total)}</div>
                        </div>
                        <div>
                            <div className="text-xl text-slate-400">المدفوع</div>
                            <div dir="ltr">{formatCurrency(state.paid)}</div>
                        </div>
                        <div>
                            <div className="text-xl text-slate-400">الباقي</div>
                            <div className="text-5xl font-bold text-amber-300" dir="ltr">
                                {formatCurrency(state.change)}
                            </div>
                        </div>
                    </div>
                    <div className="text-xl text-slate-500">فاتورة رقم {state.orderNumber}</div>
                </main>
            ) : (
                <main className="flex-1 flex items-center justify-center">
                    <div className="text-5xl text-slate-300">أهلاً بكم في {shopName}</div>
                </main>
            )}
        </div>
    );
}
