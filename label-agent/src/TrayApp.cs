using System.Drawing;

namespace TurboLabel;

/// <summary>
/// The icon next to the clock. Double-click opens the window; the menu has
/// the quick choices (label printer, start with Windows, exit). Also runs
/// the receipt poller for paired drawers.
/// </summary>
public sealed class TrayApp : ApplicationContext, IAgentUi
{
    private readonly AgentConfig _config;
    private readonly LabelPrinter _printer = new();
    private readonly AgentServer _server;
    private readonly NotifyIcon _icon;
    private readonly Control _ui;
    private readonly ActivityLog _log = new();
    private readonly ReceiptPoller _poller;
    private MainWindow? _window;
    private string _status = "جاري التشغيل...";

    public TrayApp(AgentConfig config, string? link = null)
    {
        _config = config;
        _poller = new ReceiptPoller(config, this, _log);

        _ui = new Control();
        _ui.CreateControl();

        var menu = new ContextMenuStrip { RightToLeft = RightToLeft.Yes };
        menu.Opening += (_, _) => BuildMenu(menu);

        _icon = new NotifyIcon
        {
            Icon = SystemIcons.Application,
            Text = "Turbo Label",
            ContextMenuStrip = menu,
            Visible = true,
        };
        _icon.DoubleClick += (_, _) => ShowWindow();

        _server = new AgentServer(config, _printer, this);
        _ = StartServer();
        _poller.Start();

        if (PairingCode.IsPairLink(link))
        {
            Pair(link);
        }
    }

    private async Task StartServer()
    {
        try
        {
            await _server.StartAsync();
            _status = $"جاهز للطباعة (المنفذ {_config.Port})";
            if (string.IsNullOrWhiteSpace(_config.Printer))
            {
                Notify("Turbo Label", "اضغط على الأيقونة دي واختار طابعة الملصقات.", error: false);
            }
        }
        catch (Exception e)
        {
            _status = "لم يعمل: " + e.Message;
            Notify("Turbo Label لم يعمل", $"المنفذ {_config.Port} مستخدم أو مقفول: {e.Message}", error: true);
        }
    }

    private void BuildMenu(ContextMenuStrip menu)
    {
        menu.Items.Clear();
        menu.Items.Add(new ToolStripMenuItem($"Turbo Label {AgentServer.Version}") { Enabled = false });
        menu.Items.Add(new ToolStripMenuItem(_status) { Enabled = false });
        menu.Items.Add(new ToolStripSeparator());

        var open = new ToolStripMenuItem("فتح Turbo Label") { Font = new Font(menu.Font, FontStyle.Bold) };
        open.Click += (_, _) => ShowWindow();
        menu.Items.Add(open);

        var printers = new ToolStripMenuItem("طابعة الملصقات");
        foreach (var name in LabelPrinter.InstalledPrinters())
        {
            var item = new ToolStripMenuItem(name) { Checked = string.Equals(name, _config.Printer, StringComparison.OrdinalIgnoreCase) };
            item.Click += (_, _) =>
            {
                _config.Printer = name;
                _config.Save();
            };
            printers.DropDownItems.Add(item);
        }
        if (printers.DropDownItems.Count == 0)
        {
            printers.DropDownItems.Add(new ToolStripMenuItem("لا توجد طابعات") { Enabled = false });
        }
        menu.Items.Add(printers);

        var receipts = new ToolStripMenuItem("طابعة الفواتير");
        foreach (var name in LabelPrinter.InstalledPrinters())
        {
            var item = new ToolStripMenuItem(name) { Checked = string.Equals(name, _config.ReceiptPrinter, StringComparison.OrdinalIgnoreCase) };
            item.Click += (_, _) => _config.Update(c => c.ReceiptPrinter = name);
            receipts.DropDownItems.Add(item);
        }
        if (receipts.DropDownItems.Count == 0)
        {
            receipts.DropDownItems.Add(new ToolStripMenuItem("لا توجد طابعات") { Enabled = false });
        }
        menu.Items.Add(receipts);

        var autoStart = new ToolStripMenuItem("تشغيل مع الويندوز") { Checked = _config.AutoStart };
        autoStart.Click += (_, _) =>
        {
            _config.AutoStart = !_config.AutoStart;
            _config.Save();
            WindowsIntegration.SetAutoStart(_config.AutoStart);
        };
        menu.Items.Add(autoStart);

        menu.Items.Add(new ToolStripSeparator());
        var exit = new ToolStripMenuItem("خروج");
        exit.Click += (_, _) => ExitThread();
        menu.Items.Add(exit);
    }

    public Task<bool> AskToAllow(string origin)
    {
        var answer = new TaskCompletionSource<bool>();
        _ui.BeginInvoke(() =>
        {
            var result = MessageBox.Show(
                $"السيستم على العنوان:\n{origin}\nعايز يطبع ملصقات على طابعات الجهاز ده.\n\nتسمح له؟",
                "Turbo Label",
                MessageBoxButtons.YesNo,
                MessageBoxIcon.Question,
                MessageBoxDefaultButton.Button1,
                MessageBoxOptions.RightAlign | MessageBoxOptions.RtlReading | MessageBoxOptions.DefaultDesktopOnly);
            answer.TrySetResult(result == DialogResult.Yes);
        });

        return answer.Task;
    }

    public void Notify(string title, string message, bool error)
    {
        _ui.BeginInvoke(() => _icon.ShowBalloonTip(4000, title, message, error ? ToolTipIcon.Error : ToolTipIcon.Info));
    }

    public void Record(string kind, string detail, bool ok)
    {
        _log.Add(kind, detail, ok);
    }

    public void ShowWindow()
    {
        _ui.BeginInvoke(() =>
        {
            _window ??= new MainWindow(_config, _poller, _log, () => _status, Pair);
            _window.ShowAndFocus();
        });
    }

    public void Pair(string? code)
    {
        _ui.BeginInvoke(() =>
        {
            var parsed = PairingCode.Parse(code);
            if (parsed is null)
            {
                MessageBox.Show("كود الربط مش صحيح. انسخه تاني من السيستم («الأدراج» ← «ربط برنامج الطباعة»).", "Turbo Label",
                    MessageBoxButtons.OK, MessageBoxIcon.Error, MessageBoxDefaultButton.Button1,
                    MessageBoxOptions.RightAlign | MessageBoxOptions.RtlReading | MessageBoxOptions.DefaultDesktopOnly);

                return;
            }

            var answer = MessageBox.Show(
                $"ربط الجهاز ده بالدرج «{parsed.Terminal}»\nعلى السيستم: {parsed.Server}\n\nفواتير الدرج ده وفتح درج الفلوس هيطبعوا على طابعة الجهاز ده. موافق؟",
                "Turbo Label",
                MessageBoxButtons.YesNo,
                MessageBoxIcon.Question,
                MessageBoxDefaultButton.Button1,
                MessageBoxOptions.RightAlign | MessageBoxOptions.RtlReading | MessageBoxOptions.DefaultDesktopOnly);
            if (answer != DialogResult.Yes)
            {
                return;
            }

            _config.AddConnection(new PosConnection { Server = parsed.Server, Token = parsed.Token, Terminal = parsed.Terminal });
            _poller.Refresh();
            _log.Add("ربط", $"{parsed.Terminal} ← {parsed.Server}", ok: true);
            if (string.IsNullOrWhiteSpace(_config.ReceiptPrinter))
            {
                Notify("اختار طابعة الفواتير", "اتربط الجهاز بالدرج. اختار طابعة الفواتير من «الطابعات» لو الدرج في السيستم مش محدد له طابعة.", error: false);
            }

            ShowWindow();
        });
    }

    protected override void ExitThreadCore()
    {
        _icon.Visible = false;
        _poller.Dispose();
        // Off the UI thread: waiting here for a continuation queued to it would hang.
        Task.Run(_server.StopAsync).Wait(TimeSpan.FromSeconds(3));
        base.ExitThreadCore();
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            _icon.Dispose();
            _window?.Dispose();
            _printer.Dispose();
            _ui.Dispose();
        }

        base.Dispose(disposing);
    }
}
