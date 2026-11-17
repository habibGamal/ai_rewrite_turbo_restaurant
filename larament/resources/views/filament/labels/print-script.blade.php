{{-- Hands a label print job to the Turbo Label app on this PC (127.0.0.1), starting it through
     turbo-label:// when it does not answer; without the app, offers printing from the browser. --}}
<script>
    window.turboLabels = window.turboLabels || (() => {
        const agent = 'http://127.0.0.1:47123';
        const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

        const notify = (title, body, status, browserUrl = null) => {
            if (! window.FilamentNotification) {
                return;
            }
            const notification = new window.FilamentNotification().title(title).body(body ?? '')[status]();
            if (browserUrl) {
                notification.persistent().actions([
                    new window.FilamentNotificationAction('browser')
                        .label('اطبع من المتصفح')
                        .button()
                        .url(browserUrl)
                        .openUrlInNewTab(),
                ]);
            }
            notification.send();
        };

        const send = async (manifest) => {
            const response = await fetch(agent + '/print', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ manifest }),
            });

            return response.json();
        };

        // Is the app answering? (no CORS preflight, so it tells "not running" apart from "blocked")
        const running = async () => {
            try {
                const response = await fetch(agent + '/status');

                return response.ok;
            } catch (error) {
                return false;
            }
        };

        const print = async (manifest, browserUrl) => {
            notify('جاري إرسال الملصقات للطابعة...', null, 'info');

            if (! await running()) {
                // App not running: start it, then give it a few seconds.
                window.location.assign('turbo-label://start');
                for (let attempt = 0; attempt < 5 && ! await running(); attempt++) {
                    await sleep(1500);
                }
            }

            let result = null;
            let failure = null;
            try {
                result = await send(manifest);
            } catch (error) {
                failure = error;
                console.error('[Turbo Label] print request failed', error);
            }

            if (result === null && await running()) {
                notify(
                    'المتصفح منع الاتصال ببرنامج الطباعة',
                    'البرنامج شغال لكن الطلب اترفض (' + (failure?.message ?? '') + '). افتح Console في أدوات المطور وابعت الخطأ.',
                    'danger',
                    browserUrl,
                );

                return;
            }

            if (result === null) {
                notify(
                    'برنامج طباعة الملصقات غير شغال على الجهاز ده',
                    'شغّل برنامج Turbo Label أو اطبع من المتصفح.',
                    'warning',
                    browserUrl,
                );
            } else if (result.ok) {
                notify('تم إرسال ' + result.labels + ' ملصق للطابعة', result.printer ?? '', 'success');
            } else {
                notify('لم تتم الطباعة', result.error ?? '', 'danger', browserUrl);
            }
        };

        return { print };
    })();
</script>
