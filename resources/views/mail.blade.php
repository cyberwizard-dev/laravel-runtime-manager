@extends('runtime-manager::layout')
@section('title','Mail')
@section('content')
<h1>Outgoing mail</h1>

@unless($enabled)
  <div class="banner warn">Mail monitoring is disabled. Set <span class="mono">RUNTIME_MAIL_MONITOR=true</span> to record outgoing mail. Recipient domains, counts and subjects are stored — never full addresses or message bodies.</div>
@endunless

<div class="banner warn" style="background:var(--chip);border-color:var(--line);color:var(--muted)">
  A recorded message means the mail transport <strong>accepted the handoff</strong>. It is not a guarantee of inbox delivery — bounces, spam filtering and downstream rejection happen after this point.
</div>

<div class="grid">
  <div class="card stat"><span class="n">{{ $sent }}</span><span class="l">Handed off (24h)</span></div>
</div>

<h2>Recent messages</h2>
<table>
  <thead><tr><th>When</th><th>Mailer</th><th>To (domains)</th><th>Recipients</th><th>Subject</th></tr></thead>
  <tbody>
    @forelse($recent as $m)
      <tr>
        <td>{{ \Illuminate\Support\Carbon::parse($m->created_at)->diffForHumans() }}</td>
        <td class="mono">{{ $m->mailer }}</td>
        <td class="mono muted">{{ $m->to_domains }}</td>
        <td>{{ $m->recipients }}</td>
        <td class="muted">{{ $m->subject }}</td>
      </tr>
    @empty
      <tr><td colspan="5" class="muted">No outgoing mail recorded.</td></tr>
    @endforelse
  </tbody>
</table>
@endsection
