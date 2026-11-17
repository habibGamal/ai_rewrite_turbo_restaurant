namespace TurboLabel;

internal static class Program
{
    /// <summary>
    /// One copy per Windows user. A second start (for example from a
    /// turbo-label:// link while the app already runs) just exits.
    /// </summary>
    [STAThread]
    private static void Main(string[] args)
    {
        using var mutex = new Mutex(true, @"Local\TurboLabelAgent", out var firstInstance);
        if (!firstInstance)
        {
            return;
        }

        ApplicationConfiguration.Initialize();

        var config = AgentConfig.Load();
        WindowsIntegration.RegisterProtocol();
        WindowsIntegration.SetAutoStart(config.AutoStart);

        using var tray = new TrayApp(config);
        Application.Run(tray);
    }
}
