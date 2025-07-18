import { HttpContext } from '@adonisjs/core/http'
import { LucidModel, ModelQueryBuilderContract } from '@adonisjs/lucid/types/model'

export class RenderSuitePagination {
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
    this._pageNumber = queries[slug + '_page'] || 1
    this._order = queries[slug + '_order'] || 'asc'
    this._columnKey = queries[slug + '_columnKey'] || 'id'
    this._pageSize = queries[slug + '_pageSize'] || 10
    this._attribute = queries[slug + '_attribute']
    this._search = queries[slug + '_search']
  }

  public async paginate<T extends LucidModel>(query: ModelQueryBuilderContract<T>) {
    if (this._attribute && this._search) {
      if (!this._attribute.includes('.')) query.where(this._attribute, 'LIKE', `%${this._search}%`)
      else {
        const [relation, attribute] = this._attribute.split('.')
        query.whereHas(relation as any, (builder) => {
          builder.where(attribute, 'LIKE', `%${this._search}%`)
        })
      }
    }
    query.orderBy(this._columnKey, this._order)
    return (await query.paginate(this._pageNumber, this._pageSize)).serialize()
  }
}
