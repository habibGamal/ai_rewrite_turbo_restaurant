import React, { createContext, useEffect, useLayoutEffect, useState } from 'react'
import { App, ConfigProvider, Spin, theme } from 'antd'
import { router, usePage } from '@inertiajs/react'
import moment from 'moment'
import { configMomentLocaleAr } from '../config.js'
import LogoSvg from '~/components/LogoSvg.js'
interface Flash {
  error?: string
  success?: string
}

type Theme = 'dark' | 'light'
export const themeToggler = createContext<{
  currentTheme: Theme
  toggleTheme: (currentTheme: Theme) => void
} | null>(null)

function Config(props: { children: JSX.Element }) {
  const { message } = App.useApp()
  const page = usePage().props
  // check if flash has error or success message display it
  useLayoutEffect(() => {
    if (page.errors && typeof page.errors === 'string') {
      message.error(page.errors)
    }
    if (page.success) {
      message.success(page.success as string)
    }
    if (page.exception) {
      message.error('حدث خطأ ما')
      console.table(page.exception)
    }
  }, [page])

  useEffect(() => {
    // refetch the page when the user clicks the back button
    const handlePopState = (event: PopStateEvent) => {
      event.stopImmediatePropagation()
      router.reload({
        preserveState: false,
        preserveScroll: false,
        replace: true,
      })
    }
    window.addEventListener('popstate', handlePopState)
    return () => {
      window.removeEventListener('popstate', handlePopState)
    }
  }, [])

  const [loading, setLoading] = useState(false)
  useEffect(() => {
    const startLoading = router.on('start', () => {
      setLoading(true)
    })
    const finishLoading = router.on('finish', () => {
      setLoading(false)
    })
    return () => {
      startLoading()
      finishLoading()
    }
  }, [])
  return (
    <div className="relative">
      <div className="absolute -top-full z-[-1] opacity-0 ">
        <div id="print_container"></div>
      </div>
      {loading && (
        <div className="fixed w-full h-screen z-[2000] bg-[#0707074c] dark:bg-[#83838340] grid place-items-center">
          <div className="grid place-items-center gap-8">
            <div className="overflow-clip w-[100vw] md:w-[80vw] h-40 md:h-80">
              <LogoSvg />
            </div>
            <Spin size="large" />
          </div>
        </div>
      )}
      {props.children}
    </div>
  )
}

function ThemeLayer(props: { children: JSX.Element }) {
  const [currentTheme, setCurrentTheme] = useState<Theme>('light')

  // switch to dark theme by add `dark` class to html tag
  // to trigger dark mode in tailwindcss
  useLayoutEffect(() => {
    const theme = themeFromStorage()
    updateHtmlElementTheme(theme)
  }, [currentTheme])

  const themeFromStorage = () => {
    const theme = (localStorage.getItem('theme') ?? 'light') as Theme
    // set the current theme to the theme from storage
    if (theme !== currentTheme) setCurrentTheme(theme)
    return theme
  }

  const updateHtmlElementTheme = (theme: Theme) => {
    const htmlElement = document.querySelector('html') as HTMLElement
    const isDark = theme === 'dark'
    if (isDark) htmlElement.classList.add('dark')
    else htmlElement.classList.remove('dark')
  }

  useLayoutEffect(() => {
    const html = document.querySelector('html') as HTMLHtmlElement
    html.setAttribute('dir', 'rtl')
  })
  const toggleTheme = (currentTheme: Theme) => {
    localStorage.setItem('theme', currentTheme)
    setCurrentTheme(currentTheme)
  }

  useEffect(() => {
    configMomentLocaleAr()
    moment.locale('ar')
  }, [])

  return (
    <themeToggler.Provider value={{ currentTheme, toggleTheme }}>
      <ConfigProvider
        direction="rtl"
        theme={{
          algorithm: currentTheme === 'light' ? theme.defaultAlgorithm : theme.darkAlgorithm,
          token: {
            colorPrimary: currentTheme === 'light' ? '#7E57C2' : '9575CD',
            colorError: '#cf6679',
            fontSize: 18,
            // fontFamily: "tajawal",
            // lineHeight: 1,
          },
        }}
      >
        <App>
          <Config>{props.children}</Config>
        </App>
      </ConfigProvider>
    </themeToggler.Provider>
  )
}

export default ThemeLayer
