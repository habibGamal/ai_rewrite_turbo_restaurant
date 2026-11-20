using Microsoft.Win32;

namespace TurboLabel;

/// <summary>
/// Per-user registry entries (no admin rights needed): the turbo-label://
/// link that starts the app from the browser, and starting with Windows.
/// </summary>
internal static class WindowsIntegration
{
    private const string RunKey = @"Software\Microsoft\Windows\CurrentVersion\Run";
    private const string AppName = "TurboLabel";

    private static string ExePath => Environment.ProcessPath ?? Application.ExecutablePath;

    public static void RegisterProtocol()
    {
        try
        {
            using var key = Registry.CurrentUser.CreateSubKey(@"Software\Classes\turbo-label");
            key.SetValue("", "URL:Turbo Label");
            key.SetValue("URL Protocol", "");
            using var command = key.CreateSubKey(@"shell\open\command");
            command.SetValue("", $"\"{ExePath}\" \"%1\"");
        }
        catch (Exception)
        {
            // Printing still works; only starting from the browser does not.
        }
    }

    public static void SetAutoStart(bool enabled)
    {
        try
        {
            using var key = Registry.CurrentUser.OpenSubKey(RunKey, writable: true);
            if (key is null)
            {
                return;
            }

            if (enabled)
            {
                key.SetValue(AppName, $"\"{ExePath}\"");
            }
            else
            {
                key.DeleteValue(AppName, throwOnMissingValue: false);
            }
        }
        catch (Exception)
        {
            // Not fatal.
        }
    }
}
