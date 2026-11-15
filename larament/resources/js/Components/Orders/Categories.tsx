import React, { useMemo, useState } from 'react';
import { Button, ConfigProvider, Divider, Radio, RadioChangeEvent, Typography } from 'antd';
import { Input } from 'antd';

import { Category, Product, ProductGroupTile, User, OrderItemAction, OrderItemData } from '@/types';
import VariantPickerModal, { formatPriceRange } from '@/Components/Orders/VariantPickerModal';

const { Search } = Input;

interface CategoriesProps {
    categories: Category[];
    orderType: string;
    dispatch: React.Dispatch<OrderItemAction>;
    disabled?: boolean;
    user: User;
}

function isBlocked(category: Category, orderType: string): boolean {
    return (category.blocked_order_type_codes ?? []).includes(orderType);
}

function visibleChildren(category: Category, orderType: string): Category[] {
    if (isBlocked(category, orderType)) {
        return [];
    }

    return (category.children ?? []).filter((child) => !isBlocked(child, orderType));
}

/** A category's own products plus the SKUs of its variant groups (scanner and cart lookups need them). */
function sellables(category: Category, withGroupSkus: boolean): Product[] {
    if (!withGroupSkus) return category.products;

    return [...category.products, ...(category.groups ?? []).flatMap((group) => group.skus)];
}

/**
 * Products sellable on this order type. Variant SKUs inside group tiles are included
 * unless `withGroupSkus` is false (the tile grid shows those through their group).
 */
export function productsForOrder(
    categories: Category[],
    orderType: string,
    includeBlocked: boolean,
    withGroupSkus = true,
): Product[] {
    return categories.flatMap((category) => {
        const categoryBlocked = isBlocked(category, orderType);
        const own = includeBlocked || !categoryBlocked ? sellables(category, withGroupSkus) : [];
        const children = (category.children ?? []).flatMap((child) => {
            const childBlocked = categoryBlocked || isBlocked(child, orderType);

            return includeBlocked || !childBlocked ? sellables(child, withGroupSkus) : [];
        });

        return [...own, ...children];
    });
}

function groupsForOrder(categories: Category[], orderType: string): ProductGroupTile[] {
    return categories.flatMap((category) => {
        if (isBlocked(category, orderType)) return [];

        return [
            ...(category.groups ?? []),
            ...visibleChildren(category, orderType).flatMap((child) => child.groups ?? []),
        ];
    });
}

export default function Categories({ categories, orderType, dispatch, disabled, user }: CategoriesProps) {
    const visibleMains = useMemo(
        () => categories.filter((category) => !isBlocked(category, orderType)),
        [categories, orderType],
    );
    const [selectedMain, setSelectedMain] = useState<number | 'all'>('all');
    const [selectedSub, setSelectedSub] = useState<number | 'all'>('all');
    const [search, setSearch] = useState('');
    const [pickerGroup, setPickerGroup] = useState<ProductGroupTile | null>(null);

    const activeMain = selectedMain === 'all'
        ? null
        : visibleMains.find((category) => category.id === selectedMain) ?? null;
    const subs = activeMain ? visibleChildren(activeMain, orderType) : [];

    const baseProducts = useMemo(() => {
        if (!activeMain) {
            return productsForOrder(visibleMains, orderType, false, false);
        }

        if (selectedSub === 'all') {
            return [
                ...activeMain.products,
                ...subs.flatMap((sub) => sub.products),
            ];
        }

        return subs.find((sub) => sub.id === selectedSub)?.products ?? [];
    }, [activeMain, selectedSub, subs, visibleMains, orderType]);

    const baseGroups = useMemo(() => {
        if (!activeMain) {
            return groupsForOrder(visibleMains, orderType);
        }

        if (selectedSub === 'all') {
            return [
                ...(activeMain.groups ?? []),
                ...subs.flatMap((sub) => sub.groups ?? []),
            ];
        }

        return subs.find((sub) => sub.id === selectedSub)?.groups ?? [];
    }, [activeMain, selectedSub, subs, visibleMains, orderType]);

    const term = search.toLowerCase();
    const products = baseProducts.filter((product) =>
        product.name.toLowerCase().includes(term),
    );
    const groups = baseGroups.filter((group) =>
        group.name.toLowerCase().includes(term)
        || group.skus.some((sku) => sku.name.toLowerCase().includes(term)),
    );

    const onChangeCategory = ({ target: { value } }: RadioChangeEvent) => {
        setSelectedMain(value);
        setSelectedSub('all');
        setSearch('');
    };

    const onChangeSub = ({ target: { value } }: RadioChangeEvent) => {
        setSelectedSub(value);
        setSearch('');
    };

    const onAddProduct = (product: Product) => {
        dispatch({
            type: 'add',
            orderItem: {
                product_id: product.id,
                name: product.name,
                price: product.price,
                quantity: 1,
                initial_quantity: undefined,
                product,
            },
            user,
        });
    };

    return (
        <div className="isolate">
            <div className="flex justify-between mb-4">
                <Typography.Title className="mt-0" level={5}>
                    الاصناف
                </Typography.Title>
                <Search
                    style={{ width: 200 }}
                    placeholder="بحث"
                    allowClear
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    onSearch={setSearch}
                />
            </div>

            <ConfigProvider
                theme={{
                    token: {
                        borderRadius: 4,
                    },
                }}
            >
                <Radio.Group
                    className="grid grid-cols-4 text-center gap-4"
                    onChange={onChangeCategory}
                    size="large"
                    value={activeMain ? activeMain.id : 'all'}
                    buttonStyle="solid"
                >
                    <Radio.Button className="rounded before:!hidden border-none" value="all">
                        الكل
                    </Radio.Button>
                    {visibleMains.map((category) => (
                        <Radio.Button
                            disabled={disabled}
                            key={category.id}
                            className="rounded whitespace-nowrap overflow-hidden text-ellipsis before:!hidden border-none"
                            value={category.id}
                        >
                            {category.name}
                        </Radio.Button>
                    ))}
                </Radio.Group>

                {activeMain && subs.length > 0 && (
                    <div className="mt-4">
                        <Typography.Text type="secondary">التصنيفات الفرعية</Typography.Text>
                        <Radio.Group
                            className="grid grid-cols-4 text-center gap-3 mt-2"
                            onChange={onChangeSub}
                            value={selectedSub}
                            buttonStyle="solid"
                        >
                            <Radio.Button className="rounded before:!hidden border-none" value="all">
                                الكل
                            </Radio.Button>
                            {subs.map((category) => (
                                <Radio.Button
                                    disabled={disabled}
                                    key={category.id}
                                    className="rounded whitespace-nowrap overflow-hidden text-ellipsis before:!hidden border-none"
                                    value={category.id}
                                >
                                    {category.name}
                                </Radio.Button>
                            ))}
                        </Radio.Group>
                    </div>
                )}
            </ConfigProvider>

            <Divider />

            <div className="grid grid-cols-4 gap-4">
                {groups.map((group) => (
                    <Button
                        disabled={disabled}
                        key={`group-${group.id}`}
                        type="primary"
                        onClick={() => setPickerGroup(group)}
                        className="w-full h-full min-h-[100px] whitespace-normal bg-indigo-900"
                        style={{ height: 'auto', minHeight: '100px' }}
                    >
                        <span className="flex flex-col items-center gap-1">
                            <span className="text-xl font-bold">{group.name}</span>
                            <span className="text-sm opacity-80" dir="ltr">{formatPriceRange(group)}</span>
                        </span>
                    </Button>
                ))}
                {products.map((product) => (
                    <Button
                        disabled={disabled}
                        key={product.id}
                        type="primary"
                        onClick={() => onAddProduct(product)}
                        className="w-full h-full min-h-[100px] text-xl font-bold whitespace-normal bg-gray-800"
                        style={{ height: 'auto', minHeight: '100px' }}
                    >
                        {product.name}
                    </Button>
                ))}
            </div>

            <VariantPickerModal
                group={pickerGroup}
                onClose={() => setPickerGroup(null)}
                onPick={onAddProduct}
            />
        </div>
    );
}
