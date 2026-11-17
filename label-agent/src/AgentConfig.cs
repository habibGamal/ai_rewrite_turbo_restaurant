using System.Text.Json;

namespace TurboLabel;

/// <summary>
/// Settings kept in %APPDATA%\TurboLabel\config.json.
/// </summary>
public sealed class AgentConfig
{
    public const int DefaultPort = 47123;

    public int Port { get; set; } = DefaultPort;

    /// <summary>Windows printer used when the POS does not name one.</summary>
    public string? Printer { get; set; }

    /// <summary>POS addresses (scheme://host:port) allowed to print here.</summary>
    public List<string> AllowedOrigins { get; set; } = new();

    public bool AutoStart { get; set; } = true;

    private static readonly object Gate = new();

    private static string Folder => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "TurboLabel");

    private static string FilePath => Path.Combine(Folder, "config.json");

    public static AgentConfig Load()
    {
        try
        {
            if (File.Exists(FilePath))
            {
                return JsonSerializer.Deserialize<AgentConfig>(File.ReadAllText(FilePath)) ?? new AgentConfig();
            }
        }
        catch (Exception)
        {
            // A broken file starts over with defaults.
        }

        return new AgentConfig();
    }

    public void Save()
    {
        lock (Gate)
        {
            Directory.CreateDirectory(Folder);
            File.WriteAllText(FilePath, JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true }));
        }
    }

    public bool IsAllowed(string origin)
    {
        lock (Gate)
        {
            return AllowedOrigins.Contains(origin, StringComparer.OrdinalIgnoreCase);
        }
    }

    public void Allow(string origin)
    {
        lock (Gate)
        {
            if (!AllowedOrigins.Contains(origin, StringComparer.OrdinalIgnoreCase))
            {
                AllowedOrigins.Add(origin);
            }
        }

        Save();
    }
}
