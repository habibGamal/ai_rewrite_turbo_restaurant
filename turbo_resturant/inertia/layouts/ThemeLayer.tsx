import React, { createContext, useEffect, useLayoutEffect, useState } from 'react'
import { App, Button, ConfigProvider, Spin, theme, Typography } from 'antd'
import { router, usePage } from '@inertiajs/react'
import moment from 'moment'
import { configMomentLocaleAr } from '../config.js'
import LogoSvg from '~/components/LogoSvg.js'
import { transmit } from '~/app/app.js'
import { webOrdersSubscription } from '~/app/transmit.js'
import { Order } from '~/types/Models.js'
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
  const { message, notification } = App.useApp()
  const page = usePage().props

  // check if flash has error or success message display it
  useLayoutEffect(() => {
    router.on('finish', (e) => {
      // console.log('success:',e.detail.page.props.errors)
      // console.log('success:',e)
    })

    message.destroy()
    if (page.errors && typeof page.errors === 'string') {
      message.error(page.errors)
      page.errors = undefined
    }
    if (page.success) {
      message.success(page.success as string)
      page.success = undefined
    }
    if (page.exception) {
      message.error('حدث خطأ ما')
      console.table(page.exception)
      page.exception = undefined
    }
  }, [page])

  useEffect(() => {
    // refetch the page when the user clicks the back button
    const handlePopState = (event: PopStateEvent) => {
      event.stopImmediatePropagation()
      router.reload({
        // preserveState: false,
        // preserveScroll: false,
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

  const [subscribed, setSubscribed] = useState(webOrdersSubscription.isCreated)
  useEffect(() => {
    if (subscribed) return
    const subscripe = async () => {
      await webOrdersSubscription.create()
      setSubscribed(webOrdersSubscription.isCreated)
    }
    subscripe()
  }, [])

  useEffect(() => {
    if (!('Notification' in window)) return
    const cancelListen = webOrdersSubscription.onMessage((message: { order: Order }) => {
      console.log('order:', message)
      const audio = new Audio('/audio/web-notification.wav')
      audio.play()
      const browserNotification = new Notification('طلب اونلاين جديد', {
        body: `رقم الطلب: ${message.order.orderNumber}`,
        icon: '/images/logo.jpg',
        // onclick: ()=>{}
      })
      browserNotification.onclick = () => {
        window.focus()
        notification.destroy()
        router.get(`/orders/manage-web-order/${message.order.id}`)
      }
      notification.info({
        message: 'طلب اونلاين جديد',
        description: (
          <div className="flex flex-col gap-4">
            <Typography.Text>رقم الطلب: {message.order.orderNumber}</Typography.Text>
            <Typography.Text>نوع الطلب: {message.order.typeString}</Typography.Text>
            <Button
              onClick={() => {
                notification.destroy()
                router.get(`/orders/manage-web-order/${message.order.id}`)
              }}
            >
              عرض الطلب
            </Button>
          </div>
        ),
        duration: 0,
        placement: 'topLeft',
      })
    })
    return () => {
      cancelListen()
    }
  }, [subscribed])

  return (
    <div className="relative">
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
