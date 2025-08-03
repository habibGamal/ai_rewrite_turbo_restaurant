/**
 * Clear slug from url query
 * slug format like this : ordersByPaymentMethod_
 */
export default function clearSlugFromUrlQuery(slug: string) {
  const url = new URL(window.location.href)
  url.searchParams.forEach((_, key) => {
    if (key.startsWith(slug)) {
      url.searchParams.delete(key)
    }
  })
  history.replaceState({}, '', url)
}
