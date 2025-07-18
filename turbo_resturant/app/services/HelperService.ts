import { DateTime } from 'luxon'

export default class HelperService {
  public static period(fromQs?: string, toQs?: string) {
    const from: string = fromQs || DateTime.now().startOf('month').toFormat('yyyy-MM-dd')
    const to: string = toQs || DateTime.now().toFormat('yyyy-MM-dd')
    const fromDt = DateTime.fromFormat(from, 'yyyy-MM-dd')
      .startOf('day')
      .toFormat('yyyy-MM-dd HH:mm:ss')
    const toDt = DateTime.fromFormat(to, 'yyyy-MM-dd').endOf('day').toFormat('yyyy-MM-dd HH:mm:ss')
    return { fromDt, toDt }
  }
}
