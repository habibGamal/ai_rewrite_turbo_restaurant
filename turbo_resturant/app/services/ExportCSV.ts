import app from '@adonisjs/core/services/app'
import { createObjectCsvWriter } from 'csv-writer'
import Excel from 'exceljs'
import fs from 'fs'

/** Exports data and columns to a spreadsheet */
export async function exportToCSV(
  name: string,
  columns: string[],
  data: { [key: string]: any }[],
  options?: { columnsTitles?: string[]; forceName?: string }
): Promise<{ success: boolean; path?: string }> {
  try {
    /** Dated Filename - i.e posts-1681511826821.csv **/
    const fileName = options?.forceName
      ? `${options.forceName}.csv`
      : `${name}-${new Date().valueOf()}.csv`
    const path = app.publicPath(`exports/${fileName}`)
    fs.mkdirSync(app.publicPath('exports'), { recursive: true }, (err) => {})
    const csvWriter = createObjectCsvWriter({
      path: path,
      header: columns.map((column, i) => {
        return {
          id: column,
          title: options?.columnsTitles ? options.columnsTitles[i] : column.toUpperCase(),
        }
      }),
    })

    /** Map each table column with column inside columns array **/
    const formattedData = data.map((item) => {
      const obj = {}

      columns.map((column) => {
        return (obj[column] = item[column])
      })

      return obj
    })

    await csvWriter.writeRecords(formattedData)

    // create xlsx file
    const workbook = new Excel.Workbook()
    await workbook.csv.readFile(app.publicPath(`exports/${fileName}`))
    await workbook.xlsx.writeFile(app.publicPath(`exports/${fileName}.xlsx`))

    return {
      success: true,
      path: fileName,
    }
  } catch (error) {
    console.log(error)
    return {
      success: false,
    }
  }
}
