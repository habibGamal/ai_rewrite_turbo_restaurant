using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Printing;
using System.Net.Http.Json;

namespace TurboLabel;

/// <summary>
/// Downloads a job's page images and prints them through the Windows
/// driver at the label's real size, with no margins and no scaling. One
/// job at a time.
/// </summary>
public sealed class LabelPrinter : IDisposable
{
    private readonly HttpClient _http = NewClient();
    private readonly SemaphoreSlim _oneAtATime = new(1, 1);

    private static HttpClient NewClient()
    {
        var http = new HttpClient { Timeout = TimeSpan.FromSeconds(60) };
        // Some hosting firewalls refuse requests with no User-Agent.
        http.DefaultRequestHeaders.UserAgent.ParseAdd($"TurboLabel/{AgentServer.Version}");

        return http;
    }

    public static IReadOnlyList<string> InstalledPrinters()
    {
        return PrinterSettings.InstalledPrinters.Cast<string>().ToList();
    }

    /// <returns>How many labels were sent to the printer.</returns>
    public async Task<int> PrintAsync(Uri manifestUrl, string printerName, CancellationToken cancel = default)
    {
        using var manifestResponse = await Fetch(manifestUrl, cancel);
        var job = await manifestResponse.Content.ReadFromJsonAsync<LabelJob>(cancel)
            ?? throw new LabelPrintException("مهمة الطباعة فاضية.");

        if (job.Pages.Count == 0 || job.Page.WidthMm <= 0 || job.Page.HeightMm <= 0)
        {
            throw new LabelPrintException("مهمة الطباعة فاضية.");
        }

        var images = new List<Bitmap>();
        try
        {
            foreach (var page in job.Pages)
            {
                var imageUrl = new Uri(manifestUrl, page.Image);
                if (!SameOrigin(imageUrl, manifestUrl))
                {
                    throw new LabelPrintException("رابط صورة الملصق من موقع مختلف.");
                }

                using var imageResponse = await Fetch(imageUrl, cancel);
                using var memory = new MemoryStream();
                await imageResponse.Content.CopyToAsync(memory, cancel);
                memory.Position = 0;
                // GDI+ needs a bitmap's stream for its whole life; copy it free.
                using var loaded = new Bitmap(memory);
                images.Add(new Bitmap(loaded));
            }

            await _oneAtATime.WaitAsync(cancel);
            try
            {
                await Task.Run(() => Print(job, images, printerName), cancel);
            }
            finally
            {
                _oneAtATime.Release();
            }
        }
        finally
        {
            foreach (var image in images)
            {
                image.Dispose();
            }
        }

        return job.TotalLabels;
    }

    /// <summary>
    /// GET that turns a failed answer into the server's own message (the POS
    /// explains in plain text why a label could not be drawn).
    /// </summary>
    private async Task<HttpResponseMessage> Fetch(Uri url, CancellationToken cancel)
    {
        var response = await _http.GetAsync(url, cancel);
        if (response.IsSuccessStatusCode)
        {
            return response;
        }

        var body = await response.Content.ReadAsStringAsync(cancel);
        response.Dispose();
        var message = body.TrimStart().StartsWith('<') || body.Length > 400
            ? $"السيرفر رد بخطأ {(int)response.StatusCode}."
            : body.Trim();

        throw new LabelPrintException(message);
    }

    public static bool SameOrigin(Uri a, Uri b)
    {
        return Uri.Compare(a, b, UriComponents.SchemeAndServer, UriFormat.Unescaped, StringComparison.OrdinalIgnoreCase) == 0;
    }

    private static void Print(LabelJob job, IReadOnlyList<Bitmap> images, string printerName)
    {
        // Paper sizes are in hundredths of an inch.
        var width = (int)Math.Round(job.Page.WidthMm / 25.4 * 100);
        var height = (int)Math.Round(job.Page.HeightMm / 25.4 * 100);

        using var document = new PrintDocument();
        document.DocumentName = $"Turbo Label - {job.Template}";
        document.PrintController = new StandardPrintController(); // no "Printing..." window
        document.PrinterSettings.PrinterName = printerName;
        if (!document.PrinterSettings.IsValid)
        {
            throw new LabelPrintException($"الطابعة «{printerName}» مش موجودة على الجهاز ده.");
        }

        var paper = new PaperSize("Turbo Label", width, height);
        document.DefaultPageSettings.PaperSize = paper;
        document.DefaultPageSettings.Margins = new Margins(0, 0, 0, 0);
        document.DefaultPageSettings.Landscape = false;
        document.OriginAtMargins = false;

        var pageIndex = 0;
        var copy = 0;

        document.QueryPageSettings += (_, e) =>
        {
            e.PageSettings.PaperSize = paper;
            e.PageSettings.Margins = new Margins(0, 0, 0, 0);
            e.PageSettings.Landscape = false;
        };

        document.PrintPage += (_, e) =>
        {
            var graphics = e.Graphics!;
            // Draw from the paper's physical corner, not the printable area's.
            graphics.TranslateTransform(-e.PageSettings.HardMarginX, -e.PageSettings.HardMarginY);
            graphics.InterpolationMode = InterpolationMode.NearestNeighbor;
            graphics.PixelOffsetMode = PixelOffsetMode.Half;
            graphics.SmoothingMode = SmoothingMode.None;
            graphics.DrawImage(images[pageIndex], new RectangleF(0, 0, width, height));

            copy++;
            if (copy >= Math.Max(1, job.Pages[pageIndex].Copies))
            {
                copy = 0;
                pageIndex++;
            }

            e.HasMorePages = pageIndex < images.Count;
        };

        document.Print();
    }

    /// <summary>
    /// A test label drawn here (no POS needed): a frame, the app name and
    /// the time, at the given label size.
    /// </summary>
    public static void PrintTestLabel(string printerName, double widthMm, double heightMm)
    {
        var width = (int)Math.Round(widthMm / 25.4 * 100);
        var height = (int)Math.Round(heightMm / 25.4 * 100);

        using var document = new PrintDocument();
        document.DocumentName = "Turbo Label - test";
        document.PrintController = new StandardPrintController();
        document.PrinterSettings.PrinterName = printerName;
        if (!document.PrinterSettings.IsValid)
        {
            throw new LabelPrintException($"الطابعة «{printerName}» مش موجودة على الجهاز ده.");
        }

        var paper = new PaperSize("Turbo Label", width, height);
        document.DefaultPageSettings.PaperSize = paper;
        document.DefaultPageSettings.Margins = new Margins(0, 0, 0, 0);
        document.QueryPageSettings += (_, e) =>
        {
            e.PageSettings.PaperSize = paper;
            e.PageSettings.Margins = new Margins(0, 0, 0, 0);
        };
        document.PrintPage += (_, e) =>
        {
            var graphics = e.Graphics!;
            graphics.TranslateTransform(-e.PageSettings.HardMarginX, -e.PageSettings.HardMarginY);
            using var pen = new Pen(Color.Black, 2);
            graphics.DrawRectangle(pen, 4, 4, width - 8, height - 8);
            using var big = new Font("Arial", 10, FontStyle.Bold);
            using var small = new Font("Arial", 7);
            var centre = new StringFormat { Alignment = StringAlignment.Center, LineAlignment = StringAlignment.Center };
            graphics.DrawString("Turbo Label", big, Brushes.Black, new RectangleF(0, 0, width, height * 0.55f), centre);
            graphics.DrawString($"{widthMm}×{heightMm} mm  {DateTime.Now:HH:mm:ss}", small, Brushes.Black, new RectangleF(0, height * 0.45f, width, height * 0.5f), centre);
            e.HasMorePages = false;
        };

        document.Print();
    }

    public void Dispose()
    {
        _http.Dispose();
        _oneAtATime.Dispose();
    }
}
