import axios from 'axios'
import { SetOptions } from '../components/SelectSearch.js'

export default async function calcProductComponentsCost(formValues: any) {
  const response = await axios.post<{
    cost: number
  }>(`/form-fetch-info/calc-product-components-cost`, {
    ...formValues,
  })
  return response.data.cost
}
