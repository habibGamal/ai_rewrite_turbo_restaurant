import { Col } from 'antd'
type Column = {
  col: boolean
}
const FORM_COLUMNS_LAYOUT = {
  md: 24,
  lg: 12,
  xl: 8,
}

export default function formFieldsReshape(items: (JSX.Element | Column)[]) {
  const reshapedItems: JSX.Element[][] = []
  items.forEach((item) => {
    if ((item as Column).col) reshapedItems.push([])
    else reshapedItems[reshapedItems.length - 1].push(item as JSX.Element)
  })
  return reshapedItems.map((col, index) => (
    <Col {...FORM_COLUMNS_LAYOUT} key={index}>
      {col.map((item) => item)}
    </Col>
  ))
}
