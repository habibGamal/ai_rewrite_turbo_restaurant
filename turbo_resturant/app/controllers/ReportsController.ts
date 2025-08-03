import { HttpContext } from '@adonisjs/core/http'
import HelperService from '#services/HelperService'
import ReportsService from '#services/reports/ReportsService'
import ErrorMsgException from '#exceptions/error_msg_exception'
import { UserRole } from '#enums/UserEnums'
import Shift from '#models/Shift'
import { ShiftsReportService } from '#services/reports/ShiftsReportService'
import layout from '#helpers/layout'

export default class ReportsController {
  public async clientsReport({ inertia, request }: HttpContext) {
    const { fromDt, toDt } = HelperService.period(request.qs().from, request.qs().to)
    const customers = await ReportsService.clientsReport(fromDt, toDt)
    return inertia.render('Reports/ClientsReport' + layout(), {
      customers,
    })
  }

  public async productsReport({ inertia, request }: HttpContext) {
    const { fromDt, toDt } = HelperService.period(request.qs().from, request.qs().to)
    const products = await ReportsService.productsReport(fromDt, toDt)
    return inertia.render('Reports/ProductsReport' + layout(), {
      products,
    })
  }

  public async detailedReport({ inertia, request }: HttpContext) {
    const { fromDt, toDt } = HelperService.period(request.qs().from, request.qs().to)
    const data = await ReportsService.detailedReport(fromDt, toDt)
    return inertia.render('Reports/DetailedReport' + layout(), data)
  }

  public async shiftsReport({ inertia, request }: HttpContext) {
    const { fromDt, toDt } = HelperService.period(request.qs().from, request.qs().to)
    const shifts = await ReportsService.shiftsReport(fromDt, toDt)

    return inertia.render('Reports/ShiftsReport' + layout(), {
      shifts,
    })
  }

  public async shiftsLogs({ inertia, request }: HttpContext) {
    const { fromDt, toDt } = HelperService.period(request.qs().from, request.qs().to)
    const shifts = await Shift.query()
      .select('id', 'start_at')
      .whereBetween('start_at', [fromDt, toDt])
      .orderBy('start_at', 'desc')

    return inertia.render('Reports/ShiftsLogs' + layout(), {
      shifts,
    })
  }

  public async shiftReport({ params, inertia }: HttpContext) {
    const shift = await ReportsService.shiftReport(params.id)

    return inertia.render('Reports/ShiftReport' + layout(), {
      shift,
    })
  }

  public async fullShiftsReport({ inertia, request }: HttpContext) {
    const { fromDt, toDt } = HelperService.period(request.qs().from, request.qs().to)
    const shifts = await Shift.query().select('id').whereBetween('start_at', [fromDt, toDt])
    const report = new ShiftsReportService(shifts)

    return inertia.render('Reports/FullShiftsReport' + layout(), {
      statistics: await report.statistics(),
      main: async () => await report.orders(),
      ordersByPaymentMethod: inertia.lazy(() => report.ordersByPaymentMethod()),
      ordersByStatusOrType: inertia.lazy(() => report.ordersByStatusOrType()),
      statisticsByStatusOrType: inertia.lazy(() => report.statisticsByStatusOrType()),
      expenses: inertia.lazy(() => report.expenses()),
      ordersHasDiscounts: inertia.lazy(() => report.ordersHasDiscounts()),
    })
  }

  public async currentShiftReport({ inertia }: HttpContext) {
    // get last shift

    const shift = await Shift.query().select('id','startCash').orderBy('id', 'desc').first()
    // console.log(shift.id)
    if (shift === null) throw new ErrorMsgException('لا يوجد شيفت مفتوح')
    const report = new ShiftsReportService([shift])


    return inertia.render('Reports/CurrentShiftReport' + layout(), {
      statistics: await report.statistics(),
      main: async () => await report.orders(),
      ordersByPaymentMethod: inertia.lazy(() => report.ordersByPaymentMethod()),
      ordersByStatusOrType: inertia.lazy(() => report.ordersByStatusOrType()),
      statisticsByStatusOrType: inertia.lazy(() => report.statisticsByStatusOrType()),
      expenses: inertia.lazy(() => report.expenses()),
      ordersHasDiscounts: inertia.lazy(() => report.ordersHasDiscounts()),
    })
  }

  public async expensesReport({ inertia, request }: HttpContext) {
    const { fromDt, toDt } = HelperService.period(request.qs().from, request.qs().to)
    const expenses = await ReportsService.expensesReport(fromDt, toDt)
    return inertia.render('Reports/ExpensesReport' + layout(), {
      expenses,
    })
  }

  public async stockReport({ inertia, request }: HttpContext) {
    const { fromDt, toDt } = HelperService.period(request.qs().from, request.qs().to)
    const data = await ReportsService.stockReport(fromDt, toDt)

    return inertia.render('Reports/ResturantReport' + layout(), data)
  }

  public async driversReport({ inertia, request }: HttpContext) {
    const { fromDt, toDt } = HelperService.period(request.qs().from, request.qs().to)
    const drivers = await ReportsService.driversReport(fromDt, toDt)
    return inertia.render('Reports/DriversReport' + layout(), {
      drivers,
    })
  }
}
