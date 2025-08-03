/// <reference path="../../adonisrc.ts" />
import { resolvePageComponent } from '@adonisjs/inertia/helpers'
import { createInertiaApp } from '@inertiajs/react'
import moment from 'moment'
import { createRoot } from 'react-dom/client'
import AuthorizedLayout from '~/layouts/AuthorizedLayout'
import ViewerLayout from '~/layouts/ViewerLayout'
import '../css/app.scss'
import WatcherLayout from '~/layouts/WatcherLayout'

const appName = import.meta.env.VITE_APP_NAME || 'Turbo'

const isDesktop = () => {
  const userAgent = navigator.userAgent.toLowerCase()
  const isMobile = /mobile|android|iphone|ipad|tablet|blackberry|opera mini|iemobile|wpdesktop/.test(userAgent)
  return !isMobile
}

if (isDesktop() && 'Notification' in window) {
  Notification.requestPermission()
}

createInertiaApp({
  progress: { color: '#5468FF' },

  title: (title) => `${title} - ${appName}`,

  resolve: async (name) => {
    const [pageName, layout] = name.split(':')
    const module = await resolvePageComponent(
      `../pages/${pageName}.tsx`,
      import.meta.glob('../pages/**/*.tsx')
    )
    const page = module.default
    const defaultLayout = page.layout
    page.layout = defaultLayout || ((page: any) => <AuthorizedLayout children={page} />)
    if (layout === 'viewer') {
      page.layout = (page: any) => <ViewerLayout children={page} />
    }
    if (layout === 'watcher') {
      page.layout = (page: any) => <WatcherLayout children={page} />
    }
    return page
  },

  // resolve: resolver,

  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />)
  },
})
