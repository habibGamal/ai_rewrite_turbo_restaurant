import { FormSchema, FormSchemaField } from './formGenerator.js'
function snakeToCamel(s: string) {
  return s.replace(/(_\w)/g, function (m) {
    return m[1].toUpperCase()
  })
}

export default function refineFormInitValues(formSchema: FormSchema, initValues?: any) {
  if (!initValues) return undefined
  const newInitValues = { ...initValues }
  const keys = Object.keys(initValues)
  keys.forEach((key) => {
    const schema = formSchema.find(
      (field) => field.key === key.replace('_id', 'Id')
    ) as FormSchemaField
    newInitValues[snakeToCamel(key)] = initValues[key]
    if (schema && newInitValues[key] !== null) {
      if (schema.type === 'radio') {
        newInitValues[key] = newInitValues[key].toString()
      }
      if (schema.type === 'checkbox_group') {
        newInitValues[key] = newInitValues[key].map((item: any) => item.id?.toString())
      }
      if (schema.type === 'select') {
        newInitValues[key.replace('_id', 'Id')] = newInitValues[key].toString()
      }
    }
  })
  return newInitValues
}
