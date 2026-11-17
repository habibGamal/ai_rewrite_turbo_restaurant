namespace TurboLabel;

public sealed record ActivityEntry(DateTime Time, string Kind, string Detail, bool Ok);

/// <summary>The last few hundred things the app printed or failed to print, for the window.</summary>
public sealed class ActivityLog
{
    private const int Keep = 300;
    private readonly LinkedList<ActivityEntry> _entries = new();
    private readonly object _gate = new();

    public event Action? Changed;

    public void Add(string kind, string detail, bool ok)
    {
        lock (_gate)
        {
            _entries.AddFirst(new ActivityEntry(DateTime.Now, kind, detail, ok));
            while (_entries.Count > Keep)
            {
                _entries.RemoveLast();
            }
        }

        Changed?.Invoke();
    }

    public List<ActivityEntry> Snapshot()
    {
        lock (_gate)
        {
            return _entries.ToList();
        }
    }
}
