import { FormInstance } from 'antd'
type FieldError = {
  name: string
  message: string
}
export default class FormErrorMapping {
  private errors: FieldError[] = []
  private form: FormInstance<any>
  private fields: string[]

  constructor(form: FormInstance<any>) {
    this.form = form
    this.fields = Object.keys(this.form.getFieldsValue())
  }

  public clearFormErrors() {
    this.form.setFields(this.fields.map((field) => ({ name: field, errors: [] })))
  }

  public updateErrors(errors: { [key: string]: string }) {
    this.errors = this.errorsToFieldErrors(errors)

    this.form.setFields(
      this.errors.map((error) => ({
        name: error.name,
        errors: [error.message],
      }))
    )
  }

  private errorsToFieldErrors(errors: { [key: string]: string }) {
    return Object.entries(errors).map(([name, message]) => ({
      name,
      message,
    }))
  }
}
