import DailySnapshotService from '#services/DailySnapshotService'
import { defineConfig } from '@adonisjs/inertia'

export default defineConfig({
  /**
   * Path to the Edge view that will be used as the root view for Inertia responses
   */
  rootView: 'inertia_layout',

  /**
   * Data that should be shared with all rendered pages
   */
  sharedData: {
    errors: (ctx) => ctx.session?.flashMessages.get('errors'),
    exception: (ctx) => ctx.session?.flashMessages.get('exception'),
    success: (ctx) => ctx.session?.flashMessages.get('success'),
    user: (ctx) => {
      return ctx.auth.user
    },
    dayIsOpen: (_) => {
      return DailySnapshotService.dayIsOpen()
    },
    allowStartAccounting: (_) => {
      return DailySnapshotService.allowStartAccounting()
    },
  },

  /**
   * Options for the server-side rendering
   */
  ssr: {
    enabled: false,
    entrypoint: 'inertia/app/ssr.tsx',
  },
})
