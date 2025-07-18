import type { HttpContext } from '@adonisjs/core/http'
import type { NextFn } from '@adonisjs/core/types/http'
import fs from 'fs'
export default class CheckLicenseMiddleware {
  async handle(ctx: HttpContext, next: NextFn) {
    /**
     * Middleware logic goes here (before the next call)
     */
    const applicationPath = process.cwd()
    if (fs.existsSync(applicationPath + '/license.json')) {
      const license = JSON.parse(fs.readFileSync(applicationPath + '/license.json', 'utf8'))
      const [name, expires] = license.key.split(':')
      // expires format => 2024-4
      const validLicense = expires == new Date().toISOString().slice(0, 7)
      console.log(validLicense,expires,new Date().toISOString().slice(0, 7))
      if (!validLicense) {
        // return ctx.inertia.render('ActiveLicense')
        return ctx.response.redirect('active-license')
      }
    }

    /**
     * Call next method in the pipeline and return its output
     */
    const output = await next()
    return output
  }
}
