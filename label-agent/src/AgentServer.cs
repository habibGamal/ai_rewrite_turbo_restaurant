using System.Net;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.Logging;

namespace TurboLabel;

/// <summary>
/// The local web server the POS page talks to, on 127.0.0.1 only (nothing
/// else on the network can reach it). Any page may ask /status, so the POS
/// can tell whether the app is running; printing needs the page's site to
/// be allowed once by the person at this PC.
///
/// GET  /status    {ok, version, printer, allowed}
/// GET  /printers  {printers: [...], default}
/// POST /print     {manifest, printer?} -> {ok, labels} or {ok:false, error}
/// </summary>
public sealed class AgentServer(AgentConfig config, LabelPrinter printer, IAgentUi ui)
{
    public static string Version => typeof(AgentServer).Assembly.GetName().Version?.ToString(3) ?? "1.0.0";

    private WebApplication? _app;

    public async Task StartAsync()
    {
        var builder = WebApplication.CreateBuilder(new WebApplicationOptions
        {
            ContentRootPath = AppContext.BaseDirectory,
        });
        builder.Logging.ClearProviders();
        builder.WebHost.ConfigureKestrel(options => options.Listen(IPAddress.Loopback, config.Port));

        var app = builder.Build();
        app.Use(Cors);

        app.MapGet("/status", (HttpContext context) => Results.Json(new
        {
            ok = true,
            version = Version,
            printer = config.Printer,
            allowed = config.IsAllowed(Origin(context)),
        }));

        app.MapGet("/printers", async (HttpContext context) =>
        {
            if (!await EnsureAllowed(context))
            {
                return Forbidden();
            }

            return Results.Json(new { printers = LabelPrinter.InstalledPrinters(), @default = config.Printer });
        });

        app.MapPost("/print", async (HttpContext context, PrintRequest request) =>
        {
            if (!await EnsureAllowed(context))
            {
                return Forbidden();
            }

            if (!Uri.TryCreate(request.Manifest, UriKind.Absolute, out var manifest)
                || (manifest.Scheme != Uri.UriSchemeHttp && manifest.Scheme != Uri.UriSchemeHttps)
                || !LabelPrinter.SameOrigin(manifest, new Uri(Origin(context))))
            {
                return Error("رابط مهمة الطباعة غير صالح.");
            }

            var printerName = string.IsNullOrWhiteSpace(request.Printer) ? config.Printer : request.Printer;
            if (string.IsNullOrWhiteSpace(printerName))
            {
                ui.Notify("اختار طابعة الملصقات", "اضغط على أيقونة Turbo Label جنب الساعة واختار طابعة الملصقات.", error: true);

                return Error("لم يتم اختيار طابعة الملصقات في برنامج الطباعة. اختارها من أيقونة البرنامج جنب الساعة.");
            }

            try
            {
                var labels = await printer.PrintAsync(manifest, printerName);
                ui.Notify("تمت الطباعة", $"اتبعت {labels} ملصق للطابعة {printerName}.", error: false);

                return Results.Json(new { ok = true, labels, printer = printerName });
            }
            catch (LabelPrintException e)
            {
                return Error(e.Message);
            }
            catch (HttpRequestException e)
            {
                return Error("تعذر تحميل الملصقات من السيستم: " + e.Message);
            }
            catch (Exception e)
            {
                ui.Notify("فشلت الطباعة", e.Message, error: true);

                return Error("فشلت الطباعة: " + e.Message);
            }
        });

        _app = app;
        await app.StartAsync();
    }

    public async Task StopAsync()
    {
        if (_app is not null)
        {
            await _app.StopAsync();
            await _app.DisposeAsync();
        }
    }

    private static string Origin(HttpContext context)
    {
        return context.Request.Headers.Origin.ToString();
    }

    private async Task Cors(HttpContext context, Func<Task> next)
    {
        var origin = Origin(context);
        if (!Uri.TryCreate(origin, UriKind.Absolute, out _))
        {
            // Only web pages talk to this app, and they always send Origin.
            context.Response.StatusCode = StatusCodes.Status403Forbidden;

            return;
        }

        var headers = context.Response.Headers;
        headers.AccessControlAllowOrigin = origin;
        headers.Vary = "Origin";
        headers.AccessControlAllowMethods = "GET, POST, OPTIONS";
        headers.AccessControlAllowHeaders = "Content-Type";
        // Chrome's Private Network Access preflight for pages calling localhost.
        headers["Access-Control-Allow-Private-Network"] = "true";

        if (HttpMethods.IsOptions(context.Request.Method))
        {
            context.Response.StatusCode = StatusCodes.Status204NoContent;

            return;
        }

        await next();
    }

    private readonly SemaphoreSlim _askOneAtATime = new(1, 1);
    private readonly HashSet<string> _refused = new(StringComparer.OrdinalIgnoreCase);

    private async Task<bool> EnsureAllowed(HttpContext context)
    {
        var origin = Origin(context);
        if (config.IsAllowed(origin))
        {
            return true;
        }

        await _askOneAtATime.WaitAsync();
        try
        {
            if (config.IsAllowed(origin))
            {
                return true;
            }
            if (_refused.Contains(origin))
            {
                return false;
            }

            if (await ui.AskToAllow(origin))
            {
                config.Allow(origin);

                return true;
            }

            _refused.Add(origin);

            return false;
        }
        finally
        {
            _askOneAtATime.Release();
        }
    }

    private static IResult Forbidden()
    {
        return Results.Json(new { ok = false, error = "الموقع ده غير مسموح له بالطباعة على الجهاز ده." }, statusCode: StatusCodes.Status403Forbidden);
    }

    private static IResult Error(string message)
    {
        return Results.Json(new { ok = false, error = message }, statusCode: StatusCodes.Status422UnprocessableEntity);
    }
}

public interface IAgentUi
{
    Task<bool> AskToAllow(string origin);

    void Notify(string title, string message, bool error);
}
