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
                targetAddressSpace: 'local',
            });

            return response.json();
        };

        const print = async (manifest, browserUrl) => {
            notify('جاري إرسال الملصقات للطابعة...', null, 'info');

            let result = null;
            for (let attempt = 0; attempt < 4 && result === null; attempt++) {
                try {
                    result = await send(manifest);
                } catch (error) {
                    // App not running: start it once, then give it a few seconds.
                    if (attempt === 0) {
                        window.location.assign('turbo-label://start');
                    }
                    await sleep(2000);
                }
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
