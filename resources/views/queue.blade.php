@extends('runtime-manager::layout')
@section('title','Queue')
@section('content')
<h1>Queue</h1>

<div class="grid">
  <div class="card stat"><span class="n">{{ $pending }}</span><span class="l">Pending</span></div>
  <div class="card stat"><span class="n">{{ $reserved }}</span><span class="l">In flight</span></div>
  <div class="card stat"><span class="n">{{ $failedTotal }}</span><span class="l">Failed</span></div>
</div>

<h2>Backlog by queue</h2>
<table>
  <thead><tr><th>Queue</th><th>Pending</th></tr></thead>
  <tbody>
    @forelse($pendingByQ as $q => $n)
      <tr><td class="mono">{{ $q }}</td><td>{{ $n }}</td></tr>
    @empty
      <tr><td colspan="2" class="muted">Queue empty.</td></tr>
    @endforelse
  </tbody>
</table>

<h2>Failed jobs</h2>
@if($failedTotal > 0)
  <div class="toolbar">
    <form class="inline" method="post" action="{{ route('runtime.queue.retry') }}">@csrf<button class="btn">Retry all</button></form>
    <form class="inline" method="post" action="{{ route('runtime.queue.flush') }}" onsubmit="return confirm('Permanently delete ALL failed jobs?')">@csrf<button class="btn danger">Flush all</button></form>
  </div>
  <table>
    <thead><tr><th>Failed</th><th>Queue</th><th>Exception</th><th></th></tr></thead>
    <tbody>
      @foreach($failed as $job)
        <tr>
          <td>{{ \Illuminate\Support\Carbon::parse($job->failed_at)->diffForHumans() }}</td>
          <td class="mono">{{ $job->connection }}/{{ $job->queue }}</td>
          <td class="muted mono">{{ $job->exception_summary }}</td>
          <td style="white-space:nowrap">
            <form class="inline" method="post" action="{{ route('runtime.queue.retry') }}">@csrf<input type="hidden" name="uuid" value="{{ $job->uuid }}"><button class="btn">Retry</button></form>
            <form class="inline" method="post" action="{{ route('runtime.queue.forget', $job->uuid) }}" onsubmit="return confirm('Delete this failed job?')">@csrf<button class="btn danger">Delete</button></form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
@else
  <p class="muted">No failed jobs.</p>
@endif

<h2>Processing history</h2>
<table>
  <thead><tr><th>Tick</th><th>Status</th><th>Processed</th><th>Failed</th><th>Duration</th></tr></thead>
  <tbody>
    @forelse($history as $h)
      <tr>
        <td>{{ $h['at']?->diffForHumans() }}</td>
        <td><span class="pill {{ $h['status'] }}">{{ $h['status'] }}</span></td>
        <td>{{ $h['processed'] ?? '—' }}</td>
        <td>{{ $h['failed'] ?? '—' }}</td>
        <td>{{ $h['duration'] }}ms</td>
      </tr>
    @empty
      <tr><td colspan="5" class="muted">No history yet.</td></tr>
    @endforelse
  </tbody>
</table>
@endsection
