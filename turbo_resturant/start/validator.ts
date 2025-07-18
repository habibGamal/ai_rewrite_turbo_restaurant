import vine, { SimpleMessagesProvider } from '@vinejs/vine'

vine.messagesProvider = new SimpleMessagesProvider({
  // Applicable for all fields
  'required': 'هذا الحقل مطلوب',
  'string': 'هذا الحقل يجب أن يكون نص',
  'number': 'هذا الحقل يجب أن يكون رقم',
  'email': 'هذا الحقل يجب أن يكون بريد إلكتروني',
  'date': 'هذا الحقل يجب أن يكون تاريخ',

})
