@extends('runtime-manager::layout')
@section('title','HTTP')
@section('content')
<h1>Outgoing HTTP</h1>

@unless($enabled)
  <div class="banner warn">HTTP monitoring is disabled. Set <span class="mono">RUNTIME_HTTP_MONITOR=true</span> to record outbound calls made through Laravel's HTTP client. Only host, path, status and duration are stored — never query strings, headers or bodies.</div>
@endunless

<div class="grid">
  <div class="card stat"><span class="n">{{ $total }}</span><span class="l">Requests (1h)</span></div>
  <div class="card stat"><span class="n">{{ $failures }}</span><span class="l">Failures (1h)</span></div>
  <div class="card stat"><span class="n">{{ $avg }}<span class="muted" style="font-size:14px">ms</span></span><span class="l">Avg duration (1h)</span></div>
</div>

<h2>Recent calls</h2>
<table>
  <thead><tr><th>When</th><th>Method</th><th>Host</th><th>Path</th><th>Status</th><th>Duration</th></tr></thead>
  <tbody>
    @forelse($recent as $r)
      <tr>
        <td>{{ \Illuminate\Support\Carbon::parse($r->created_at)->diffForHumans() }}</td>
        <td class="mono">{{ $r->method }}</td>
        <td class="mono">{{ $r->host }}</td>
        <td class="mono muted">{{ $r->path }}</td>
        <td>
          @if($r->error)<span class="pill fail">error</span>
          @else<span class="pill {{ $r->ok ? 'ok':'warn' }}">{{ $r->status }}</span>@endif
        </td>
        <td>{{ $r->duration_ms !== null ? $r->duration_ms.'ms' : '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted">No outbound HTTP calls recorded.</td></tr>
    @endforelse
  </tbody>
</table>
@endsection
