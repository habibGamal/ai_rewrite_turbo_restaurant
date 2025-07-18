import { Col, Row } from 'antd'
import React from 'react'
import ThemeLayer from './ThemeLayer.js'

export default function UnauthorizedLayout(props: { children: JSX.Element }) {
  return (
    <ThemeLayer>
      <Row wrap={false}>
        <Col flex="auto">{props.children}</Col>
      </Row>
    </ThemeLayer>
  )
}
