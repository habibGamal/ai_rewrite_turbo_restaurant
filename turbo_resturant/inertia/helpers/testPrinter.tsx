import TestTemplate from '~/components/Print/TestTemplate'
import printTemplate from './printTemplate'
import { router } from '@inertiajs/react'

export default async function testPrinter(printerAddress: string) {
  const image = await printTemplate('test_print', <TestTemplate />)
  router.post('/test-printer', {
    printer: printerAddress,
    img: image,
  })
}
