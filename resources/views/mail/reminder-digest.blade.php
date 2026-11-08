{{ $heading }}

@foreach ($lines as $line)
- {{ $line }}
@endforeach

Open {{ config('app.url') }} to act on these.
