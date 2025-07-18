import { UserRole } from '#enums/UserEnums'
import { HttpContext } from '@adonisjs/core/http'
const layout = () => {
  const { auth } = HttpContext.getOrFail()
  if(auth.user?.role === UserRole.Viewer) return ':viewer'
  if(auth.user?.role === UserRole.Watcher) return ':watcher'
  return ''
}

export default layout
