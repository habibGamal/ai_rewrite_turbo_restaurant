import { PageProps } from '@adonisjs/inertia/types'
import { usePage } from '@inertiajs/react'
import { Modal } from 'antd/es'
import { Add } from 'iconsax-react'
import { Props } from '~/pages/RenderModel.js'
import FormComponent from '../FormComponent.js'
import useModal from '~/hooks/useModal.js'

export default function ModalForm({
  model,
  modalForm,
}: {
  model: any
  modalForm: ReturnType<typeof useModal>
}) {
  const { title, routes } = usePage().props as unknown as Props & PageProps
  const submitRoute = (): string | undefined => {
    return model ? routes?.update : routes?.store
  }
  return (
    <Modal
      title={title}
      {...modalForm}
      footer={null}
      destroyOnClose={true}
      width="90%"
      closeIcon={<Add style={{ rotate: '45deg' }} />}
    >
      <FormComponent
        submitRoute={submitRoute() ?? ''}
        initValues={model}
        formName={title}
        closeModal={modalForm.closeModal}
      />
    </Modal>
  )
}
