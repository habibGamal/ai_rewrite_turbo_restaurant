import React, { useEffect, useMemo, useState } from 'react';
import { Button, Modal, Tag, Typography } from 'antd';

import { ProductGroupTile, ProductOptionTile, VariantSku } from '@/types';

interface VariantPickerModalProps {
    group: ProductGroupTile | null;
    onClose: () => void;
    onPick: (sku: VariantSku) => void;
}

type Selection = Record<number, number>;

export function formatPriceRange(group: ProductGroupTile): string {
    const min = Number(group.min_price);
    const max = Number(group.max_price);

    return min === max ? `${min}` : `${min} - ${max}`;
}

function matches(sku: VariantSku, selection: Selection): boolean {
    return Object.values(selection).every((valueId) => sku.option_value_ids.includes(valueId));
}

/** Options that at least one SKU uses, with values no SKU uses removed. */
function usableOptions(group: ProductGroupTile): ProductOptionTile[] {
    const used = new Set(group.skus.flatMap((sku) => sku.option_value_ids));

    return group.options
        .map((option) => ({ ...option, values: option.values.filter((value) => used.has(value.id)) }))
        .filter((option) => option.values.length > 0);
}

function formatStock(quantity: number): string {
    return Number.isInteger(quantity) ? `${quantity}` : quantity.toFixed(2);
}

export default function VariantPickerModal({ group, onClose, onPick }: VariantPickerModalProps) {
    const [selection, setSelection] = useState<Selection>({});
    const options = useMemo(() => (group ? usableOptions(group) : []), [group]);

    useEffect(() => {
        setSelection({});
    }, [group]);

    // The first option without a choice is the one the keyboard drives.
    const activeIndex = options.findIndex((option) => selection[option.id] === undefined);

    // SKUs compatible with the choices made on the options before `index`, plus `valueId`.
    const candidates = (index: number, valueId: number): VariantSku[] => {
        if (!group) return [];
        const prior: Selection = {};
        options.slice(0, index).forEach((option) => {
            if (selection[option.id] !== undefined) prior[option.id] = selection[option.id];
        });
        prior[options[index].id] = valueId;

        return group.skus.filter((sku) => matches(sku, prior));
    };

    const choose = (index: number, valueId: number) => {
        if (!group) return;

        const next: Selection = {};
        options.slice(0, index).forEach((option) => {
            if (selection[option.id] !== undefined) next[option.id] = selection[option.id];
        });
        next[options[index].id] = valueId;

        // Later options with a single possible value need no tap.
        for (let i = index + 1; i < options.length; i++) {
            const possible = options[i].values.filter((value) =>
                group.skus.some((sku) => matches(sku, { ...next, [options[i].id]: value.id })),
            );
            if (possible.length !== 1) break;
            next[options[i].id] = possible[0].id;
        }

        const complete = options.every((option) => next[option.id] !== undefined);
        if (complete) {
            const sku = group.skus.find((candidate) => matches(candidate, next));
            if (sku) {
                onPick(sku);
                onClose();
                return;
            }
        }

        setSelection(next);
    };

    // Keyboard: 1-9 picks the nth available chip of the current option, Backspace steps back.
    useEffect(() => {
        if (!group) return;

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Backspace') {
                const lastChosen = [...options].reverse().find((option) => selection[option.id] !== undefined);
                if (lastChosen) {
                    event.preventDefault();
                    const index = options.indexOf(lastChosen);
                    const next: Selection = {};
                    options.slice(0, index).forEach((option) => {
                        next[option.id] = selection[option.id];
                    });
                    setSelection(next);
                }
                return;
            }

            const digit = Number(event.key);
            if (!Number.isInteger(digit) || digit < 1 || digit > 9 || activeIndex < 0) return;
            const available = options[activeIndex].values.filter(
                (value) => candidates(activeIndex, value.id).length > 0,
            );
            const value = available[digit - 1];
            if (value) {
                event.preventDefault();
                choose(activeIndex, value.id);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    });

    return (
        <Modal
            open={group !== null}
            onCancel={onClose}
            footer={null}
            destroyOnClose
            width={640}
            title={
                group && (
                    <div className="flex items-center justify-between gap-4 pe-8">
                        <span className="text-xl">{group.name}</span>
                        <Tag color="blue" className="text-base">
                            {formatPriceRange(group)} ج.م
                        </Tag>
                    </div>
                )
            }
        >
            {group && (
                <div className="flex flex-col gap-5" data-testid="variant-picker">
                    {options.map((option, index) => {
                        const reachable = index <= activeIndex || activeIndex < 0;
                        let shortcut = 0;

                        return (
                            <div key={option.id}>
                                <Typography.Text strong className="block mb-2 text-base">
                                    {option.name}
                                </Typography.Text>
                                <div className="flex flex-wrap gap-2">
                                    {option.values.map((value) => {
                                        const skus = reachable ? candidates(index, value.id) : [];
                                        const exists = skus.length > 0;
                                        // One SKU shows its real (possibly negative) stock; several show what is on hand.
                                        const stock = skus.length === 1
                                            ? Number(skus[0].stock)
                                            : skus.reduce((sum, sku) => sum + Math.max(0, Number(sku.stock)), 0);
                                        const outOfStock = exists && stock <= 0;
                                        const selected = selection[option.id] === value.id;
                                        const singleSku = skus.length === 1 ? skus[0] : null;
                                        if (exists && index === activeIndex) shortcut += 1;

                                        return (
                                            <Button
                                                key={value.id}
                                                size="large"
                                                type={selected ? 'primary' : 'default'}
                                                disabled={!exists}
                                                onClick={() => choose(index, value.id)}
                                                className={`min-w-[72px] h-auto py-2 ${outOfStock && !selected ? 'opacity-50 bg-gray-100' : ''}`}
                                                title={
                                                    singleSku
                                                        ? `${singleSku.name} — ${singleSku.price} ج.م`
                                                        : undefined
                                                }
                                            >
                                                <span className="flex items-center gap-2">
                                                    {index === activeIndex && exists && shortcut <= 9 && (
                                                        <span className="text-xs text-gray-400">{shortcut}</span>
                                                    )}
                                                    {value.color_hex && (
                                                        <span
                                                            aria-hidden
                                                            className="inline-block w-5 h-5 rounded-full border border-gray-300"
                                                            style={{ backgroundColor: value.color_hex }}
                                                        />
                                                    )}
                                                    <span className="text-base font-semibold">{value.value}</span>
                                                    {exists && (
                                                        <span
                                                            dir="ltr"
                                                            className={`text-xs ${selected ? 'opacity-80' : outOfStock ? 'text-red-500' : 'text-gray-500'}`}
                                                        >
                                                            ({formatStock(stock)})
                                                        </span>
                                                    )}
                                                </span>
                                            </Button>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })}
                    <Typography.Text type="secondary" className="text-xs">
                        الأرقام 1-9 لاختيار القيمة، Backspace للرجوع. الرقم بين القوسين هو المخزون المتاح.
                    </Typography.Text>
                </div>
            )}
        </Modal>
    );
}
