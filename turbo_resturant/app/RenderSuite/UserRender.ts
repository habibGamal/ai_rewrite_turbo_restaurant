import User from '#models/User'
import { PaginatorService } from '../services/PaginatorService.js'
import { RenderSuiteTemplate } from './RenderSuiteTemplate.js'
import { UserRole } from '#enums/UserEnums'

export class UserRender {
  public async render() {
    const pagination = new PaginatorService()
    const data =  await pagination.paginate(User.query())
    const template = new RenderSuiteTemplate<typeof data>()

    template
      .title('المستخدمين')
      .slug('users')
      .data(data)
      .columns([
        template.column('email', 'البريد الالكتروني'),
        template.column('roleString', 'الصلاحية'),
      ])
      .form([
        template.col(),
        template.text('email', 'البريد الالكتروني'),
        template.select('role', 'الصلاحية', {
          [UserRole.Admin]: 'مدير',
          [UserRole.Cashier]: 'كاشير',
          [UserRole.Viewer]: 'متابع',
          [UserRole.Watcher]: 'مراقب',
        }),
        template.text('password', 'كلمة المرور'),
      ])
      .actions({
        editable: true,
        deletable: true,
      })
      .searchable([template.searchWith('email', 'البريد الالكتروني')])
      .routes({
        store: 'users',
        update: 'users',
        destroy: 'users',
      })

    return template.render()
  }
}
