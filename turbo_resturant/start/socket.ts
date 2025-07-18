import Ws from '#services/WS'
import os from 'os'
import net from 'net'
import { PrinterTypes, ThermalPrinter } from 'node-thermal-printer'
import app from '@adonisjs/core/services/app'

app.ready(() => {
  Ws.boot()
  const io = Ws.io
  io?.on('connection', (socket) => {
    let stopScan = false
    socket.on('scan for interfaces', (data) => {
      const interfaces = os.networkInterfaces()
      const interfacesPairs: { name: string; address: string }[] = []
      Object.entries(interfaces).forEach(([key, value]) => {
        interfacesPairs.push({ name: key, address: value?.[1].address || '' })
      })
      socket.emit('interfaces', interfacesPairs)
    })

    socket.on('scan for printers', async (data) => {
      stopScan = false
      const networkRange = data.address.split('.').slice(0, 3).join('.')
      const activePrinters: string[] = []
      // scan all open port 9100 hosts
      for (let i = 1; i < 255; i++) {
        const printer = networkRange + '.' + i
        const alive = await checkConnection(printer)
        if (alive) {
          activePrinters.push(printer)
          socket.emit('printers', activePrinters)
        }
        socket.emit('scan progress', printer + '/254')
        if (stopScan) break
      }
    })
    socket.on('stop scan for printers', () => {
      stopScan = true
    })
  })
})

/**
 * Listen for incoming socket connections
 */
// Ws.io.on('connection', (socket) => {
//   let stopScan = false
//   socket.on('scan for interfaces', (data) => {
//     const interfaces = os.networkInterfaces()
//     const interfacesPairs: { name: string; address: string }[] = []
//     Object.entries(interfaces).forEach(([key, value]) => {
//       interfacesPairs.push({ name: key, address: value?.[1].address || '' })
//     })
//     socket.emit('interfaces', interfacesPairs)
//   })

//   socket.on('scan for printers', async (data) => {
//     stopScan = false
//     const networkRange = data.address.split('.').slice(0, 3).join('.')
//     const activePrinters: string[] = []
//     // scan all open port 9100 hosts
//     for (let i = 1; i < 255; i++) {
//       const printer = networkRange + '.' + i
//       const alive = await checkConnection(printer)
//       if (alive) {
//         activePrinters.push(printer)
//         socket.emit('printers', activePrinters)
//       }
//       socket.emit('scan progress', printer + '/254')
//       if (stopScan) break
//     }
//   })
//   socket.on('stop scan for printers', () => {
//     stopScan = true
//   })
// })

const checkConnection = async (hostname: string) => {
  return await new ThermalPrinter({
    type: PrinterTypes.EPSON,
    interface: `tcp://${hostname}:9100`,
    options: {
      timeout: 200,
    },
  }).isPrinterConnected()
}
