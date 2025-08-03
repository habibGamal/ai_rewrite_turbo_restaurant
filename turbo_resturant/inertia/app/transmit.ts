import { Transmit } from '@adonisjs/transmit-client'

export const transmit = new Transmit({
  baseUrl: window.location.origin,
})


export const webOrdersSubscription = transmit.subscription('web-orders')
