import { Exception } from '@adonisjs/core/exceptions'

export default class PartialReloadException extends Exception {
  static status = 500
}
