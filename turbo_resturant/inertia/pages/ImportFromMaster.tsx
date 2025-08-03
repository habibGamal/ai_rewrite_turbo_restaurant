import { Button, Col, Empty, Modal, Row } from 'antd'
import PageTitle from '~/components/PageTitle'
import { CloudSyncOutlined } from '@ant-design/icons'
import { router } from '@inertiajs/react'
import { Category, Product } from '~/types/Models'
import { useState } from 'react'
import SelectCategory from '~/components/SelectCategory'
import useModal from '~/hooks/useModal'

function ImporterModal(props: {
  title: string
  btnText: string
  importerRoute: string
  dataSlug: string
  optionsMapper?: (product: Product) => {
    label: JSX.Element
    value: string
  }
}) {
  const [categories, setCategories] = useState<Category[]>([])
  const getNewProducts = () => {
    router.reload({
      only: [props.dataSlug],
      onSuccess: (e) => {
        setCategories(e.props[props.dataSlug] as Category[])
        setTimeout(() => {
          importModal.showModal()
        }, 0)
      },
    })
  }
  const [selectedToImport, setSelectedToImport] = useState<
    { catgoryId: number; selected: string[] }[]
  >([])
  const importModal = useModal()

  const importProducts = () => {
    router.post(
      props.importerRoute,
      {
        products: selectedToImport.map((item) => item.selected),
      },
      {
        onSuccess: () => {
          importModal.closeModal()
        },
      }
    )
  }
  const filteredCategories = categories.filter((category) => category.products.length !== 0)
  const selectAll = () => {
    setSelectedToImport(
      filteredCategories.map((category) => ({
        catgoryId: category.id,
        selected: category.products.map((product) => product.id.toString()),
      }))
    )
  }
  const clear = () => {
    setSelectedToImport([])
  }
  return (
    <>
      <Button onClick={getNewProducts}>{props.btnText}</Button>
      <Modal
        title={props.title}
        okText="استيراد"
        cancelText="الغاء"
        onOk={importProducts}
        {...importModal}
        width={1000}
      >
        {filteredCategories.length === 0 ? (
          <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="لا توجد بيانات" />
        ) : (
          <Button.Group>
            <Button onClick={selectAll} className="my-4" >
              اختيار الكل
            </Button>
            <Button onClick={clear} className="my-4" >
              الغاء
            </Button>
          </Button.Group>
        )}

        <div className="grid grid-cols-3 gap-8">
          {filteredCategories.map((category) => (
            <SelectCategory
              key={category.id}
              category={category}
              selectedProducts={selectedToImport}
              setSelectedProducts={setSelectedToImport}
              options={props.optionsMapper ? category.products.map(props.optionsMapper) : undefined}
            />
          ))}
        </div>
      </Modal>
    </>
  )
}

export default function ImportFromMaster() {
  return (
    <Row gutter={[0, 25]} className="m-8">
      <Col span={24}>
        <PageTitle name="منتجات النقطة الرئيسية" />
      </Col>
      <Col className="isolate mx-auto">
        <div className="grid place-items-center gap-4">
          <CloudSyncOutlined className="text-violet-700" style={{ fontSize: '16rem' }} />
          <Button.Group size="large">
            <ImporterModal
              dataSlug="newProducts"
              importerRoute="/import-products"
              btnText="استيراد"
              title="استيراد منتجات"
            />
            <ImporterModal
              dataSlug="changedRecipes"
              importerRoute="/update-recipes"
              btnText="تحديث معياري"
              title="تحديث معياري"
            />
            <ImporterModal
              dataSlug="changedPrices"
              importerRoute="/update-prices"
              btnText="تحديث الاسعار"
              title="تحديث الاسعار"
              optionsMapper={(product: Product) => ({
                label: (
                  <div>
                    <span>{product.name}</span>
                    <br />
                    <span className="text-xs text-green-500 whitespace-nowrap">
                      {product.price} - {product.cost}
                    </span>
                  </div>
                ),
                value: product.id.toString(),
              })}
            />
          </Button.Group>
        </div>
      </Col>
    </Row>
  )
}
