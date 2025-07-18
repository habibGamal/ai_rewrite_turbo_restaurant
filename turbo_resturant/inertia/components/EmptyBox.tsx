import { Empty } from 'antd'

export default function EmptyBox() {
  return (
    <div className="isolate w-64 aspect-square grid place-items-center mx-auto my-24">
      <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />
    </div>
  )
}
