import { Exception } from '@adonisjs/core/exceptions'

export default class ErrorMsgException extends Exception {
  // static status = 500
  static code = 'ERROR_MSG'
}
