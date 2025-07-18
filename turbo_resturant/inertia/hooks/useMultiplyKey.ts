import { useEffect } from "react";

export default function useMultiplyKey() {
    useEffect(() => {
        const event = (e: KeyboardEvent) => {
            if (e.code !== "NumpadMultiply") return;
            e.preventDefault();
            const lastItemQuantity = document.querySelector(
                ".editable-quantity"
            ) as HTMLElement;
            lastItemQuantity?.click();
        };
        window.addEventListener("keydown", event);
        return () => window.removeEventListener("keydown", event);
    }, []);
}
