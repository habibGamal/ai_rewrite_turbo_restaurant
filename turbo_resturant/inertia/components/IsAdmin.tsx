import { usePage } from '@inertiajs/react'
import React from 'react'
import { User } from '../types/Models.js'
import { UserRole } from '#enums/UserEnums'

export default function IsAdmin({ children }: { children: React.ReactNode }) {
  const user = usePage().props.user as User | undefined

  if (user?.role === UserRole.Admin) return <>{children}</>
  return null
}
