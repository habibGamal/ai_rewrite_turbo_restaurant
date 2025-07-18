import { ProductType } from '#enums/ProductEnums'
import DailySnapshot from '#models/DailySnapshot'
import Product from '#models/Product'
import PurchaseInvoice from '#models/PurchaseInvoice'
import ReturnPurchaseInvoice from '#models/ReturnPurchaseInvoice'
import Shift from '#models/Shift'
import Stocktaking from '#models/Stocktaking'
import Waste from '#models/Waste'
import ErrorMsgException from '#exceptions/error_msg_exception'
export default class DailySnapshotService {
  public static async initializeFirstDay() {
    // create new day
    await DailySnapshot.create({
      data: [],
    })
  }

  public static async startAcounting() {
    // if count of days is more than 1 give error
    const count = await DailySnapshot.query().pojo<{ total: number }>().count('id as total')
    if (count[0].total > 1) throw new ErrorMsgException('غير مسموح')
    // get last day which is first day created in the system
    const lastDay = await DailySnapshot.query().orderBy('id', 'desc').firstOrFail()
    if (lastDay.closed) throw new ErrorMsgException('يوم مغلق')
    // get products with inventory level
    const items = await Product.query()
      .select('id', 'cost')
      .whereNot('type', ProductType.Manifactured)
      .preload('inventoryItem')
    // prepare data
    const data = items.map((item) => ({
      product_id: item.id,
      start_quantity: item.inventoryItem.quantity,
      end_quantity: item.inventoryItem.quantity,
      cost: item.cost,
    }))
    // update last day data
    lastDay.data = data
    await lastDay.save()
  }

  public static async openNewDay() {
    // get last day
    const lastDay = await DailySnapshot.query().orderBy('id', 'desc').firstOrFail()
    // prepare data
    const data = lastDay.data.map((item) => ({ ...item, start_quantity: item.end_quantity }))
    // create new day
    await DailySnapshot.create({ data })
  }

  private static async checkAllModelsClosed() {
    const opened = [
      await PurchaseInvoice.query().where('closed', false).first(),
      await ReturnPurchaseInvoice.query().where('closed', false).first(),
      await Stocktaking.query().where('closed', false).first(),
      await Waste.query().where('closed', false).first(),
      await Shift.query().where('closed', false).first(),
    ]
    for (const item of opened) {
      if (item === null) continue
      switch (item.constructor.name) {
        case 'PurchaseInvoice':
          throw new ErrorMsgException('برجاء اغلاق فاتورة الشراء')
        case 'ReturnPurchaseInvoice':
          throw new ErrorMsgException('برجاء اغلاق فاتورة المرتجع')
        case 'Stocktaking':
          throw new ErrorMsgException('برجاء اغلاق الجرد')
        case 'Waste':
          throw new ErrorMsgException('برجاء اغلاق الهالك')
        case 'Shift':
          throw new ErrorMsgException('برجاء اغلاق الوردية')
      }
    }
  }

  public static async closeDay() {
    // if current time less than 12:00 AM give error
    // const currentDate = new Date()
    // if (currentDate.getHours() < 24) throw new ErrorMsgException('برجاء الانتظار حتى الساعة 12:00 منتصف الليل')
    // ensure all stock levels controllers closed
    await this.checkAllModelsClosed()
    // get last day
    const lastDay = await DailySnapshot.query().orderBy('id', 'desc').firstOrFail()
    if (lastDay.closed) throw new ErrorMsgException('يوم مغلق')
    // get products with inventory level
    const products = await Product.query()
      .whereNot('type', ProductType.Manifactured)
      .select('id', 'cost')
      .preload('inventoryItem')
    // prepare data
    const data = products.map((product) => {
      const startQuantity =
        lastDay.data.find((item) => item.product_id === product.id)?.start_quantity ?? 0
      return {
        product_id: product.id,
        start_quantity: startQuantity,
        end_quantity: product.inventoryItem.quantity,
        cost: product.cost,
      }
    })
    // update last day
    lastDay.data = data
    lastDay.closed = true
    await lastDay.save()
  }

  public static async exitIfDayClosed() {
    const lastDay = await DailySnapshot.query().orderBy('id', 'desc').first()
    if (lastDay === null || lastDay.closed) throw new ErrorMsgException('يجب فتح اليوم اولاً')
  }

  public static async dayIsOpen() {
    const lastDay = await DailySnapshot.query().orderBy('id', 'desc').first()
    return lastDay === null ? false : !lastDay.closed
  }

  public static async allowStartAccounting() {
    const lastDay = await DailySnapshot.query().orderBy('id', 'desc').first()
    const count = await DailySnapshot.query().pojo<{ total: number }>().count('id as total')
    return count[0].total === 1 && !lastDay?.closed
  }

  public static async getTodaySnapshot() {
    const lastDay = await DailySnapshot.query().orderBy('id', 'desc').first()
    if (lastDay === null) throw new ErrorMsgException('لا يوجد يوم مفتوح')
    return lastDay
  }
}
