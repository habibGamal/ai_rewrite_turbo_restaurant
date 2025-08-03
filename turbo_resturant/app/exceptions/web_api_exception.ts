import { Exception } from '@adonisjs/core/exceptions'

/**
 * message is json object
 */
export default class WebApiException extends Exception {
  static status = 500
}
