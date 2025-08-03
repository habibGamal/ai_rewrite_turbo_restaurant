import { NodeType } from '#enums/SettingsEnums'
import cookie from 'cookie'

export default function useNodeType(): NodeType {
  const cookies = cookie.parse(document.cookie)
  const nodeType = cookies.nodeType as NodeType
  return nodeType
}
