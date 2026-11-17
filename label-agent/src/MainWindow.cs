using System.Diagnostics;
using System.Drawing;

namespace TurboLabel;

/// <summary>
/// The app's window: paired drawers and their status, the label and
/// receipt printers with test buttons, allowed POS sites, a log of what
/// was printed, and settings. Closing it only hides it; the app keeps
/// running next to the clock.
/// </summary>
public sealed class MainWindow : Form
{
    private readonly AgentConfig _config;
    private readonly ReceiptPoller _poller;
    private readonly ActivityLog _log;
    private readonly Func<string> _serverStatus;
    private readonly Action<string?> _pair;

    private readonly Label _serverLabel = new() { AutoSize = true, Dock = DockStyle.Top, Padding = new Padding(4, 8, 4, 8) };
    private readonly ListView _connections = NewList(("المتجر", 140), ("الدرج", 120), ("النظام", 220), ("الحالة", 300), ("آخر اتصال", 90));
    private readonly ComboBox _labelPrinter = new() { DropDownStyle = ComboBoxStyle.DropDownList, Width = 320 };
    private readonly ComboBox _receiptPrinter = new() { DropDownStyle = ComboBoxStyle.DropDownList, Width = 320 };
    private readonly NumericUpDown _testWidth = new() { Minimum = 10, Maximum = 200, Value = 38, DecimalPlaces = 0, Width = 60 };
    private readonly NumericUpDown _testHeight = new() { Minimum = 10, Maximum = 300, Value = 25, DecimalPlaces = 0, Width = 60 };
    private readonly ListBox _sites = new() { Dock = DockStyle.Fill, IntegralHeight = false };
    private readonly ListView _activity = NewList(("الوقت", 140), ("النوع", 110), ("التفاصيل", 520), ("النتيجة", 80));
    private readonly CheckBox _autoStart = new() { Text = "التشغيل مع بدء ويندوز", AutoSize = true };
    private readonly NumericUpDown _port = new() { Minimum = 1024, Maximum = 65535, Width = 90 };
    private readonly System.Windows.Forms.Timer _refresh = new() { Interval = 1000 };
    private bool _loading;

    public MainWindow(AgentConfig config, ReceiptPoller poller, ActivityLog log, Func<string> serverStatus, Action<string?> pair)
    {
        _config = config;
        _poller = poller;
        _log = log;
        _serverStatus = serverStatus;
        _pair = pair;

        Text = $"Turbo Label {AgentServer.Version}";
        RightToLeft = RightToLeft.Yes;
        RightToLeftLayout = true;
        StartPosition = FormStartPosition.CenterScreen;
        Size = new Size(920, 560);
        MinimumSize = new Size(720, 420);
        Font = new Font("Segoe UI", 9.5f);
        Icon = SystemIcons.Application;

        var tabs = new TabControl { Dock = DockStyle.Fill, RightToLeftLayout = true };
        tabs.TabPages.Add(DrawersTab());
        tabs.TabPages.Add(PrintersTab());
        tabs.TabPages.Add(SitesTab());
        tabs.TabPages.Add(ActivityTab());
        tabs.TabPages.Add(SettingsTab());
        Controls.Add(tabs);

        _refresh.Tick += (_, _) => RefreshStatus();
        _log.Changed += () =>
        {
            if (IsHandleCreated && Visible)
            {
                BeginInvoke(new Action(RefreshActivity));
            }
        };

        VisibleChanged += (_, _) =>
        {
            if (Visible)
            {
                LoadAll();
                _refresh.Start();
            }
            else
            {
                _refresh.Stop();
            }
        };
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        if (e.CloseReason == CloseReason.UserClosing)
        {
            e.Cancel = true;
            Hide();

            return;
        }

        base.OnFormClosing(e);
    }

    public void ShowAndFocus()
    {
        Show();
        if (WindowState == FormWindowState.Minimized)
        {
            WindowState = FormWindowState.Normal;
        }

        Activate();
    }

    private TabPage DrawersTab()
    {
        var page = new TabPage("الأدراج والفواتير") { Padding = new Padding(8) };

        var help = new Label
        {
            Dock = DockStyle.Top,
            AutoSize = false,
            Height = 44,
            Text = "ليطبع هذا الجهاز فواتير الدرج ويفتح درج النقود: افتح «الأدراج» في النظام، واجعل الطباعة عن طريق «برنامج Turbo Label»، ثم اضغط «ربط برنامج الطباعة» من هذا الجهاز، أو الصق رمز الربط هنا.",
        };

        var buttons = new FlowLayoutPanel { Dock = DockStyle.Bottom, AutoSize = true, Padding = new Padding(0, 6, 0, 0) };
        buttons.Controls.Add(MakeButton("ربط بدرج...", (_, _) => AskForCode()));
        buttons.Controls.Add(MakeButton("إلغاء ربط المحدد", (_, _) => RemoveSelected()));

        page.Controls.Add(_connections);
        page.Controls.Add(buttons);
        page.Controls.Add(help);
        page.Controls.Add(_serverLabel);

        return page;
    }

    private TabPage PrintersTab()
    {
        var page = new TabPage("الطابعات") { Padding = new Padding(12) };
        var layout = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, AutoScroll = true };

        layout.Controls.Add(Heading("طابعة الملصقات"));
        layout.Controls.Add(Row(_labelPrinter));
        layout.Controls.Add(Row(
            new Label { Text = "مقاس ملصق التجربة (مم):", AutoSize = true, Margin = new Padding(3, 7, 3, 3) },
            _testWidth,
            new Label { Text = "×", AutoSize = true, Margin = new Padding(3, 7, 3, 3) },
            _testHeight,
            MakeButton("طباعة ملصق تجربة", (_, _) => TestLabel())));

        layout.Controls.Add(Heading("طابعة الفواتير ودرج النقود"));
        layout.Controls.Add(new Label
        {
            AutoSize = true,
            MaximumSize = new Size(760, 0),
            Text = "تُستخدم إذا لم تُحدَّد للدرج طابعة في النظام. يجب أن تكون طابعة فواتير حرارية (ESC/POS) ودرج النقود متصل بها.",
        });
        layout.Controls.Add(Row(_receiptPrinter));
        layout.Controls.Add(Row(
            MakeButton("طباعة فاتورة تجربة", (_, _) => TestReceipt()),
            MakeButton("فتح درج النقود", (_, _) => KickDrawer())));

        layout.Controls.Add(Row(MakeButton("تحديث قائمة الطابعات", (_, _) =>
        {
            LoadPrinters();
            _poller.Refresh();
        })));

        _labelPrinter.SelectedIndexChanged += (_, _) =>
        {
            if (!_loading)
            {
                _config.Update(c => c.Printer = _labelPrinter.SelectedItem as string);
            }
        };
        _receiptPrinter.SelectedIndexChanged += (_, _) =>
        {
            if (!_loading)
            {
                _config.Update(c => c.ReceiptPrinter = _receiptPrinter.SelectedItem as string);
            }
        };

        page.Controls.Add(layout);

        return page;
    }

    private TabPage SitesTab()
    {
        var page = new TabPage("المواقع المسموحة") { Padding = new Padding(8) };
        var help = new Label { Dock = DockStyle.Top, Height = 30, Text = "مواقع النظام المسموح لها بطباعة الملصقات على هذا الجهاز." };
        var buttons = new FlowLayoutPanel { Dock = DockStyle.Bottom, AutoSize = true, Padding = new Padding(0, 6, 0, 0) };
        buttons.Controls.Add(MakeButton("إلغاء السماح للمحدد", (_, _) =>
        {
            if (_sites.SelectedItem is string origin)
            {
                _config.Disallow(origin);
                LoadSites();
            }
        }));
        buttons.Controls.Add(MakeButton("إلغاء السماح للكل", (_, _) =>
        {
            _config.Disallow(null);
            LoadSites();
        }));

        page.Controls.Add(_sites);
        page.Controls.Add(buttons);
        page.Controls.Add(help);

        return page;
    }

    private TabPage ActivityTab()
    {
        var page = new TabPage("السجل") { Padding = new Padding(8) };
        page.Controls.Add(_activity);

        return page;
    }

    private TabPage SettingsTab()
    {
        var page = new TabPage("الإعدادات") { Padding = new Padding(12) };
        var layout = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1 };

        _autoStart.CheckedChanged += (_, _) =>
        {
            if (_loading)
            {
                return;
            }

            _config.Update(c => c.AutoStart = _autoStart.Checked);
            WindowsIntegration.SetAutoStart(_autoStart.Checked);
        };

        layout.Controls.Add(Row(_autoStart));
        layout.Controls.Add(Row(
            new Label { Text = "منفذ البرنامج:", AutoSize = true, Margin = new Padding(3, 7, 3, 3) },
            _port,
            MakeButton("حفظ", (_, _) =>
            {
                _config.Update(c => c.Port = (int)_port.Value);
                MessageBox.Show(this, "أغلق البرنامج وافتحه من جديد ليعمل المنفذ الجديد. يتصل النظام بالمنفذ 47123، فلا تغيّره إلا بطلب من الدعم الفني.", "Turbo Label",
                    MessageBoxButtons.OK, MessageBoxIcon.Information, MessageBoxDefaultButton.Button1, MessageBoxOptions.RightAlign | MessageBoxOptions.RtlReading);
            })));
        layout.Controls.Add(Row(MakeButton("فتح مجلد الإعدادات", (_, _) =>
        {
            var folder = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "TurboLabel");
            Directory.CreateDirectory(folder);
            Process.Start(new ProcessStartInfo("explorer.exe", $"\"{folder}\"") { UseShellExecute = true });
        })));
        layout.Controls.Add(new Label { AutoSize = true, Text = $"الإصدار {AgentServer.Version}", Margin = new Padding(3, 16, 3, 3) });

        page.Controls.Add(layout);

        return page;
    }

    private void LoadAll()
    {
        LoadPrinters();
        LoadSites();
        _loading = true;
        _autoStart.Checked = _config.AutoStart;
        _port.Value = Math.Clamp(_config.Port, 1024, 65535);
        _loading = false;
        RefreshStatus();
        RefreshActivity();
    }

    private void LoadPrinters()
    {
        _loading = true;
        try
        {
            var printers = LabelPrinter.InstalledPrinters().Cast<object>().ToArray();
            Fill(_labelPrinter, printers, _config.Printer);
            Fill(_receiptPrinter, printers, _config.ReceiptPrinter);
        }
        finally
        {
            _loading = false;
        }
    }

    private static void Fill(ComboBox box, object[] printers, string? selected)
    {
        box.Items.Clear();
        box.Items.AddRange(printers);
        box.SelectedItem = printers.Cast<string>().FirstOrDefault(p => string.Equals(p, selected, StringComparison.OrdinalIgnoreCase));
    }

    private void LoadSites()
    {
        _sites.Items.Clear();
        _sites.Items.AddRange(_config.AllowedSnapshot().Cast<object>().ToArray());
    }

    private void RefreshStatus()
    {
        _serverLabel.Text = _serverStatus();

        var connections = _config.ConnectionsSnapshot();
        var selected = _connections.SelectedItems.Count > 0 ? _connections.SelectedItems[0].Tag as string : null;

        _connections.BeginUpdate();
        _connections.Items.Clear();
        foreach (var connection in connections)
        {
            var state = _poller.State(connection.Server);
            var item = new ListViewItem(new[]
            {
                state.Shop,
                string.IsNullOrEmpty(state.Terminal) ? connection.Terminal : state.Terminal,
                connection.Server,
                state.Status,
                state.LastSeen?.ToString("HH:mm:ss") ?? "-",
            })
            {
                Tag = connection.Server,
                ForeColor = state.Online && state.Enabled ? Color.DarkGreen : Color.Firebrick,
            };
            _connections.Items.Add(item);
            item.Selected = connection.Server == selected;
        }

        if (connections.Count == 0)
        {
            _connections.Items.Add(new ListViewItem(new[] { "", "", "هذا الجهاز غير مربوط بأي درج", "", "" }) { ForeColor = Color.Gray });
        }

        _connections.EndUpdate();
    }

    private void RefreshActivity()
    {
        _activity.BeginUpdate();
        _activity.Items.Clear();
        foreach (var entry in _log.Snapshot())
        {
            _activity.Items.Add(new ListViewItem(new[]
            {
                entry.Time.ToString("yyyy-MM-dd HH:mm:ss"),
                entry.Kind,
                entry.Detail,
                entry.Ok ? "تم" : "فشل",
            })
            { ForeColor = entry.Ok ? Color.Black : Color.Firebrick });
        }

        _activity.EndUpdate();
    }

    private void AskForCode()
    {
        using var dialog = new Form
        {
            Text = "ربط بدرج",
            RightToLeft = RightToLeft.Yes,
            RightToLeftLayout = true,
            StartPosition = FormStartPosition.CenterParent,
            Size = new Size(520, 230),
            FormBorderStyle = FormBorderStyle.FixedDialog,
            MaximizeBox = false,
            MinimizeBox = false,
            Font = Font,
        };
        var label = new Label { Dock = DockStyle.Top, Height = 40, Text = "الصق رمز الربط الذي ظهر في النظام بعد «ربط برنامج الطباعة»:" };
        var input = new TextBox { Dock = DockStyle.Fill, Multiline = true, RightToLeft = RightToLeft.No };
        var buttons = new FlowLayoutPanel { Dock = DockStyle.Bottom, AutoSize = true };
        var ok = new Button { Text = "ربط", DialogResult = DialogResult.OK, AutoSize = true };
        var cancel = new Button { Text = "إلغاء", DialogResult = DialogResult.Cancel, AutoSize = true };
        buttons.Controls.Add(ok);
        buttons.Controls.Add(cancel);
        dialog.Controls.Add(input);
        dialog.Controls.Add(buttons);
        dialog.Controls.Add(label);
        dialog.AcceptButton = ok;
        dialog.CancelButton = cancel;

        if (Clipboard.ContainsText() && PairingCode.Parse(Clipboard.GetText()) is not null)
        {
            input.Text = Clipboard.GetText().Trim();
        }

        if (dialog.ShowDialog(this) == DialogResult.OK)
        {
            _pair(input.Text);
        }
    }

    private void RemoveSelected()
    {
        if (_connections.SelectedItems.Count == 0 || _connections.SelectedItems[0].Tag is not string server)
        {
            return;
        }

        if (Ask($"إلغاء ربط هذا الجهاز بدرج النظام:\n{server}\n\nلن تُطبع الفواتير هنا حتى تربطه من جديد."))
        {
            _config.RemoveConnection(server);
            RefreshStatus();
        }
    }

    private void TestLabel()
    {
        if (_labelPrinter.SelectedItem is not string printer)
        {
            Say("اختر طابعة الملصقات أولاً.", error: true);

            return;
        }

        Run("ملصق تجربة", printer, () => LabelPrinter.PrintTestLabel(printer, (double)_testWidth.Value, (double)_testHeight.Value));
    }

    private void TestReceipt()
    {
        if (_receiptPrinter.SelectedItem is not string printer)
        {
            Say("اختر طابعة الفواتير أولاً.", error: true);

            return;
        }

        Run("فاتورة تجربة", printer, () => RawPrinter.Send(printer, RawPrinter.TestPage(), "Turbo Label - test"));
    }

    private void KickDrawer()
    {
        if (_receiptPrinter.SelectedItem is not string printer)
        {
            Say("اختر طابعة الفواتير أولاً.", error: true);

            return;
        }

        Run("فتح الدرج", printer, () => RawPrinter.Send(printer, RawPrinter.DrawerKick, "Turbo Label - drawer"));
    }

    private void Run(string kind, string printer, Action print)
    {
        UseWaitCursor = true;
        Task.Run(print).ContinueWith(task =>
        {
            BeginInvoke(() =>
            {
                UseWaitCursor = false;
                var error = task.Exception?.InnerException?.Message;
                _log.Add(kind, printer + (error is null ? "" : ": " + error), error is null);
                if (error is not null)
                {
                    Say(error, error: true);
                }
            });
        });
    }

    private bool Ask(string message)
    {
        return MessageBox.Show(this, message, "Turbo Label", MessageBoxButtons.YesNo, MessageBoxIcon.Question,
            MessageBoxDefaultButton.Button2, MessageBoxOptions.RightAlign | MessageBoxOptions.RtlReading) == DialogResult.Yes;
    }

    private void Say(string message, bool error)
    {
        MessageBox.Show(this, message, "Turbo Label", MessageBoxButtons.OK, error ? MessageBoxIcon.Error : MessageBoxIcon.Information,
            MessageBoxDefaultButton.Button1, MessageBoxOptions.RightAlign | MessageBoxOptions.RtlReading);
    }

    private static ListView NewList(params (string Title, int Width)[] columns)
    {
        var list = new ListView
        {
            Dock = DockStyle.Fill,
            View = View.Details,
            FullRowSelect = true,
            MultiSelect = false,
            HideSelection = false,
            RightToLeftLayout = true,
        };
        foreach (var (title, width) in columns)
        {
            list.Columns.Add(title, width);
        }

        return list;
    }

    private static Button MakeButton(string text, EventHandler onClick)
    {
        var button = new Button { Text = text, AutoSize = true, Padding = new Padding(6, 2, 6, 2) };
        button.Click += onClick;

        return button;
    }

    private static Label Heading(string text)
    {
        return new Label { Text = text, AutoSize = true, Font = new Font("Segoe UI", 11f, FontStyle.Bold), Margin = new Padding(3, 12, 3, 4) };
    }

    private static FlowLayoutPanel Row(params Control[] controls)
    {
        var row = new FlowLayoutPanel { AutoSize = true, WrapContents = false, Margin = new Padding(0, 2, 0, 2) };
        row.Controls.AddRange(controls);

        return row;
    }
}
