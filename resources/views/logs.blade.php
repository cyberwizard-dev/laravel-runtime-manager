@extends('runtime-manager::layout')
@section('title','Logs')
@section('content')
<h1>Run logs</h1>

<form method="get" class="toolbar">
  <select name="status">
    <option value="">All statuses</option>
    @foreach($statuses as $s)
      <option value="{{ $s }}" @selected($status===$s)>{{ $s }}</option>
    @endforeach
  </select>
  <select name="trigger">
    <option value="">All triggers</option>
    <option value="cli" @selected($trigger==='cli')>cli</option>
    <option value="http" @selected($trigger==='http')>http</option>
  </select>
  <input type="date" name="date" value="{{ $date }}">
  <button class="btn">Filter</button>
  <a class="btn" href="{{ route('runtime.logs') }}">Reset</a>
</form>

<table>
  <thead><tr><th>Started</th><th>Status</th><th>Trigger</th><th>Duration</th><th>Services / error</th></tr></thead>
  <tbody>
    @forelse($runs as $run)
      <tr>
        <td>{{ $run->started_at }}</td>
        <td><span class="pill {{ $run->status }}">{{ $run->status }}</span></td>
        <td class="muted">{{ $run->trigger }}</td>
        <td>{{ $run->duration_ms }}ms</td>
        <td class="mono muted">
          @if($run->error)
            <span class="pill fail">error</span> {{ \Illuminate\Support\Str::limit($run->error, 160) }}
          @else
            {{ collect($run->services ?? [])->map(fn($v,$k)=>$k.':'.($v['status']??'?'))->join('  ') ?: '—' }}
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="5" class="muted">No runs match.</td></tr>
    @endforelse
  </tbody>
</table>

<div style="margin-top:16px">{{ $runs->links() }}</div>
@endsection
