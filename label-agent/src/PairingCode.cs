using System.Text;
using System.Text.Json;
using System.Web;

namespace TurboLabel;

/// <summary>
/// The code the POS shows when a drawer or kitchen printer is set to print
/// through this app: "TL1." + base64url of {"s": server, "t": token, "n": name, "k": target key}.
/// It can also arrive inside a link, turbo-label://pair?code=TL1....
/// </summary>
public sealed record PairingCode(string Server, string Token, string Terminal, string Key = "")
{
    private const string Prefix = "TL1.";

    public static PairingCode? Parse(string? text)
    {
        text = text?.Trim();
        if (string.IsNullOrEmpty(text))
        {
            return null;
        }

        if (text.StartsWith("turbo-label:", StringComparison.OrdinalIgnoreCase)
            && Uri.TryCreate(text, UriKind.Absolute, out var link))
        {
            text = HttpUtility.ParseQueryString(link.Query)["code"]?.Trim();
            if (string.IsNullOrEmpty(text))
            {
                return null;
            }
        }

        if (!text.StartsWith(Prefix, StringComparison.Ordinal))
        {
            return null;
        }

        try
        {
            var base64 = text[Prefix.Length..].Replace('-', '+').Replace('_', '/');
            base64 = base64.PadRight(base64.Length + (4 - base64.Length % 4) % 4, '=');
            using var json = JsonDocument.Parse(Encoding.UTF8.GetString(Convert.FromBase64String(base64)));
            var root = json.RootElement;
            var server = root.GetProperty("s").GetString()?.TrimEnd('/') ?? "";
            var token = root.GetProperty("t").GetString() ?? "";
            var terminal = root.TryGetProperty("n", out var name) ? name.GetString() ?? "" : "";
            var key = root.TryGetProperty("k", out var keyProp) ? keyProp.GetString() ?? "" : "";

            if (!Uri.TryCreate(server, UriKind.Absolute, out var uri)
                || (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps)
                || token.Length < 20)
            {
                return null;
            }

            return new PairingCode(server, token, terminal, key);
        }
        catch (Exception)
        {
            return null;
        }
    }

    public static bool IsPairLink(string? arg)
    {
        return arg is not null && arg.StartsWith("turbo-label://pair", StringComparison.OrdinalIgnoreCase);
    }
}
