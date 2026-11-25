import type { Theme } from 'vitepress'
import DefaultTheme from 'vitepress/theme'
import './style.css'

type RedirectMap = Record<string, string>

function normalizePath(pathname: string): string {
  const withoutHtml = pathname.replace(/\.html$/i, '')
  if (withoutHtml.length > 1 && withoutHtml.endsWith('/')) {
    return withoutHtml.slice(0, -1)
  }
  return withoutHtml || '/'
}

function resolveRedirect(url: string, redirects: RedirectMap): string | null {
  const hashIndex = url.indexOf('#')
  const hash = hashIndex >= 0 ? url.slice(hashIndex) : ''
  const withoutHash = hashIndex >= 0 ? url.slice(0, hashIndex) : url

  const queryIndex = withoutHash.indexOf('?')
  const query = queryIndex >= 0 ? withoutHash.slice(queryIndex) : ''
  const rawPath = queryIndex >= 0 ? withoutHash.slice(0, queryIndex) : withoutHash

  const normalized = normalizePath(rawPath)
  const target = redirects[rawPath] ?? redirects[normalized]
  if (!target) {
    return null
  }

  return `${target}${query}${hash}`
}

export default {
  extends: DefaultTheme,
  enhanceApp({ router, siteData }) {
    const themeConfig = siteData.value.themeConfig as { redirects?: RedirectMap } | undefined
    const redirects = themeConfig?.redirects
    if (!redirects || Object.keys(redirects).length === 0) {
      return
    }

    router.onBeforeRouteChange = (to) => {
      const target = resolveRedirect(to, redirects)
      if (target && target !== to) {
        router.go(target)
        return false
      }
    }

    if (typeof window !== 'undefined') {
      const current = `${window.location.pathname}${window.location.search}${window.location.hash}`
      const target = resolveRedirect(current, redirects)
      if (target && target !== current) {
        queueMicrotask(() => {
          router.go(target)
        })
      }
    }
  },
} satisfies Theme
