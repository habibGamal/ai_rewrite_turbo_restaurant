import { Database } from '@adonisjs/lucid/database'

export default function vineExists(table: string) {
  return (db: Database, value: string) => {
    const check = async () =>
      (await db.from(table).select('id').where('id', value).first()) ? true : false
    return check()
  }
}
