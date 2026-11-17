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
    private readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(60) };
    private readonly SemaphoreSlim _oneAtATime = new(1, 1);

    public static IReadOnlyList<string> InstalledPrinters()
    {
        return PrinterSettings.InstalledPrinters.Cast<string>().ToList();
    }

    /// <returns>How many labels were sent to the printer.</returns>
    public async Task<int> PrintAsync(Uri manifestUrl, string printerName, CancellationToken cancel = default)
    {
        var job = await _http.GetFromJsonAsync<LabelJob>(manifestUrl, cancel)
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

                await using var stream = await _http.GetStreamAsync(imageUrl, cancel);
                using var memory = new MemoryStream();
                await stream.CopyToAsync(memory, cancel);
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

    public void Dispose()
    {
        _http.Dispose();
        _oneAtATime.Dispose();
    }
}
