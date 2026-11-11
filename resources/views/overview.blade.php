@extends('runtime-manager::layout')
@section('title','Overview')
@section('content')
<h1>Overview</h1>

@if($stale)
  <div class="banner fail">
    <strong>Cron looks down.</strong>
    @if($state && $state->last_tick_at)
      Last tick was {{ $state->last_tick_at->diffForHumans() }} (threshold {{ $staleAfter }}s). Check the cron job.
    @else
      No tick has ever been recorded. Configure the cron job on the <a href="{{ route('runtime.setup') }}">Setup</a> page.
    @endif
  </div>
@else
  <div class="banner ok"><strong>Runtime healthy.</strong> Last tick {{ $state->last_tick_at->diffForHumans() }}.</div>
@endif

@if($mailError)
  <div class="banner warn">Last watchdog alert email failed at the transport: <span class="mono">{{ $mailError }}</span>. SMTP handoff did not succeed — check your mail config.</div>
@endif

<div class="grid">
  <div class="card stat"><span class="n">{{ $pending }}</span><span class="l">Pending jobs</span></div>
  <div class="card stat"><span class="n">{{ $reserved }}</span><span class="l">In flight</span></div>
  <div class="card stat"><span class="n">{{ $failed }}</span><span class="l">Failed jobs</span></div>
  <div class="card stat"><span class="n">{{ $schedCount }}</span><span class="l">Scheduled tasks</span></div>
  <div class="card stat"><span class="n">{{ $state?->total_ticks ?? 0 }}</span><span class="l">Total ticks</span></div>
  <div class="card stat"><span class="n">{{ $avgDuration }}<span class="muted" style="font-size:14px">ms</span></span><span class="l">Avg tick (1h)</span></div>
</div>

<h2>Health</h2>
<table>
  <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
  <tbody>
    @foreach($health as $c)
      <tr>
        <td class="mono">{{ $c['key'] }}</td>
        <td><span class="pill {{ $c['status'] }}">{{ $c['status'] }}</span></td>
        <td class="muted">{{ $c['message'] }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

@if($deployment)
  <h2>Deployment</h2>
  <div class="card kv">
    <div>Last post-deploy</div><div>{{ \Illuminate\Support\Carbon::parse($deployment['at'])->diffForHumans() }}</div>
    <div>Operational</div><div><span class="pill {{ ($deployment['operational'] ?? false) ? 'ok':'fail' }}">{{ ($deployment['operational'] ?? false) ? 'yes':'no' }}</span></div>
  </div>
@endif

<h2>Recent ticks</h2>
<table>
  <thead><tr><th>Started</th><th>Status</th><th>Duration</th><th>Trigger</th><th>Services</th></tr></thead>
  <tbody>
    @forelse($recentRuns as $run)
      <tr>
        <td>{{ $run->started_at?->diffForHumans() }}</td>
        <td><span class="pill {{ $run->status }}">{{ $run->status }}</span></td>
        <td>{{ $run->duration_ms }}ms</td>
        <td class="muted">{{ $run->trigger }}</td>
        <td class="mono muted">{{ collect($run->services ?? [])->keys()->join(', ') ?: '—' }}</td>
      </tr>
    @empty
      <tr><td colspan="5" class="muted">No ticks recorded yet.</td></tr>
    @endforelse
  </tbody>
</table>
@endsection
