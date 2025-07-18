import { UserRole } from '#enums/UserEnums'
import User from '#models/User'
import { BaseSeeder } from '@adonisjs/lucid/seeders'

export default class extends BaseSeeder {
  async run() {
    await User.createMany([
      {
        email: 'admin@span.com',
        password: '12345678',
        role: UserRole.Admin,
      },
      {
        email: 'user@span.com',
        password: '12345678',
        role: UserRole.Cashier,
      },
    ])
  }
}
