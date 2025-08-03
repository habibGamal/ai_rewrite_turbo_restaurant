import { OrderStatus, OrderType } from '#enums/OrderEnums'
import {
  CheckCircleOutlined,
  EditOutlined,
  PercentageOutlined,
  PrinterOutlined,
  RightOutlined,
  SaveOutlined,
  UserAddOutlined,
} from '@ant-design/icons'
import { router, usePage } from '@inertiajs/react'
import {
  App,
  Badge,
  Breadcrumb,
  Button,
  Col,
  Descriptions,
  Divider,
  Empty,
  Popconfirm,
  Row,
  Typography,
} from 'antd'
import { useEffect, useReducer, useState } from 'react'
import { printOrder } from '~/helpers/printTemplate.js'
import IsAdmin from '../../components/IsAdmin.js'
import Categories from '../../components/ManageOrder/Categories.js'
import DriverModal from '../../components/ManageOrder/DriverModal.js'
import OrderDiscountModal from '../../components/ManageOrder/OrderDiscount.js'
import OrderItem from '../../components/ManageOrder/OrderItem.js'
import OrderNotesModal from '../../components/ManageOrder/OrderNotes.js'
import PrintInKitchen from '../../components/ManageOrder/PrintInKitchen.js'
import { orderItemsReducer } from '../../helpers/ManageOrder.js'
import { orderHeader } from '../../helpers/orderHeader.js'
import { orderStatus } from '../../helpers/orderState.js'
import useModal from '../../hooks/useModal.js'
import CashierLayout from '../../layouts/CashierLayout.js'
import { Category, Order, User } from '../../types/Models.js'
import { OrderItemT } from '../../types/Types.js'
import PaymentModal from '~/components/ManageOrder/PaymentModal.js'
import WebPaymentModal from '~/components/ManageOrder/WebPaymentModal.js'

export default function ManageWebOrder({
  order,
  categories,
}: {
  order: Order
  categories: Category[]
}) {
  const products = categories.flatMap((category) => category.products)
  const user = usePage().props.user as User
  const initOrderItems: OrderItemT[] = order.items.map((orderItem) => ({
    productId: orderItem.productId,
    name: products.find((product) => product.id === orderItem.productId)?.name || '',
    price: parseFloat(orderItem.price),
    quantity: orderItem.quantity,
    notes: orderItem.notes,
    initialQuantity: orderItem.quantity,
  }))

  const [orderItems, dispatch] = useReducer(orderItemsReducer, [])

  useEffect(() => {
    dispatch({ type: 'init', orderItems: initOrderItems, user })
  }, [order.items])

  const orderNotesModal = useModal()

  const orderDiscountModal = useModal()

  const paymentModal = useModal()

  const printInKitchenModal = useModal()

  const driverModal = useModal()

  const acceptOrder = () => router.post(`/web-orders/accept-order/${order.id}`)

  const save = (callback: (page: any) => void = () => {}) => {
    router.post(
      `/web-orders/save-order/${order.id}`,
      { items: orderItems },
      {
        onSuccess: (page) => callback(page),
      }
    )
  }

  const cancelOrder = () => {
    router.post(`/web-orders/reject-order/${order.id}`)
  }

  const outForDelivery = () => router.post(`/web-orders/out-for-delivery/${order.id}`)

  const payment = () => {
    save(() => paymentModal.showModal())
  }

  const printInKitchen = () => {
    save(() => printInKitchenModal.showModal())
  }

  const disableAllControls = ![OrderStatus.Pending, OrderStatus.Processing].includes(order.status)
  const isDelivery = order.type === OrderType.WebDelivery

  useEffect(() => {
    // make shourtcuts for actions: save , printReceipt
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'F8') {
        e.preventDefault()
        save()
      }
      if (e.key === 'F9') {
        e.preventDefault()
        printWithCanvas()
      }
    }
    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
  }, [orderItems])

  const printWithCanvas = async () => {
    save(async (page) => {
      await printOrder(page.props.order, orderItems, page.props.receiptFooter?.[0]?.value)
    })
  }

  const details = [
    {
      key: '1',
      label: 'نوع الطلب',
      children: order.typeString,
    },
    {
      key: '2',
      label: 'رقم  الطلب المرجعي',
      children: order.id,
    },
    {
      key: 'orderNumber',
      label: 'رقم  الطلب',
      children: order.orderNumber,
    },
    {
      key: 'orderNumber',
      label: 'رقم الوردية',
      children: order.shiftId,
    },

    {
      key: '3',
      label: 'تاريخ الطلب',
      children: order.createdAt,
    },
    {
      key: '3',
      label: 'السائق',
      children: order.driver ? order.driver.name : 'لا يوجد',
    },
    {
      key: 'name',
      label: 'اسم العميل',
      children: order.customer ? order.customer.name : 'لا يوجد',
    },
    {
      key: 'phone',
      label: 'رقم العميل',
      children: order.customer ? order.customer.phone : 'لا يوجد',
    },
    {
      key: 'address',
      label: 'عنوان العميل',
      children: order.customer ? order.customer.address : 'لا يوجد',
    },
    {
      key: '6',
      label: 'ملاحظات',
      children: order.orderNotes,
    },
  ]

  const payments = [
    {
      key: 'subTotal',
      label: 'المجموع',
      children: order.subTotal.toFixed(1),
    },
    {
      key: 'tax',
      label: 'الضريبة',
      children: order.tax.toFixed(1),
    },
    {
      key: 'service',
      label: 'الخدمة',
      children: order.service.toFixed(1),
    },
    {
      key: 'discount',
      label: 'الخصم',
      children: order.discount.toFixed(1),
    },
    {
      key: 'webPosDiff',
      label: 'فرق تسعير',
      children: order.webPosDiff.toFixed(1),
    },
    {
      key: 'total',
      label: 'الاجمالي',
      children: order.total.toFixed(1),
    },
  ]

  const btnsState: Record<
    OrderStatus,
    (
      | 'printReciept'
      | 'printKitchen'
      | 'driver'
      | 'notes'
      | 'discount'
      | 'save'
      | 'cancel'
      | 'outForDelivery'
    )[]
  > = {
    pending: ['cancel'],
    processing: ['printReciept', 'printKitchen', 'driver', 'notes', 'discount', 'save', 'cancel'],
    completed: ['cancel', 'printKitchen', 'printReciept'],
    cancelled: [],
    out_for_delivery: ['printReciept', 'driver', 'save', 'cancel'],
  }

  const actionBtn = {
    accept: order.status === OrderStatus.Pending,
    outForDelivery: order.status === OrderStatus.Processing && order.type === OrderType.WebDelivery,
    complete:
      (order.status === OrderStatus.OutForDelivery && order.type === OrderType.WebDelivery) ||
      (order.status === OrderStatus.Processing && order.type === OrderType.WebTakeaway),
  }

  return (
    <div className="p-4">
      <Badge.Ribbon {...orderStatus(order.status)}>
        <div className="isolate flex gap-4 items-center">
          <Button
            onClick={() => router.get('/orders#' + order.type)}
            size="large"
            type="primary"
            icon={<RightOutlined />}
          />
          <Breadcrumb className="text-2xl" separator=">" items={orderHeader(order)} />
        </div>
      </Badge.Ribbon>
      <Row gutter={[16, 16]} className="mt-8">
        <Col span={8}>
          <div className="isolate grid grid-cols-2 gap-4">
            <Button
              onClick={() => printWithCanvas()}
              disabled={!btnsState[order.status].includes('printReciept')}
              size="large"
              icon={<PrinterOutlined />}
            >
              طباعة الفاتورة
            </Button>
            <Button
              disabled={!btnsState[order.status].includes('printKitchen')}
              onClick={() => printInKitchen()}
              size="large"
              icon={<PrinterOutlined />}
            >
              طباعة في المطبخ
            </Button>
            {isDelivery && (
              <Button
                onClick={() => driverModal.showModal()}
                disabled={!btnsState[order.status].includes('driver')}
                size="large"
                icon={<UserAddOutlined />}
              >
                بيانات السائق
              </Button>
            )}
            <Button
              onClick={() => orderNotesModal.showModal()}
              disabled={!btnsState[order.status].includes('notes')}
              size="large"
              icon={<EditOutlined />}
              className={`${isDelivery ? '' : 'col-span-2'}`}
            >
              ملاحظات الطلب
            </Button>
            <IsAdmin>
              <Button
                disabled={!btnsState[order.status].includes('discount')}
                onClick={() => orderDiscountModal.showModal()}
                size="large"
                icon={<PercentageOutlined />}
                className="col-span-2"
              >
                خصم
              </Button>
            </IsAdmin>

            <Button
              disabled={disableAllControls}
              onClick={() => save()}
              size="large"
              icon={<SaveOutlined />}
              type="primary"
            >
              حفظ
            </Button>

            {actionBtn.accept && (
              <Popconfirm
                title="هل انت متأكد من قبول الطلب؟"
                okText="نعم"
                cancelText="لا"
                onConfirm={acceptOrder}
              >
                <Button type="primary" size="large" icon={<CheckCircleOutlined />}>
                  قبول الطلب
                </Button>
              </Popconfirm>
            )}

            {actionBtn.outForDelivery && (
              <Popconfirm title="تأكيد؟" okText="نعم" cancelText="لا" onConfirm={outForDelivery}>
                <Button type="primary" size="large" icon={<CheckCircleOutlined />}>
                  خرج للتوصيل
                </Button>
              </Popconfirm>
            )}

            {actionBtn.complete && (
              <Popconfirm title="تأكيد؟" okText="نعم" cancelText="لا" onConfirm={payment}>
                <Button type="primary" size="large" icon={<CheckCircleOutlined />}>
                  انهاء الطلب
                </Button>
              </Popconfirm>
            )}

            <IsAdmin>
              <Popconfirm
                title="هل انت متأكد من الغاء الطلب؟"
                okText="نعم"
                cancelText="لا"
                onConfirm={cancelOrder}
              >
                <Button
                  className="col-span-2"
                  disabled={!btnsState[order.status].includes('cancel')}
                  size="large"
                  danger
                >
                  الغاء
                </Button>
              </Popconfirm>
            </IsAdmin>
          </div>
          <div className="isolate mt-4">
            <Typography.Title className="mt-0" level={5}>
              تفاصيل الطلب
            </Typography.Title>
            {orderItems.length === 0 && <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />}
            {orderItems.map((orderItem) => (
              <OrderItem
                disabled={disableAllControls}
                key={orderItem.productId}
                orderItem={orderItem}
                dispatch={dispatch}
                forWeb
              />
            ))}
          </div>
        </Col>
        <Col span="16">
          <Row gutter={[16, 16]}>
            <Col span="24" className="isolate-2">
              <Descriptions bordered title="بيانات الطلب" column={2} items={details} />
            </Col>
            <Col span="24" className="isolate-2">
              <Descriptions bordered title="الحساب" column={2} items={payments} />
            </Col>
          </Row>
        </Col>
      </Row>
      <DriverModal driverModal={driverModal} order={order} />
      <OrderDiscountModal orderDiscountModal={orderDiscountModal} order={order} forWeb />
      <WebPaymentModal paymentModal={paymentModal} order={order} />
      <OrderNotesModal orderNotesModal={orderNotesModal} order={order} />
      <PrintInKitchen
        printInKitchenModal={printInKitchenModal}
        order={order}
        orderItems={orderItems}
      />
    </div>
  )
}

ManageWebOrder.layout = (page: any) => <CashierLayout children={page} />
