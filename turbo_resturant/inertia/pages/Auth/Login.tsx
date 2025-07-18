import { router } from '@inertiajs/react'
import { Button, Form, Input } from 'antd'
import UnauthorizedLayout from '../../layouts/UnauthorizedLayout.js'
import { Link2 } from 'iconsax-react'
export default function Login() {
  const onFinish = (values: any) => router.post('/login', values)

  return (
    <div className="grid place-items-center w-full min-h-[50vh]">
      <div className="flex my-4 items-center gap-4">
        <div className="dark:bg-dark-700 p-2 rounded-2xl">
          <img
            className="w-24 h-24 object-contain object-center rounded-2xl"
            src="images/logo.jpg"
          />
        </div>
        <Link2 className="text-gray-500" />
        <div className="dark:bg-dark-700 p-2 rounded-2xl">
          <img
            className="w-24 h-24 object-contain object-center rounded-2xl"
            src="images/turbo_logo.png"
          />
        </div>
      </div>
      <Form name="login" className="isolate xl:min-w-[500px]" layout="vertical" onFinish={onFinish}>
        <Form.Item
          label="البريد الإلكتروني"
          name="email"
          rules={[{ required: true, message: 'Please input your email!' }]}
        >
          <Input />
        </Form.Item>

        <Form.Item
          label="كلمه السر"
          name="password"
          rules={[{ required: true, message: 'Please input your password!' }]}
        >
          <Input.Password />
        </Form.Item>

        <Form.Item>
          <Button type="primary" htmlType="submit">
            تسجيل الدخول
          </Button>
        </Form.Item>
      </Form>
    </div>
  )
}

Login.layout = (page: any) => <UnauthorizedLayout children={page} />
