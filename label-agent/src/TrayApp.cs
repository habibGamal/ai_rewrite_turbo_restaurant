using System.Drawing;

namespace TurboLabel;

/// <summary>
/// The icon next to the clock: choose the label printer, see and clear
/// the allowed POS sites, start with Windows, exit.
/// </summary>
public sealed class TrayApp : ApplicationContext, IAgentUi
{
    private readonly AgentConfig _config;
    private readonly LabelPrinter _printer = new();
    private readonly AgentServer _server;
    private readonly NotifyIcon _icon;
    private readonly Control _ui;
    private string _status = "جاري التشغيل...";

    public TrayApp(AgentConfig config)
    {
        _config = config;

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

        _server = new AgentServer(config, _printer, this);
        _ = StartServer();
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

        var sites = new ToolStripMenuItem("المواقع المسموح لها بالطباعة");
        foreach (var origin in _config.AllowedOrigins.ToList())
        {
            sites.DropDownItems.Add(new ToolStripMenuItem(origin) { Enabled = false });
        }
        if (_config.AllowedOrigins.Count > 0)
        {
            sites.DropDownItems.Add(new ToolStripSeparator());
            var clear = new ToolStripMenuItem("إلغاء السماح للكل");
            clear.Click += (_, _) =>
            {
                _config.AllowedOrigins.Clear();
                _config.Save();
            };
            sites.DropDownItems.Add(clear);
        }
        else
        {
            sites.DropDownItems.Add(new ToolStripMenuItem("لا يوجد") { Enabled = false });
        }
        menu.Items.Add(sites);

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

    protected override void ExitThreadCore()
    {
        _icon.Visible = false;
        // Off the UI thread: waiting here for a continuation queued to it would hang.
        Task.Run(_server.StopAsync).Wait(TimeSpan.FromSeconds(3));
        base.ExitThreadCore();
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            _icon.Dispose();
            _printer.Dispose();
            _ui.Dispose();
        }

        base.Dispose(disposing);
    }
}
