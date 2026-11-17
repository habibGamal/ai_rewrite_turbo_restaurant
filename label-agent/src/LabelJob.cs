using System.Text.Json.Serialization;

namespace TurboLabel;

/// <summary>The POS print job manifest (labels.jobs.manifest).</summary>
public sealed class LabelJob
{
    [JsonPropertyName("job")]
    public string Job { get; set; } = "";

    [JsonPropertyName("template")]
    public string Template { get; set; } = "";

    [JsonPropertyName("page")]
    public LabelPage Page { get; set; } = new();

    [JsonPropertyName("total_labels")]
    public int TotalLabels { get; set; }

    [JsonPropertyName("total_pages")]
    public int TotalPages { get; set; }

    [JsonPropertyName("pages")]
    public List<LabelPageImage> Pages { get; set; } = new();
}

public sealed class LabelPage
{
    [JsonPropertyName("width_mm")]
    public double WidthMm { get; set; }

    [JsonPropertyName("height_mm")]
    public double HeightMm { get; set; }

    [JsonPropertyName("dpi")]
    public int Dpi { get; set; }
}

public sealed class LabelPageImage
{
    [JsonPropertyName("image")]
    public string Image { get; set; } = "";

    [JsonPropertyName("copies")]
    public int Copies { get; set; }
}

public sealed class PrintRequest
{
    [JsonPropertyName("manifest")]
    public string? Manifest { get; set; }

    [JsonPropertyName("printer")]
    public string? Printer { get; set; }
}

public sealed class LabelPrintException(string message) : Exception(message);
