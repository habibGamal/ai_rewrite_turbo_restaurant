import { exportToCSV } from '#services/ExportCSV'
import { ModelQueryBuilderContract } from '@adonisjs/lucid/types/model'
import { HttpContext } from '@adonisjs/core/http'
import { RenderSuitePagination } from './RenderSuitePagination.js'

type Column = ReturnType<typeof RenderSuiteTemplate.prototype.column>
type Expand = ReturnType<typeof RenderSuiteTemplate.prototype.expand>
type SearcheWith = ReturnType<typeof RenderSuiteTemplate.prototype.searchWith>
type FormField =
  | ReturnType<typeof RenderSuiteTemplate.prototype.col>
  | ReturnType<typeof RenderSuiteTemplate.prototype.text>
  | ReturnType<typeof RenderSuiteTemplate.prototype.number>
  | ReturnType<typeof RenderSuiteTemplate.prototype.select>
  | ReturnType<typeof RenderSuiteTemplate.prototype.checkboxGroup>
  | ReturnType<typeof RenderSuiteTemplate.prototype.selectSearch>
  | ReturnType<typeof RenderSuiteTemplate.prototype.radio>
  | ReturnType<typeof RenderSuiteTemplate.prototype.checkbox>

type Actions = {
  editable?: boolean
  deletable?: boolean
  customActions?: {
    label: string
    actionRoute: string
    options?: { type?: string; method?: string }
  }[]
}

type Routes = {
  store?: string
  update?: string
  destroy?: string
}

export class RenderSuiteTemplate<T> {
  private static colNumber = 0
  private declare _title: string
  private _imoportRoute?: string
  private declare _slug: string
  private declare _data: T
  private declare _columns: Column[]
  private declare _expandable: Expand[]
  private declare _actions: Actions
  private declare _searchable: SearcheWith[]
  private declare _routes: Routes
  private declare _form: FormField[]
  private declare _exportQuery: ModelQueryBuilderContract<any>
  private declare _exportColumns: string[]
  private _formUtils: string[] = []
  private _noForm = false
  private _noActions = false

  public importRoute(route: string) {
    this._imoportRoute = route
    return this
  }

  public exportQuery(query: ModelQueryBuilderContract<any>) {
    this._exportQuery = query
    return this
  }

  public noForm() {
    this._noForm = true
    return this
  }

  public noActions() {
    this._noActions = true
    return this
  }

  public title(title: string) {
    this._title = title
    return this
  }

  public slug(slug: string) {
    this._slug = slug
    return this
  }

  public data<D extends T>(data: D) {
    this._data = data
    return this
  }

  public formUtils(formUtils: string[]) {
    this._formUtils = formUtils
    return this
  }

  public columns(columns: Column[]) {
    this._columns = columns
    return this
  }

  public expandable(expandable: Expand[]) {
    this._expandable = expandable
    return this
  }

  public customAction(
    label: string,
    actionRoute: string,
    options?: { type?: string; method?: string }
  ) {
    return {
      label,
      actionRoute,
      options,
    }
  }

  public actions(actions: Actions) {
    this._actions = actions
    return this
  }

  public searchable(searchable: SearcheWith[]) {
    this._searchable = searchable
    return this
  }

  public routes(routes: Routes) {
    this._routes = routes
    return this
  }

  public form(form: FormField[]) {
    this._form = form
    return this
  }

  public column(
    key: string,
    label: string,
    sortable = false,
    color?: boolean,
    mappingValues?: any
  ) {
    return {
      key,
      label,
      sortable,
      color,
      mappingValues,
    }
  }

  public expand(key: string, label: [string, string]) {
    return {
      key: key,
      label: label,
    }
  }

  public searchWith(key: string, label: string) {
    return {
      key: key,
      label: label,
    }
  }

  public col() {
    RenderSuiteTemplate.colNumber++
    return {
      key: 'col' + RenderSuiteTemplate.colNumber,
    }
  }

  public text(
    key: string,
    label: string,
    disabled?: {
      [key: string]: string[]
    }
  ) {
    return {
      key,
      type: 'text',
      label: label,
      disabled: disabled,
    }
  }

  public number(key: string, label: string, min = 0) {
    return {
      key,
      type: 'number',
      label: label,
      min: min,
    }
  }

  public select(
    key: string,
    label: string,
    options: {
      [key: string]: string
    },
    conditions = [],
    initBy?: string
  ) {
    return {
      key,
      type: 'select',
      label: label,
      options: options,
      disabled: conditions,
      initBy,
    }
  }

  public checkboxGroup(
    key: string,
    label: string,
    options: {
      [key: string]: string
    }
  ) {
    return {
      key,
      type: 'checkbox_group',
      label: label,
      options: options,
    }
  }

  public selectSearch(
    key: string,
    label: [string, string],
    slug: string,
    disabled?: {
      [key: string]: string[]
    },
    initBy?: string
  ) {
    return {
      key,
      type: 'select_search',
      label: label,
      slug: slug,
      disabled: disabled,
      initBy: initBy,
    }
  }

  public radio(key: string, label: string, options: any) {
    return {
      key,
      type: 'radio',
      label: label,
      options: options,
    }
  }

  public checkbox(key: string, label: string) {
    return {
      key,
      type: 'checkbox',
      label: label,
    }
  }

  public exportColumns(columns: string[]) {
    this._exportColumns = columns
    return this
  }

  public formList(
    key: string,
    label: [string, string],
    formItems: FormField[],
    disabled?: {
      [key: string]: string[]
    },
    initBy?: string
  ) {
    return {
      key,
      type: 'form_list',
      label: label,
      formItems: formItems,
      disabled: disabled,
      initBy: initBy,
    }
  }

  public render() {
    const { inertia } = HttpContext.get()!
    return {
      title: () => this._title,
      importRoute: () => this._imoportRoute,
      slug: () => this._slug,
      data: () => this._data,
      columns: () => this._columns,
      expandable: () => this._expandable,
      actions: () => this._actions,
      searchable: () => this._searchable,
      routes: () => this._routes,
      form: () => this._form,
      formUtils: () => this._formUtils,
      exportCSV: inertia.lazy(async () => {
        const modelAll = await this._exportQuery
        const columns = this._exportColumns
        const data = modelAll.map((model) => {
          const row: any = {}
          columns.forEach((column) => {
            if (column.includes('.')) {
              const columnParts = column.split('.')
              row[column] = model[columnParts[0]][columnParts[1]]
              return
            }
            row[column] = model[column]
          })
          return row
        })
        const result = await exportToCSV(this._title[0], columns, data)
        return result
      }),
      noForm: () => this._noForm,
      noActions: () => this._noActions,
    }
  }
}
