export default interface Pagination<T> {
  data: T[]
  meta:{
    current_page: number
    first_page_url: string
    first_page: number
    last_page: number
    last_page_url: string
    previous_page_url: string
    next_page_url: string
    per_page: number
    total: number
    [key: string]: any
  }
}

export interface Link {
  url: string
  label: string
  active: boolean
}
