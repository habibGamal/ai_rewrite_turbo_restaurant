import http from "k6/http";
import { check, sleep } from "k6";

export const options = {
    vus: 100, // 20 concurrent users
    duration: "10s", // test duration
};

const BASE_URL = "http://larament.test"; // change this
const ORDER_ID = 1; // test the SAME order → important

export default function () {
    const payload = JSON.stringify({
        items: [
            {
                product_id: 10,
                quantity: 1,
                price: 60,
                notes: null,
                item_discount: "0.00",
                item_discount_type: null,
                item_discount_percent: null,
            },
            {
                product_id: 6,
                quantity: 1,
                price: 180,
                notes: null,
                item_discount: "0.00",
                item_discount_type: null,
                item_discount_percent: null,
            },
            {
                product_id: 17,
                quantity: 1,
                price: 1.5,
                notes: null,
                item_discount: "0.00",
                item_discount_type: null,
                item_discount_percent: null,
            },
        ],
    });

    const params = {
        headers: {
            "Content-Type": "application/json",
            // Add auth if needed:
            // 'Authorization': 'Bearer YOUR_TOKEN',
        },
    };

    const res = http.post(`${BASE_URL}/api/save-order/${ORDER_ID}`, payload, params);

    check(res, {
        "status is 200": (r) => r.status === 200,
    });

    sleep(Math.random()); // random delay to increase overlap
}
