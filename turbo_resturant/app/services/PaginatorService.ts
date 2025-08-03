import { HttpContext } from '@adonisjs/core/http'
import { LucidModel, ModelObject, ModelQueryBuilderContract } from '@adonisjs/lucid/types/model'
import { exportToCSV } from './ExportCSV.js'

export class PaginatorService {
  private _slug: string
  private _columns: [{
    key: string[]
    title: string[]
  }]
  private _export?: boolean
  private _total: number
  private _order: 'asc' | 'desc' | undefined
  private _columnKey: string
  private _pageNumber: number
  private _pageSize: number
  private _attribute?: string
  private _search?: string

  constructor() {
    const { request } = HttpContext.getOrFail()
    const queries = request.qs()
    const slug = queries.slug
    this._slug = slug
    this._export = queries['export'] || false
    this._total = queries['total'] || 0
    this._columns = queries['columns'] || []
    this._pageNumber = queries[slug + '_page'] || 1
    this._order = queries[slug + '_order'] || 'asc'
    this._columnKey = queries[slug + '_columnKey'] || 'id'
    this._pageSize = queries[slug + '_pageSize'] || 10
    this._attribute = queries[slug + '_attribute']
    this._search = queries[slug + '_search']
  }

  public async paginate<T extends LucidModel>(
    query: ModelQueryBuilderContract<T>,
    options: { useDefalutSorting: boolean } = {
      useDefalutSorting: true,
    }
  ) {
    if (this._attribute && this._search) {
      if (!this._attribute.includes('.')) {
        // if table name required will be in format table/attribute and we need to replace / with .
        this._attribute = this._attribute.replace('/', '.')
        query.where(this._attribute, 'LIKE', `%${this._search}%`)
      } else {
        const [relation, attribute] = this._attribute.split('.')
        query.whereHas(relation as any, (builder) => {
          builder.where(attribute, 'LIKE', `%${this._search}%`)
        })
      }
    }
    if (options.useDefalutSorting) query.orderBy(this._columnKey, this._order)
    if (this._export) await this.exportCSV((await query.paginate(1, this._total)).serialize().data)
    return (await query.paginate(this._pageNumber, this._pageSize)).serialize()
  }

  private async exportCSV(data: ModelObject[]) {
    const resolvedData = data.map((item) => {
      return {
        ...item,
        ...(item.meta || {}),
      }
    })
    await exportToCSV(
      this._slug,
      this._columns[0].key,
      resolvedData,
      {
        columnsTitles: this._columns[0].title,
        forceName: this._slug,
      }
    )
  }
}
