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
import ReceiptTemplate from '~/components/Print/ReceiptTemplate.js'
import printTemplate, { printOrder } from '~/helpers/printTemplate.js'
import IsAdmin from '../../components/IsAdmin.js'
import Categories from '../../components/ManageOrder/Categories.js'
import ChangeOrderTypeModal from '../../components/ManageOrder/ChangeOrderType.js'
import CustomerModal from '../../components/ManageOrder/CustomerModal.js'
import DriverModal from '../../components/ManageOrder/DriverModal.js'
import OrderDiscountModal from '../../components/ManageOrder/OrderDiscount.js'
import OrderItem from '../../components/ManageOrder/OrderItem.js'
import OrderNotesModal from '../../components/ManageOrder/OrderNotes.js'
import PaymentModal from '../../components/ManageOrder/PaymentModal.js'
import PrintInKitchen from '../../components/ManageOrder/PrintInKitchen.js'
import {
  orderItemsReducer,
  orderPaymentItems,
  orderPaymentValues,
} from '../../helpers/ManageOrder.js'
import { orderHeader } from '../../helpers/orderHeader.js'
import { orderStatus } from '../../helpers/orderState.js'
import useModal from '../../hooks/useModal.js'
import CashierLayout from '../../layouts/CashierLayout.js'
import { Category, Order, User } from '../../types/Models.js'
import { OrderItemT } from '../../types/Types.js'
import PartialReceiptTemplate, { PartType } from '~/components/Print/PartialReceiptTemplate.js'

export default function ManageOrder({
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

  const [customerInfoSkip, setCustomerInfoSkip] = useState<boolean>(false)

  const customerModal = useModal()

  const orderNotesModal = useModal()

  const orderDiscountModal = useModal()

  const orderTypeModal = useModal()

  const paymentModal = useModal()

  const printInKitchenModal = useModal()

  const driverModal = useModal()

  const save = (callback: (page: any) => void = () => {}) => {
    router.post(
      `/orders/save-order/${order.id}`,
      { items: orderItems },
      {
        onSuccess: (page) => callback(page),
      }
    )
  }

  const cancelOrder = () => {
    router.post(`/orders/cancel-completed-order/${order.id}`)
  }

  const { modal } = App.useApp()

  const askForCustomerInfo = () => {
    modal.confirm({
      title: 'هل تريد اضافة بيانات العميل؟',
      icon: <UserAddOutlined />,
      content: 'اضغط على "نعم" لاضافة بيانات العميل',
      okText: 'نعم',
      cancelText: 'لا',
      onOk: () => customerModal.showModal(),
      onCancel: () => skipCustomerInfo(),
    })
  }

  const tryCompleteOrder = () => {
    if (!order.customer && !customerInfoSkip) {
      return save(() => askForCustomerInfo())
    }
    payment()
  }

  const payment = () => {
    save(() => paymentModal.showModal())
  }

  const skipCustomerInfo = () => {
    setCustomerInfoSkip(true)
    customerModal.closeModal()
    payment()
  }

  const printInKitchen = () => {
    save(() => printInKitchenModal.showModal())
  }

  const printReceipt = () => {
    console.log(orderItems)
    save(() =>
      router.get(`/print/${order.id}`, undefined, {
        preserveState: true,
      })
    )
  }

  const disableAllControls = order.status !== OrderStatus.Processing
  const orderCancelled = order.status === OrderStatus.Cancelled
  const orderInProcess = order.status === OrderStatus.Processing
  const orderCompleted = order.status === OrderStatus.Completed
  const isDineIn = order.type === OrderType.DineIn
  const isTakeAway = order.type === OrderType.Takeaway
  const isDelivery = order.type === OrderType.Delivery

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
              // onClick={printReceipt}
              onClick={() => printWithCanvas()}
              // disabled={orderInProcess}
              size="large"
              icon={<PrinterOutlined />}
            >
              طباعة الفاتورة
            </Button>
            <Button
              disabled={orderCancelled}
              onClick={() => printInKitchen()}
              size="large"
              icon={<PrinterOutlined />}
            >
              طباعة في المطبخ
            </Button>
            <Button
              onClick={() => customerModal.showModal()}
              disabled={orderCancelled}
              size="large"
              className={isDelivery ? '' : 'col-span-2'}
              icon={<UserAddOutlined />}
            >
              بيانات العميل
            </Button>
            {isDelivery && (
              <Button
                onClick={() => driverModal.showModal()}
                disabled={orderCancelled}
                size="large"
                icon={<UserAddOutlined />}
              >
                بيانات السائق
              </Button>
            )}
            <Button
              onClick={() => orderNotesModal.showModal()}
              disabled={orderCancelled}
              size="large"
              icon={<EditOutlined />}
            >
              ملاحظات الطلب
            </Button>
            <Button
              disabled={disableAllControls}
              onClick={() => orderTypeModal.showModal()}
              size="large"
              icon={<EditOutlined />}
            >
              تغيير الطلب الى
            </Button>
            <IsAdmin>
              <Button
                disabled={disableAllControls}
                onClick={() => save(() => orderDiscountModal.showModal())}
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
            <Button
              disabled={disableAllControls}
              onClick={tryCompleteOrder}
              size="large"
              icon={<CheckCircleOutlined />}
              type="primary"
            >
              انهاء الطلب
            </Button>
            {orderCompleted && (
              <IsAdmin>
                <Popconfirm
                  title="هل انت متأكد من الغاء الطلب؟"
                  okText="نعم"
                  cancelText="لا"
                  onConfirm={cancelOrder}
                >
                  <Button className="col-span-2" disabled={orderCancelled} size="large" danger>
                    الغاء
                  </Button>
                </Popconfirm>
              </IsAdmin>
            )}
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
              />
            ))}
            <Divider />
            <Descriptions
              bordered
              title="الحساب"
              column={1}
              items={orderPaymentItems(
                orderPaymentValues(order, orderItems, order.discount, 'value')
              )}
            />
          </div>
        </Col>
        <Col span={16}>
          <Categories disabled={disableAllControls} categories={categories} dispatch={dispatch} />
        </Col>
      </Row>

      {/* <ChooseTableForm tableModal={tableModal} onFinish={changeTable} /> */}
      <CustomerModal customerModal={customerModal} order={order} />
      {/* <KitchenNotesModal kitchenModal={kitchenModal} order={order} /> */}
      <DriverModal driverModal={driverModal} order={order} />
      <ChangeOrderTypeModal changeOrderTypeModal={orderTypeModal} order={order} />
      <OrderDiscountModal orderDiscountModal={orderDiscountModal} order={order} />
      <OrderNotesModal orderNotesModal={orderNotesModal} order={order} />
      <PaymentModal paymentModal={paymentModal} order={order} orderItems={orderItems} />
      <PrintInKitchen
        printInKitchenModal={printInKitchenModal}
        order={order}
        orderItems={orderItems}
      />
    </div>
  )
}

ManageOrder.layout = (page: any) => <CashierLayout children={page} />
