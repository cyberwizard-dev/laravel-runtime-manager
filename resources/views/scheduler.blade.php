@extends('runtime-manager::layout')
@section('title','Scheduler')
@section('content')
<h1>Scheduler</h1>

@unless($onOneServer)
  <div class="banner warn"><strong>onOneServer unavailable.</strong> The current cache store has no atomic lock, so <span class="mono">onOneServer()</span> is a no-op. Use the <span class="mono">database</span> cache store to enable it.</div>
@endunless
@unless($runInBackground)
  <div class="banner warn"><strong>runInBackground unavailable.</strong> Process functions are disabled on this host, so <span class="mono">runInBackground()</span> tasks run inline instead.</div>
@endunless

@if($lastSchedulerData)
  <div class="card kv" style="margin-bottom:16px">
    <div>Last run</div><div>{{ $lastRun->started_at?->diffForHumans() }} — <span class="pill {{ $lastRun->status }}">{{ $lastRun->status }}</span></div>
    <div>Tasks fired</div><div>{{ ($lastSchedulerData['summary']['ran'] ?? false) ? 'yes' : 'none due' }}</div>
    @if(!empty($lastSchedulerData['summary']['tail']))
      <div>Output tail</div><div class="mono muted">{{ implode(' · ', $lastSchedulerData['summary']['tail']) }}</div>
    @endif
  </div>
@endif

<h2>Registered tasks</h2>
<table>
  <thead><tr><th>Task</th><th>Expression</th><th>Next run</th><th>Timezone</th></tr></thead>
  <tbody>
    @forelse($tasks as $t)
      <tr>
        <td class="mono">{{ $t['label'] }}</td>
        <td class="mono muted">{{ $t['expression'] }}</td>
        <td>{{ $t['next_run'] ?? '—' }}</td>
        <td class="muted">{{ $t['timezone'] ?? config('app.timezone') }}</td>
      </tr>
    @empty
      <tr><td colspan="4" class="muted">No scheduled tasks registered in this application.</td></tr>
    @endforelse
  </tbody>
</table>
@endsection
