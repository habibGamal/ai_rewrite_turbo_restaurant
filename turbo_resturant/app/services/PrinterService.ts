import Shift from '#models/Shift'
import { PrinterTypes, ThermalPrinter } from 'node-thermal-printer'
import path from 'path'
import { fileURLToPath } from 'url'
const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
type PrinterServiceProps = {
  network?: string
}
export default class PrinterService {
  private declare printer: ThermalPrinter

  /**
   * @param network - The network address of the printer
   * for example:
   * with ip
   * interface: `tcp://${network}:9100`,
   * shared usb
   * interface: `//192.168.43.119/e100`,
   */
  constructor({ network }: PrinterServiceProps) {
    if (network) this.printer = this.constructFromNetwork(network)
  }

  private constructFromNetwork(network: string) {
    return new ThermalPrinter({
      type: PrinterTypes.EPSON,
      interface: network,
    })
  }

  public async printImgDataUrl(dataUrl: string) {
    const dataUrlToBuffer = (dataUrl: string) => {
      const base64 = dataUrl.split(',')[1]
      return Buffer.from(base64, 'base64')
    }
    this.printer.alignCenter()
    await this.printer.printImageBuffer(dataUrlToBuffer(dataUrl))
    this.printer.cut()
  }



  public async printImgsDataUrl(dataUrl: string[]) {
    const dataUrlToBuffer = (dataUrl: string) => {
      const base64 = dataUrl.split(',')[1]
      return Buffer.from(base64, 'base64')
    }
    this.printer.alignCenter()
    for (const img of dataUrl) {
      await this.printer.printImageBuffer(dataUrlToBuffer(img))
    }
    this.printer.cut();
    // await this.printer.printImageBuffer(dataUrlToBuffer(dataUrl))
  }

  public async shiftReportTemplate(
    shift: Shift,
    ordersCount: number,
    orderesValue: number,
    expensesValue: number,
    deliveryCost: number
  ) {
    // TODO: Implement this method

  }

  public async execute() {
    return  this.printer.execute()
    .catch((e) => console.log(e))
  }
}
