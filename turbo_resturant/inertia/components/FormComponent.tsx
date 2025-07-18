import { router, usePage } from '@inertiajs/react'
import { Button, Form, Row, Space } from 'antd'
import { useEffect, useMemo } from 'react'
import formFieldsReshape from '../helpers/formFieldsReshape.js'
import fromGenerator, { FormSchema } from '../helpers/formGenerator.js'
import useLoading from '../hooks/useLoading.js'
import refineFormInitValues from '../helpers/refineFormInitValues.js'
import FormErrorMapping from '~/helpers/ErrorMapping.js'
const FORM_LAYOUT_1 = {
  labelCol: {
    md: {
      span: 12,
    },
    lg: {
      span: 24,
    },
    xl: {
      span: 24,
    },
  },
  wrapperCol: {
    md: {
      span: 12,
    },
    lg: {
      span: 24,
    },
    xl: {
      span: 24,
    },
  },
}

interface FormProps {
  submitRoute: string
  formName: string
  initValues: any
  modelToEdit?: any
  closeModal: () => void
  submitBtnText?: string
}

const FormComponent = ({
  submitRoute,
  formName,
  initValues,
  closeModal,
  submitBtnText = 'حفظ',
}: FormProps) => {
  const { form: formSchema } = usePage().props

  const [form] = Form.useForm()

  const formItems = useMemo(
    () => formFieldsReshape(fromGenerator(formSchema as FormSchema, form)),
    []
  )

  const submitState = useLoading()

  const onFinish = (values: any) => {

    const events = {
      onStart: () => submitState.stateLoading.onStart(),
      onSuccess: () => {
        closeModal()
      },
      onFinish: () => submitState.stateLoading.onFinish(),
    }
    if (initValues === undefined) return router.post(`/${submitRoute}`, values, events)
    router.put(`/${submitRoute}/${initValues.id}`, values, events)
  }

  const refinedInitValues = refineFormInitValues(formSchema as FormSchema, initValues)

  const { errors } = usePage().props
  useEffect(() => {
    const formErrorMapping = new FormErrorMapping(form)
    formErrorMapping.clearFormErrors()
    if (!errors) return
    const isErrors = Object.keys(errors).length > 0
    if (isErrors) return formErrorMapping.updateErrors(errors)
    return () => form.resetFields()
  }, [errors])

  return (
    <Form
      {...FORM_LAYOUT_1}
      form={form}
      name={formName}
      onFinish={onFinish}
      initialValues={refinedInitValues}
      className="p-8 border-2 border-indigo-500 rounded-md bg-indigo-50 dark:bg-transparent dark:border-none"
      layout="vertical"
      scrollToFirstError
      key={formName}
    >
      <Row gutter={24} justify="center">
        {formItems}
      </Row>
      <Form.Item key="buttons" className="grid place-items-center mt-8">
        <Space>
          <Button key="submit" type="primary" htmlType="submit" loading={submitState.loading}>
            {submitBtnText}
          </Button>
          <Button key="reset" htmlType="button" onClick={() => {}}>
            اعادة ملئ المدخلات
          </Button>
        </Space>
      </Form.Item>
    </Form>
  )
}

export default FormComponent
