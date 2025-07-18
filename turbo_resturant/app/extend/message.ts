import { HttpContext } from '@adonisjs/core/http'

HttpContext.getter('message', function (this: HttpContext) {
  return {
    success: (message: string) => {
      this.session?.flash({ success: message })
    },
    error: (message: string) => {
      this.session?.flash({ errors: message })
    },
  }
})

declare module '@adonisjs/core/http' {
  interface HttpContext {
    message: {
      success: (message: string) => void
      error: (message: string) => void
    }
  }
}
