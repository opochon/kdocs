namespace Kdocs.AgentPoste;

/// <summary>Source du temps, injectable : les files d'attente et les copies de secours se testent sans horloge réelle.</summary>
public interface IHorloge
{
    DateTimeOffset MaintenantUtc { get; }
}

public sealed class HorlogeSysteme : IHorloge
{
    public static readonly HorlogeSysteme Instance = new();

    public DateTimeOffset MaintenantUtc => DateTimeOffset.UtcNow;
}

/// <summary>Horloge figée des tests, que l'on avance à la main.</summary>
public sealed class HorlogeFigee : IHorloge
{
    public HorlogeFigee(DateTimeOffset depart) => MaintenantUtc = depart;

    public DateTimeOffset MaintenantUtc { get; private set; }

    public HorlogeFigee Avancer(TimeSpan duree)
    {
        MaintenantUtc += duree;

        return this;
    }

    public HorlogeFigee Fixer(DateTimeOffset instant)
    {
        MaintenantUtc = instant;

        return this;
    }
}
