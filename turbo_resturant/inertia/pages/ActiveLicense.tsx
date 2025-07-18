import UnauthorizedLayout from '~/layouts/UnauthorizedLayout'

export default function ActiveLicense() {
  return <div>ActiveLicense</div>
}

ActiveLicense.layout = (page: any) => <UnauthorizedLayout children={page} />
