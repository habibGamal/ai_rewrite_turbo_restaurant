import React, { ReactNode } from "react";
import { Link } from "@inertiajs/react";
import { Col, Typography, Badge, Tag, Tooltip } from "antd";
import { CheckCircleOutlined, ClockCircleOutlined, ShoppingCartOutlined } from "@ant-design/icons";
import { OrderTimer } from "./OrderTimer";
import { formatCurrency } from "@/utils/orderCalculations";
import type { Order } from "@/types";

interface OrderCardProps {
    order: Order;
    /** The main title shown in the card header (e.g. table number or order number) */
    headerTitle: ReactNode;
    /** Optional subtitle shown below the header title */
    subtitle?: ReactNode;
    /** Tab-specific content rendered between header and footer */
    children?: ReactNode;
    /** Badge.Ribbon status config - { text, color }. Defaults to orderStatus() helper */
    statusConfig?: { text: string; color: string };
    /** Base URL prefix for the order manage page. Default: `/orders/manage/` */
    linkPrefix?: string;
    /** Whether to show the OrderTimer in the footer. Default: true */
    showFooterTimer?: boolean;
    /** Additional class names for the card container div */
    className?: string;
}

export const OrderCard: React.FC<OrderCardProps> = ({
    order,
    headerTitle,
    subtitle,
    children,
    statusConfig,
    linkPrefix = "/orders/manage/",
    showFooterTimer = true,
    className = "",
}) => {
    const ribbonProps = statusConfig || { text: order.status, color: "default" };

    return (
        <Col span={6}>
            <Link href={`${linkPrefix}${order.id}`}>
                <Badge.Ribbon color={ribbonProps.color} text={ribbonProps.text}>
                    <div
                        className={`rounded border border-gray-200 p-4 ${className}`}
                    >
                        <div className="flex items-start justify-between">
                            <Typography.Title level={4} className="mb-0">
                                {headerTitle}
                            </Typography.Title>
                            <OrderTimer createdAt={order.created_at} />
                        </div>

                        {subtitle && (
                            <Typography.Text className="block text-gray-500">
                                {subtitle}
                            </Typography.Text>
                        )}

                        {children}

                        <div className="border-t pt-3 flex items-center justify-between gap-1">
                            <span className="flex items-center gap-1 text-sm text-gray-600">
                                <ShoppingCartOutlined />
                                {order.items?.length || 0} أصناف
                            </span>
                            {showFooterTimer && (
                                <OrderTimer createdAt={order.created_at} />
                            )}
                            {/* {order.kitchen_status && (
                                <Tooltip
                                    title={order.kitchen_status === 'ready' ? 'جاهز' : 'قيد التحضير'}
                                >
                                    <Tag
                                        color={order.kitchen_status === 'ready' ? 'success' : 'processing'}
                                        icon={order.kitchen_status === 'ready' ? <CheckCircleOutlined /> : <ClockCircleOutlined />}
                                        // className=" text-xs px-1.5  leading-noxsne inline-flex items-center gap-1 font-normal cursor-pointer"
                                    >
                                        {order.kitchen_progress ? `(${order.kitchen_progress.done} من ${order.kitchen_progress.total})` : ''}
                                    </Tag>
                                </Tooltip>
                            )} */}
                            <Typography.Text strong>
                                {formatCurrency(Number(order.total))}
                            </Typography.Text>
                        </div>
                    </div>
                </Badge.Ribbon>
            </Link>
        </Col>
    );
};
