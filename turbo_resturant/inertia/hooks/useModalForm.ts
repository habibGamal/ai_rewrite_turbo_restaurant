import { useState } from 'react'
import useModal from './useModal'

export default function useModalForm() {
  const modalForm = useModal()
  const [model, setModel] = useState<any | undefined>(undefined)
  const add = () => {
    setModel(undefined)
    modalForm.showModal()
  }
  const edit = (model: any) => {
    setModel(model)
    modalForm.showModal()
  }
  return { add, edit, model, modalForm }
}
