import { ProductType, ProductUnit } from '#enums/ProductEnums'
import ErrorMsgException from '#exceptions/error_msg_exception'
import Category from '#models/Category'
import Printer from '#models/Printer'
import Product from '#models/Product'
import app from '@adonisjs/core/services/app'
import db from '@adonisjs/lucid/services/db'
import ExcelJS from 'exceljs'

export default class ImportFromExcel {
  private async importPrinters(printersData: ExcelJS.Worksheet) {
    const printers: { name: string; ipAddress: string }[] = []
    printersData.eachRow((row, rowNumber) => {
      if (rowNumber > 1) {
        // Skip header row
        const name = row.getCell(1).value as string
        const ipAddr = row.getCell(2).value as string
        if (typeof name !== 'string' || name === '') return
        console.log('name, ipAddr', name, ipAddr)
        printers.push({ name: name.trim(), ipAddress: ipAddr?.trim() })
      }
    })
    // if printer exits skip
    for (const printer of printers) {
      const printerExists = await Printer.findBy('name', printer.name)
      if (printerExists) continue
      await Printer.create(printer)
    }
  }

  private async importCategories(categoriesData: ExcelJS.Worksheet) {
    const categories: string[] = []
    categoriesData.eachRow((row, rowNumber) => {
      if (rowNumber > 1) {
        // Skip header row
        const name = row.getCell(1).value as string
        if (typeof name !== 'string' || name === '') return
        categories.push(name)
      }
    })
    for (const name of categories) {
      const category = await Category.findBy('name', name)
      if (category) continue
      await Category.create({ name })
    }
  }

  private async importRawProducts(rawProductsData: ExcelJS.Worksheet) {
    const rawProducts: { name: string; cost: number; unit: string; categoryName: string }[] = []
    rawProductsData.eachRow((row, rowNumber) => {
      if (rowNumber > 1) {
        // Skip header row
        const name = row.getCell(1).value as string
        const cost = row.getCell(2).value as number
        const unit = row.getCell(3).value as string
        const categoryName = row.getCell(4).value as string
        if (typeof name !== 'string' || name === '') return
        if (typeof cost !== 'number') return
        if (typeof unit !== 'string' || unit === '') return
        if (typeof categoryName !== 'string' || categoryName === '') return
        rawProducts.push({
          name: name.trim(),
          cost,
          unit: unit.trim(),
          categoryName: categoryName.trim(),
        })
      }
    })
    for (const product of rawProducts) {
      let category = await Category.findBy('name', product.categoryName)
      if (!category) category = await Category.create({ name: product.categoryName })
      const price = product.cost
      await Product.firstOrCreate(
        { name: product.name, legacy: false, type: ProductType.RawMaterial },
        {
          name: product.name,
          cost: product.cost,
          unit: product.unit as ProductUnit,
          categoryId: category.id,
          price,
          type: ProductType.RawMaterial,
        }
      )
    }
  }

  private async importConsumableProducts(consumableProductsData: ExcelJS.Worksheet) {
    const consumableProducts: {
      name: string
      cost: number
      price: number
      unit: string
      categoryName: string
      printerName: string
    }[] = []
    consumableProductsData.eachRow((row, rowNumber) => {
      if (rowNumber > 1) {
        // Skip header row
        const name = row.getCell(1).value as string
        const cost = row.getCell(2).value as number
        const price = row.getCell(3).value as number
        const unit = row.getCell(4).value as string
        const categoryName = row.getCell(5).value as string
        const printerName = row.getCell(6).value as string
        if (typeof name !== 'string' || name === '') return
        if (typeof cost !== 'number') return
        if (typeof price !== 'number') return
        if (typeof unit !== 'string' || unit === '') return
        if (typeof categoryName !== 'string' || categoryName === '') return
        if (typeof printerName !== 'string' || printerName === '') return
        consumableProducts.push({
          name: name.trim(),
          cost,
          price,
          unit: unit.trim(),
          categoryName: categoryName.trim(),
          printerName: printerName.trim(),
        })
      }
    })
    for (const product of consumableProducts) {
      let category = await Category.findBy('name', product.categoryName)
      let printer = await Printer.findBy('name', product.printerName)
      if (!category) category = await Category.create({ name: product.categoryName })
      if (!printer) printer = await Printer.create({ name: product.printerName, ipAddress: '' })
      await Product.firstOrCreate(
        { name: product.name, legacy: false, type: ProductType.Consumable },
        {
          name: product.name,
          price: product.price,
          cost: product.cost,
          unit: product.unit as ProductUnit,
          categoryId: category.id,
          printerId: printer.id,
          type: ProductType.Consumable,
        }
      )
    }
  }

  private async importManifacturedProducts(manifacturedProductsData: ExcelJS.Worksheet) {
    const manifacturedProducts: {
      name: string
      price: number
      categoryName: string
      printerName: string
    }[] = []
    manifacturedProductsData.eachRow((row, rowNumber) => {
      if (rowNumber > 1) {
        // Skip header row
        const name = row.getCell(1).value as string
        const price = row.getCell(2).value as number
        const categoryName = row.getCell(3).value as string
        const printerName = row.getCell(4).value as string
        if (typeof name !== 'string' || name === '') return
        if (typeof price !== 'number') return
        if (typeof categoryName !== 'string' || categoryName === '') return
        if (typeof printerName !== 'string' || printerName === '') return
        manifacturedProducts.push({
          name: name.trim(),
          price,
          categoryName: categoryName.trim(),
          printerName: printerName.trim(),
        })
      }
    })
    for (const product of manifacturedProducts) {
      let category = await Category.findBy('name', product.categoryName)
      let printer = await Printer.findBy('name', product.printerName)
      if (!category) category = await Category.create({ name: product.categoryName })
      if (!printer) printer = await Printer.create({ name: product.printerName, ipAddress: '' })
      const rawProductWithSameName = await Product.query()
        .where({ name: product.name, legacy: false, type: ProductType.RawMaterial })
        .first()
      if (rawProductWithSameName) {
        throw new Error(
          'Raw Product with the same name already exists ' + rawProductWithSameName.name
        )
      }
      await Product.firstOrCreate(
        { name: product.name, legacy: false, type: ProductType.Manifactured },
        {
          name: product.name,
          cost: 0,
          price: product.price,
          unit: ProductUnit.Packet,
          categoryId: category.id,
          printerId: printer.id,
          type: ProductType.Manifactured,
        }
      )
    }
  }

  private async importRecipes(recipesData: ExcelJS.Worksheet) {
    const recipes: { manifacturedProduct: string; component: string; quantity: number }[] = []
    recipesData.eachRow((row, rowNumber) => {
      if (rowNumber > 1) {
        // Skip header row
        const manifacturedProduct = row.getCell(1).value as string
        const component = row.getCell(2).value as string
        const quantity = row.getCell(3).value as string
        if (typeof manifacturedProduct !== 'string' || manifacturedProduct === '') return
        if (typeof component !== 'string' || component === '') return
        if (typeof quantity !== 'number') return
        recipes.push({
          manifacturedProduct: manifacturedProduct.trim(),
          component: component.trim(),
          quantity,
        })
      }
    })
    const setOfProducts = new Set<string>()
    recipes.forEach((recipe) => {
      setOfProducts.add(recipe.manifacturedProduct)
      setOfProducts.add(recipe.component)
    })

    const products = await Product.query().whereIn('name', Array.from(setOfProducts))
    const productsMap = new Map<string, number>()
    products.forEach((product) => {
      productsMap.set(product.name, product.id)
    })

    // delete all records in components table
    await db.rawQuery('DELETE FROM product_components')
    for (const recipe of recipes) {
      const manifacturedProduct = productsMap.get(recipe.manifacturedProduct)
      const component = productsMap.get(recipe.component)
      if (!manifacturedProduct || !component) continue
      await db.rawQuery(
        'INSERT INTO product_components (product_id, component_id, quantity) VALUES (?, ?, ?)',
        [manifacturedProduct, component, recipe.quantity]
      )
    }

    const manifacturedProducts = await Product.query()
      .preload('components')
      .where('type', ProductType.Manifactured)
    for (const product of manifacturedProducts) {
      let cost = 0
      product.components.forEach((component) => {
        cost += component.cost * component.$extras.pivot_quantity
      })
      product.cost = cost
      await product.save()
    }
  }

  public async importData(fileName: string) {
    let workbook = new ExcelJS.Workbook()
    await workbook.xlsx.readFile(app.publicPath(`uploads/${fileName}`))

    const printersData = workbook.getWorksheet('طابعة')
    if (printersData === undefined) throw new ErrorMsgException('شيت "طابعة" غير موجود')
    await this.importPrinters(printersData)

    const categoriesData = workbook.getWorksheet('الفئات')
    if (categoriesData === undefined) throw new ErrorMsgException('شيت "الفئات" غير موجود')
    await this.importCategories(categoriesData)

    const rawProductsData = workbook.getWorksheet('الخام')
    if (rawProductsData === undefined) throw new ErrorMsgException('شيت "الخام" غير موجود')
    await this.importRawProducts(rawProductsData)

    const consumableProductsData = workbook.getWorksheet('الاستهلاكية')
    if (consumableProductsData === undefined)
      throw new ErrorMsgException('شيت "الاستهلاكية" غير موجود')
    await this.importConsumableProducts(consumableProductsData)

    const manifacturedProductsData = workbook.getWorksheet('التصنيع')
    if (manifacturedProductsData === undefined)
      throw new ErrorMsgException('شيت "التصنيع" غير موجود')
    await this.importManifacturedProducts(manifacturedProductsData)

    const recipes = workbook.getWorksheet('المعياري')
    if (recipes === undefined) throw new ErrorMsgException('شيت "المعياري" غير موجود')
    await this.importRecipes(recipes)
  }
}
