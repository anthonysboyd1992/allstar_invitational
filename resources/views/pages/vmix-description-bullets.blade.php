@if ($lines !== [])
    <ul @class(['list-disc space-y-0.5 pl-4', $class ?? ''])>
        @foreach ($lines as $line)
            <li class="break-words">{{ $line }}</li>
        @endforeach
    </ul>
@endif
