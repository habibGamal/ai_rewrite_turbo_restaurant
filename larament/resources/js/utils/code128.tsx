import React from "react";

/**
 * Code 128 (set B, or set C for even-length digit codes) as inline SVG, the
 * same encoding as App\\Support\\Barcodes\\Code128 on the server.
 */
const PATTERNS = [
    "212222", "222122", "222221", "121223", "121322", "131222", "122213", "122312", "132212", "221213",
    "221312", "231212", "112232", "122132", "122231", "113222", "123122", "123221", "223211", "221132",
    "221231", "213212", "223112", "312131", "311222", "321122", "321221", "312212", "322112", "322211",
    "212123", "212321", "232121", "111323", "131123", "131321", "112313", "132113", "132311", "211313",
    "231113", "231311", "112133", "112331", "132131", "113123", "113321", "133121", "313121", "211331",
    "231131", "213113", "213311", "213131", "311123", "311321", "331121", "312113", "312311", "332111",
    "314111", "221411", "431111", "111224", "111422", "121124", "121421", "141122", "141221", "112214",
    "112412", "122114", "122411", "142112", "142211", "241211", "221114", "413111", "241112", "134111",
    "111242", "121142", "121241", "114212", "124112", "124211", "411212", "421112", "421211", "212141",
    "214121", "412121", "111143", "111341", "131141", "114113", "114311", "411113", "411311", "113141",
    "114131", "311141", "411131", "211412", "211214", "211232", "2331112"
];

const START_B = 104;
const START_C = 105;
const STOP = 106;
const QUIET_ZONE = 10;

export function code128Values(code: string): number[] {
    if (code === "") throw new Error("Cannot encode an empty barcode.");
    let values: number[];
    if (/^\d+$/.test(code) && code.length % 2 === 0) {
        values = [START_C];
        for (let i = 0; i < code.length; i += 2) values.push(Number(code.slice(i, i + 2)));
    } else {
        values = [START_B];
        for (const char of code) {
            const ord = char.charCodeAt(0);
            if (ord < 32 || ord > 126) throw new Error(`Code 128 set B cannot encode [${code}].`);
            values.push(ord - 32);
        }
    }
    let sum = values[0];
    values.slice(1).forEach((value, position) => {
        sum += value * (position + 1);
    });
    values.push(sum % 103);
    return values;
}

export function code128Widths(code: string): number[] {
    return [...code128Values(code), STOP].flatMap((value) => PATTERNS[value].split("").map(Number));
}

export function Code128Barcode({ code, height = 70, moduleWidth = 3 }: { code: string; height?: number; moduleWidth?: number }) {
    const widths = code128Widths(code);
    let x = QUIET_ZONE;
    const bars: React.ReactNode[] = [];
    widths.forEach((width, index) => {
        if (index % 2 === 0) bars.push(<rect key={index} x={x} y={0} width={width} height={height} />);
        x += width;
    });
    const total = x + QUIET_ZONE;
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox={`0 0 ${total} ${height}`}
            width={Math.min(520, total * moduleWidth)}
            height={height}
            preserveAspectRatio="none"
            role="img"
            aria-label={code}
            style={{ display: "block", margin: "0 auto" }}
        >
            <g fill="#000">{bars}</g>
        </svg>
    );
}
