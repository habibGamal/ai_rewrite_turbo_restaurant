using System.Collections.Concurrent;
using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json.Serialization;

namespace TurboLabel;

/// <summary>What the window shows for one paired drawer.</summary>
public sealed class ConnectionState
{
    public string Shop { get; set; } = "";

    public string Terminal { get; set; } = "";

    public bool Online { get; set; }

    public bool Enabled { get; set; } = true;

    public string Status { get; set; } = "جاري الاتصال...";

    public DateTime? LastSeen { get; set; }

    public DateTime LastHello { get; set; } = DateTime.MinValue;

    public DateTime RetryAt { get; set; } = DateTime.MinValue;
}

/// <summary>
/// Asks each paired POS every second or so for receipts and cash drawer
/// kicks queued for this PC's drawer, prints them raw on the receipt
/// printer, and tells the POS how it went. Pulling (rather than the POS
/// calling this PC) works through any router or firewall, including a POS
/// hosted on a VPS.
/// </summary>
public sealed class ReceiptPoller(AgentConfig config, IAgentUi ui, ActivityLog log) : IDisposable
{
    private static readonly TimeSpan Interval = TimeSpan.FromSeconds(1.5);
    private static readonly TimeSpan HelloEvery = TimeSpan.FromMinutes(1);

    private readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(20) };
    private readonly CancellationTokenSource _stop = new();
    private readonly ConcurrentDictionary<string, ConnectionState> _states = new(StringComparer.OrdinalIgnoreCase);

    public void Start()
    {
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        _http.DefaultRequestHeaders.UserAgent.ParseAdd($"TurboLabel/{AgentServer.Version}");
        _ = Task.Run(Loop);
    }

    public ConnectionState State(string server)
    {
        return _states.GetOrAdd(server, _ => new ConnectionState());
    }

    /// <summary>Say hello again soon (after pairing or changing printers).</summary>
    public void Refresh()
    {
        foreach (var state in _states.Values)
        {
            state.LastHello = DateTime.MinValue;
            state.RetryAt = DateTime.MinValue;
        }
    }

    private async Task Loop()
    {
        while (!_stop.IsCancellationRequested)
        {
            var busy = false;
            foreach (var connection in config.ConnectionsSnapshot())
            {
                try
                {
                    busy |= await Poll(connection);
                }
                catch (Exception e) when (!_stop.IsCancellationRequested)
                {
                    var state = State(connection.Server);
                    state.Online = false;
                    state.Status = "خطأ: " + e.Message;
                }
            }

            try
            {
                await Task.Delay(busy ? TimeSpan.FromMilliseconds(200) : Interval, _stop.Token);
            }
            catch (TaskCanceledException)
            {
                return;
            }
        }
    }

    /// <returns>Whether there were jobs (then ask again right away).</returns>
    private async Task<bool> Poll(PosConnection connection)
    {
        var state = State(connection.Server);
        if (DateTime.Now < state.RetryAt)
        {
            return false;
        }

        if (DateTime.Now - state.LastHello > HelloEvery)
        {
            await Hello(connection, state);
        }

        using var request = Request(HttpMethod.Get, connection, "/api/print-agent/jobs");
        using var response = await Send(request, state);
        if (response is null)
        {
            return false;
        }

        var answer = await response.Content.ReadFromJsonAsync<JobsAnswer>(_stop.Token);
        if (answer is null)
        {
            return false;
        }

        Seen(state, answer);

        foreach (var job in answer.Jobs)
        {
            await Print(connection, state, job);
        }

        return answer.Jobs.Count > 0;
    }

    private async Task Hello(PosConnection connection, ConnectionState state)
    {
        using var request = Request(HttpMethod.Post, connection, "/api/print-agent/hello");
        request.Content = JsonContent.Create(new
        {
            version = AgentServer.Version,
            machine = Environment.MachineName,
            printers = LabelPrinter.InstalledPrinters(),
        });

        using var response = await Send(request, state);
        if (response is null)
        {
            return;
        }

        state.LastHello = DateTime.Now;
        var answer = await response.Content.ReadFromJsonAsync<JobsAnswer>(_stop.Token);
        if (answer is not null)
        {
            Seen(state, answer);
        }
    }

    private static void Seen(ConnectionState state, JobsAnswer answer)
    {
        state.Online = true;
        state.LastSeen = DateTime.Now;
        state.Shop = answer.Shop ?? "";
        state.Terminal = answer.Terminal ?? state.Terminal;
        state.Enabled = answer.Enabled;
        state.Status = answer.Enabled
            ? "متصل وجاهز لطباعة الفواتير"
            : "متصل، لكن منفذ البيع مضبوط على الطباعة من الخادم لا من البرنامج";
    }

    private async Task Print(PosConnection connection, ConnectionState state, AgentJob job)
    {
        var printerName = !string.IsNullOrWhiteSpace(job.Printer) ? job.Printer : config.ReceiptPrinter;
        var what = string.IsNullOrWhiteSpace(job.Label) ? job.Kind : job.Label;
        string? error = null;

        if (string.IsNullOrWhiteSpace(printerName))
        {
            error = "لم تُختر طابعة فواتير في برنامج Turbo Label على جهاز منفذ البيع.";
            ui.Notify("اختر طابعة الفواتير", "افتح Turbo Label واختر طابعة الفواتير من «الطابعات».", error: true);
        }
        else
        {
            try
            {
                var bytes = Convert.FromBase64String(job.Data);
                await Task.Run(() => RawPrinter.Send(printerName, bytes, $"Turbo POS - {what}"));
            }
            catch (LabelPrintException e)
            {
                error = e.Message;
            }
            catch (Exception e)
            {
                error = "فشلت الطباعة: " + e.Message;
            }
        }

        log.Add(what, $"{state.Terminal} → {printerName ?? "-"}" + (error is null ? "" : $": {error}"), error is null);
        if (error is not null && !string.IsNullOrWhiteSpace(printerName))
        {
            ui.Notify("فشلت طباعة " + what, error, error: true);
        }

        using var request = Request(HttpMethod.Post, connection, $"/api/print-agent/jobs/{job.Id}");
        request.Content = JsonContent.Create(new { ok = error is null, error });
        using var reported = await Send(request, state);
    }

    private static HttpRequestMessage Request(HttpMethod method, PosConnection connection, string path)
    {
        var request = new HttpRequestMessage(method, connection.Server.TrimEnd('/') + path);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", connection.Token);

        return request;
    }

    /// <returns>The answer, or null after noting why it failed.</returns>
    private async Task<HttpResponseMessage?> Send(HttpRequestMessage request, ConnectionState state)
    {
        HttpResponseMessage response;
        try
        {
            response = await _http.SendAsync(request, _stop.Token);
        }
        catch (Exception e) when (!_stop.IsCancellationRequested)
        {
            state.Online = false;
            state.Status = "تعذر الوصول إلى النظام: " + e.Message;
            state.RetryAt = DateTime.Now.AddSeconds(5);

            return null;
        }

        if (response.IsSuccessStatusCode)
        {
            return response;
        }

        state.Online = false;
        if (response.StatusCode == HttpStatusCode.Unauthorized)
        {
            state.Status = "أُلغي الربط من النظام. أنشئ رمز ربط جديداً من «منافذ البيع».";
            state.RetryAt = DateTime.Now.AddSeconds(30);
        }
        else
        {
            state.Status = $"ردّ النظام بالخطأ {(int)response.StatusCode}";
            state.RetryAt = DateTime.Now.AddSeconds(5);
        }

        response.Dispose();

        return null;
    }

    public void Dispose()
    {
        _stop.Cancel();
        _http.Dispose();
        _stop.Dispose();
    }

    private sealed class JobsAnswer
    {
        [JsonPropertyName("shop")]
        public string? Shop { get; set; }

        [JsonPropertyName("terminal")]
        public string? Terminal { get; set; }

        [JsonPropertyName("enabled")]
        public bool Enabled { get; set; }

        [JsonPropertyName("jobs")]
        public List<AgentJob> Jobs { get; set; } = new();
    }

    private sealed class AgentJob
    {
        [JsonPropertyName("id")]
        public long Id { get; set; }

        [JsonPropertyName("kind")]
        public string Kind { get; set; } = "";

        [JsonPropertyName("label")]
        public string? Label { get; set; }

        [JsonPropertyName("printer")]
        public string? Printer { get; set; }

        [JsonPropertyName("data")]
        public string Data { get; set; } = "";
    }
}
