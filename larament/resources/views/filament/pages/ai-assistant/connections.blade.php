<x-filament-panels::page>
    <x-filament::section collapsible>
        <x-slot name="heading">طريقة الربط</x-slot>
        <x-slot name="description">يقرأ المساعد بيانات المتجر فقط، ولا يستطيع تعديل أي شيء. يرى كل مستخدم من البيانات ما تسمح به صلاحياته في التقارير.</x-slot>

        <div class="space-y-4 text-sm">
            <div>
                <div class="mb-1 font-medium">رابط الخادم</div>
                <div class="flex items-center gap-2">
                    <code dir="ltr" class="rounded bg-gray-100 px-2 py-1 font-mono dark:bg-gray-800">{{ $this->mcpUrl() }}</code>
                    <x-filament::icon-button
                        icon="heroicon-o-clipboard-document"
                        label="نسخ الرابط"
                        x-on:click="navigator.clipboard.writeText(@js($this->mcpUrl())); $tooltip('تم النسخ')"
                    />
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <div class="mb-1 font-medium">ChatGPT</div>
                    <ol class="list-decimal space-y-1 ps-5 text-gray-600 dark:text-gray-300">
                        <li>الإعدادات، ثم التطبيقات والموصلات، ثم فعّل وضع المطوّر.</li>
                        <li>أنشئ موصلاً جديداً والصق الرابط، واختر المصادقة OAuth واترك باقي الحقول فارغة.</li>
                        <li>سجّل الدخول ببريدك وكلمة المرور، ثم اضغط «موافقة».</li>
                    </ol>
                </div>
                <div>
                    <div class="mb-1 font-medium">Claude</div>
                    <ol class="list-decimal space-y-1 ps-5 text-gray-600 dark:text-gray-300">
                        <li>الإعدادات، ثم الموصلات، ثم «إضافة موصل مخصص».</li>
                        <li>الصق الرابط واضغط «إضافة»، ثم «اتصال».</li>
                        <li>سجّل الدخول ثم اضغط «موافقة».</li>
                    </ol>
                </div>
                <div>
                    <div class="mb-1 font-medium">Gemini</div>
                    <ol class="list-decimal space-y-1 ps-5 text-gray-600 dark:text-gray-300">
                        <li>الإعدادات، ثم التطبيقات المتصلة، ثم إضافة خادم MCP.</li>
                        <li>الصق الرابط واترك معرّف العميل وكلمة سره فارغين.</li>
                        <li>سجّل الدخول ثم اضغط «موافقة».</li>
                    </ol>
                </div>
                <div>
                    <div class="mb-1 font-medium">Claude Code</div>
                    <code dir="ltr" class="block rounded bg-gray-100 px-2 py-1 font-mono text-xs dark:bg-gray-800">claude mcp add --transport http pos {{ $this->mcpUrl() }}</code>
                    <p class="mt-1 text-gray-600 dark:text-gray-300">ثم اكتب ‎/mcp‎ في الجلسة واختر المصادقة.</p>
                </div>
            </div>

            <p class="text-gray-500 dark:text-gray-400">الدخول بالبادج لا يكفي للربط: سجّل الدخول بالبريد الإلكتروني وكلمة المرور. يبقى الربط ثلاثين يوماً من آخر استخدام، ويمكنك إلغاؤه في أي وقت من الجدول أدناه.</p>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
