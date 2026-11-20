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

    /// <summary>Windows printer for receipts and the cash drawer when the POS does not name one.</summary>
    public string? ReceiptPrinter { get; set; }

    /// <summary>Drawers this PC prints receipts for (paired with a code from the POS).</summary>
    public List<PosConnection> Connections { get; set; } = new();

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

    public List<string> AllowedSnapshot()
    {
        lock (Gate)
        {
            return AllowedOrigins.ToList();
        }
    }

    public void Disallow(string? origin)
    {
        lock (Gate)
        {
            if (origin is null)
            {
                AllowedOrigins.Clear();
            }
            else
            {
                AllowedOrigins.RemoveAll(o => string.Equals(o, origin, StringComparison.OrdinalIgnoreCase));
            }
        }

        Save();
    }

    public List<PosConnection> ConnectionsSnapshot()
    {
        lock (Gate)
        {
            return Connections.Select(c => c with { }).ToList();
        }
    }

    /// <summary>Replace only an older pairing for the same target on the same POS, allowing a PC to pair a sales point and kitchen printers together.</summary>
    public void AddConnection(PosConnection connection)
    {
        lock (Gate)
        {
            Connections.RemoveAll(c =>
                string.Equals(c.Token, connection.Token, StringComparison.Ordinal)
                || (string.Equals(c.Server, connection.Server, StringComparison.OrdinalIgnoreCase)
                    && (!string.IsNullOrEmpty(c.Key) && !string.IsNullOrEmpty(connection.Key)
                        ? string.Equals(c.Key, connection.Key, StringComparison.OrdinalIgnoreCase)
                        : string.Equals(c.Terminal, connection.Terminal, StringComparison.OrdinalIgnoreCase))));
            Connections.Add(connection);
        }

        Save();
    }

    public void RemoveConnection(string token)
    {
        lock (Gate)
        {
            Connections.RemoveAll(c => string.Equals(c.Token, token, StringComparison.Ordinal));
        }

        Save();
    }

    /// <summary>Change settings from the window and save.</summary>
    public void Update(Action<AgentConfig> change)
    {
        lock (Gate)
        {
            change(this);
        }

        Save();
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

/// <summary>
/// A drawer of a POS this PC prints receipts for. The token is the drawer's
/// secret from the pairing code; the POS keeps only its hash.
/// </summary>
public sealed record PosConnection
{
    public string Server { get; init; } = "";

    public string Token { get; init; } = "";

    public string Terminal { get; init; } = "";

    public string Key { get; init; } = "";

    public DateTime PairedAt { get; init; } = DateTime.Now;
}
