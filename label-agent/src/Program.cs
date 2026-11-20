namespace TurboLabel;

internal static class Program
{
    /// <summary>
    /// One copy per Windows user. A second start hands its work to the
    /// running copy and exits: a turbo-label://pair link pairs, opening the
    /// app again shows its window, turbo-label://start does nothing.
    /// </summary>
    [STAThread]
    private static void Main(string[] args)
    {
        var link = args.FirstOrDefault(a => a.StartsWith("turbo-label:", StringComparison.OrdinalIgnoreCase));

        using var mutex = new Mutex(true, @"Local\TurboLabelAgent", out var firstInstance);
        if (!firstInstance)
        {
            TellRunningCopy(link);

            return;
        }

        ApplicationConfiguration.Initialize();

        var config = AgentConfig.Load();
        WindowsIntegration.RegisterProtocol();
        WindowsIntegration.SetAutoStart(config.AutoStart);

        using var tray = new TrayApp(config, link);
        if (link is null && args.Length == 0 && config.Connections.Count == 0 && string.IsNullOrWhiteSpace(config.Printer))
        {
            // First run by hand: show the window so the printers get chosen.
            tray.ShowWindow();
        }

        Application.Run(tray);
    }

    private static void TellRunningCopy(string? link)
    {
        if (link is not null && !PairingCode.IsPairLink(link))
        {
            return; // turbo-label://start: it already runs.
        }

        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
            var port = AgentConfig.Load().Port;
            var url = $"http://127.0.0.1:{port}/" + (link is null ? "show" : "pair");
            using HttpContent content = link is null
                ? new StringContent("{}", System.Text.Encoding.UTF8, "application/json")
                : System.Net.Http.Json.JsonContent.Create(new { code = link });
            http.PostAsync(url, content).GetAwaiter().GetResult().Dispose();
        }
        catch (Exception)
        {
            // The running copy is busy or stuck; nothing more to do here.
        }
    }
}
