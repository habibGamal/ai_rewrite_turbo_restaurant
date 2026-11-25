import type { DefaultTheme } from 'vitepress'

export type ProductKey = 'pos' | 'website' | 'platform'

export interface ProductUrls {
  pos?: string
  website?: string
  platform?: string
}

const DEFAULT_URLS: Record<ProductKey, string> = {
  pos: 'http://localhost:5173',
  website: 'http://localhost:5174',
  platform: 'http://localhost:5175',
}

const PRODUCT_LABELS: Record<ProductKey, string> = {
  pos: 'نقاط البيع',
  website: 'موقع الطلبات',
  platform: 'المنصة المركزية',
}

export function productSwitcher(
  current: ProductKey,
  urls: ProductUrls = {},
): DefaultTheme.NavItemWithChildren {
  const keys: ProductKey[] = ['pos', 'website', 'platform']

  return {
    text: 'المنتجات',
    items: keys.map((key) => ({
      text: PRODUCT_LABELS[key],
      link: key === current ? '/' : (urls[key] ?? DEFAULT_URLS[key]),
    })),
  }
}
