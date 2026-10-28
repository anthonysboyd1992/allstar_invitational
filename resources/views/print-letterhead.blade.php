<header class="letterhead">
    <img class="letterhead-left" src="{{ $leftLogoUrl }}" alt="Left letterhead">
    <div class="letterhead-center">
        @if (filled($printKind ?? null))
            <p class="letterhead-kind">{{ $printKind }}</p>
        @endif
        @if (filled($printHeading))
            <p class="letterhead-heading">{{ $printHeading }}</p>
        @endif
        @if (filled($printDate))
            <p class="letterhead-date">{{ $printDate }}</p>
        @endif
        @if (filled($printVenueName))
            <p class="letterhead-venue-name">{{ $printVenueName }}</p>
        @endif
        @if (filled($printVenue))
            <p class="letterhead-venue">{{ $printVenue }}</p>
        @endif
    </div>
    <img class="letterhead-right" src="{{ $rightLogoUrl }}" alt="Right letterhead">
</header>
