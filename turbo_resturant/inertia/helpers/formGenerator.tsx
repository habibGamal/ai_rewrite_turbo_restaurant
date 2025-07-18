import { Checkbox, Form, FormInstance, Input, InputNumber, Radio, Select } from 'antd'
export type FormSchema = FormSchemaField[]
export type FormSchemaField = {
  key: string
  type: string
  label: string
  placeholder?: string
  options?: { [key: string]: string }
  min?: number
  disabled?: { [key: string]: string[] }
  slug?: string
  formItems?: FormSchema
  initBy?: string
}

export default function fromGenerator(formSchema: FormSchema, form: FormInstance) {
  return formSchema.map((field) => {
    if (field.key.includes('col')) return { col: true }
    return new InputConstruction([field.key], field as FormSchemaField, form).build()
  })
}

class InputConstruction {
  constructor(
    private name: any,
    private field: FormSchemaField,
    private form: FormInstance
  ) {
    this.text = this.text.bind(this)
    this.number = this.number.bind(this)
    this.select = this.select.bind(this)
    this.radio = this.radio.bind(this)
    this.checkbox = this.checkbox.bind(this)
    this.component = this.component.bind(this)
    this.checkboxGroup = this.checkboxGroup.bind(this)
  }

  public build() {
    const deps = this.dependencies()
    if (!deps)
      return (
        <Form.Item
          key={this.field.key}
          name={this.name}
          label={this.field.label}
          valuePropName={this.field.type === 'checkbox' ? 'checked' : undefined}
        >
          <this.component />
        </Form.Item>
      )
    return (
      <Form.Item key={this.field.key + 'dep'} dependencies={this.dependencies()}>
        {() => (
          <Form.Item
            key={this.field.key}
            name={this.name}
            label={this.field.label}
            valuePropName={this.field.type === 'checkbox' ? 'checked' : undefined}
          >
            <this.component />
          </Form.Item>
        )}
      </Form.Item>
    )
  }

  private getDisableDeps(): {
    fieldName: string
    fieldValue: string[]
  }[] {
    return Object.entries(this.field.disabled ?? {}).map(([fieldName, fieldValue]) => ({
      fieldName,
      fieldValue,
    }))
  }

  private dependencies() {
    return this.getDisableDeps().map(({ fieldName }) => fieldName)
  }

  private component(props?: any) {
    if (this.field.type === 'text') return <this.text {...props} />
    if (this.field.type === 'number') return <this.number {...props} />
    if (this.field.type === 'checkbox') return <this.checkbox {...props} />
    if (this.field.type === 'checkbox_group') return <this.checkboxGroup {...props} />
    if (this.field.type === 'radio') return <this.radio {...props} />
    if (this.field.type === 'select') return <this.select {...props} />
    return <></>
  }

  private isDisabled() {
    const deps = this.getDisableDeps().find(({ fieldName, fieldValue }) =>
      fieldValue.includes(this.form.getFieldValue(fieldName))
    )
    if (deps === undefined) return false
    return true
  }

  private options() {
    return this.field.options
      ? Object.entries(this.field.options).map(([key, value]) => ({
          label: value,
          value: key,
        }))
      : []
  }

  private text(props?: any) {
    return <Input {...props} disabled={this.isDisabled()} placeholder={this.field.placeholder} />
  }

  private number(props?: any) {
    return (
      <InputNumber
        {...props}
        disabled={this.isDisabled()}
        placeholder={this.field.placeholder}
        min={this.field.min}
        style={{ width: '100%' }}
      />
    )
  }

  private select(props?: any) {
    return (
      <Select
        {...props}
        disabled={this.isDisabled()}
        placeholder={this.field.placeholder}
        style={{ minWidth: '200px' }}
      >
        {this.options().map(({ label, value }) => (
          <Select.Option key={value} value={value}>
            {label}
          </Select.Option>
        ))}
      </Select>
    )
  }

  private radio(props?: any) {
    return (
      <Radio.Group {...props} disabled={this.isDisabled()}>
        {this.options().map(({ label, value }) => (
          <Radio key={value} value={value}>
            {label}
          </Radio>
        ))}
      </Radio.Group>
    )
  }

  private checkbox(props?: any) {

    return <Checkbox disabled={this.isDisabled()} {...props} />
  }

  private checkboxGroup(props?: any) {
    return (
      <Checkbox.Group {...props} disabled={this.isDisabled()}>
        {this.options().map(({ label, value }) => (
          <Checkbox key={value} value={value}>
            {label}
          </Checkbox>
        ))}
      </Checkbox.Group>
    )
  }
}
