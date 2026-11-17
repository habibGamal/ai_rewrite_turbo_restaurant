using System.ComponentModel;
using System.Runtime.InteropServices;

namespace TurboLabel;

/// <summary>
/// Sends bytes to a Windows printer as they are (RAW), the way receipt
/// printers expect ESC/POS commands: the POS builds the whole receipt, this
/// only passes it through the printer's queue. Works with the "Generic /
/// Text Only" driver and with most receipt printer drivers.
/// </summary>
public static class RawPrinter
{
    public const string Escape = "\u001b";

    /// <summary>ESC @ (reset), ESC p 0 (drawer pin 2) 120 ms on / 240 ms off.</summary>
    public static readonly byte[] DrawerKick = { 0x1B, 0x40, 0x1B, 0x70, 0x30, 0x3C, 0x78 };

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private sealed class DocInfo
    {
        [MarshalAs(UnmanagedType.LPWStr)]
        public string DocName = "";

        [MarshalAs(UnmanagedType.LPWStr)]
        public string? OutputFile;

        [MarshalAs(UnmanagedType.LPWStr)]
        public string DataType = "RAW";
    }

    [DllImport("winspool.drv", EntryPoint = "OpenPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern bool OpenPrinter(string printerName, out IntPtr printer, IntPtr defaults);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool ClosePrinter(IntPtr printer);

    [DllImport("winspool.drv", EntryPoint = "StartDocPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern int StartDocPrinter(IntPtr printer, int level, [In] DocInfo docInfo);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool EndDocPrinter(IntPtr printer);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool StartPagePrinter(IntPtr printer);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool EndPagePrinter(IntPtr printer);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool WritePrinter(IntPtr printer, byte[] bytes, int count, out int written);

    private static readonly object OneAtATime = new();

    /// <exception cref="LabelPrintException">With a message to show the cashier.</exception>
    public static void Send(string printerName, byte[] bytes, string documentName)
    {
        lock (OneAtATime)
        {
            if (!OpenPrinter(printerName, out var printer, IntPtr.Zero))
            {
                throw new LabelPrintException($"الطابعة «{printerName}» مش موجودة على الجهاز ده ({LastError()}).");
            }

            try
            {
                if (StartDocPrinter(printer, 1, new DocInfo { DocName = documentName }) == 0)
                {
                    throw new LabelPrintException($"الطابعة «{printerName}» رفضت الطباعة المباشرة ({LastError()}). جرب درايفر الطابعة الأصلي أو «Generic / Text Only».");
                }

                try
                {
                    StartPagePrinter(printer);
                    var ok = WritePrinter(printer, bytes, bytes.Length, out var written);
                    EndPagePrinter(printer);

                    if (!ok || written != bytes.Length)
                    {
                        throw new LabelPrintException($"مقدرتش أبعت الفاتورة كاملة للطابعة «{printerName}» ({LastError()}).");
                    }
                }
                finally
                {
                    EndDocPrinter(printer);
                }
            }
            finally
            {
                ClosePrinter(printer);
            }
        }
    }

    /// <summary>A short test page in plain ESC/POS, then a cut.</summary>
    public static byte[] TestPage()
    {
        var text = Escape + "@" + Escape + "a1" // reset, centre
            + Escape + "!\u0000" + "Turbo Label\n"
            + "------------------------\n"
            + DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "\n"
            + "Receipt printer test OK\n"
            + "\n\n\n\n"
            + "\u001dV1"; // GS V 1: partial cut

        return System.Text.Encoding.ASCII.GetBytes(text);
    }

    private static string LastError()
    {
        return new Win32Exception(Marshal.GetLastWin32Error()).Message;
    }
}
