@extends('runtime-manager::layout')
@section('title','Setup')
@section('content')
<h1>Setup &amp; verification</h1>

@if($ready)
  <div class="banner ok"><strong>Environment ready.</strong> Make sure the cron job below is configured, then run verification.</div>
@else
  <div class="banner warn"><strong>Environment not fully ready.</strong> Resolve the failing items below.</div>
@endif

<h2>1 · Cron command</h2>
<p class="muted">Add this single entry in cPanel → Cron Jobs, set to run <strong>every minute</strong>:</p>
<code class="cron">{{ $env['runner']['cron'] }}</code>
<div class="card kv" style="margin-top:12px">
  <div>Runner published</div><div><span class="pill {{ $env['runner']['published'] ? 'ok':'fail' }}">{{ $env['runner']['published'] ? 'yes':'no — run runtime:install' }}</span></div>
  <div>Runner path</div><div class="mono">{{ $env['runner']['path'] }}</div>
  <div>PHP CLI binary</div><div class="mono">{{ $env['php']['cli_binary'] }}</div>
</div>

<h2>2 · Environment</h2>
<div class="grid">
  <div class="card">
    <div class="l muted" style="text-transform:uppercase;font-size:12px;letter-spacing:.5px;margin-bottom:8px">PHP</div>
    <div class="kv">
      <div>Web version</div><div class="mono">{{ $env['php']['web_version'] }} ({{ $env['php']['web_sapi'] }})</div>
      <div>Database</div><div>@if($env['database']['connected'])<span class="pill ok">{{ $env['database']['driver'] }}</span>@else<span class="pill fail">down</span>@endif</div>
      <div>Queue driver</div><div><span class="pill {{ $env['queue']['driver']==='database'?'ok':'warn' }}">{{ $env['queue']['driver'] }}</span> @unless($env['queue']['jobs_table_exists'])<span class="pill fail">no jobs table</span>@endunless</div>
      <div>Atomic cache lock</div><div><span class="pill {{ $env['cache_locks']?'ok':'warn' }}">{{ $env['cache_locks']?'yes':'flock fallback' }}</span></div>
    </div>
    <p class="muted" style="font-size:12px;margin:10px 0 0">{{ $env['php']['note'] }}</p>
  </div>
  <div class="card">
    <div class="l muted" style="text-transform:uppercase;font-size:12px;letter-spacing:.5px;margin-bottom:8px">Extensions</div>
    @foreach($env['extensions'] as $ext => $ok)
      <span class="pill {{ $ok?'ok':'fail' }}" style="margin:2px">{{ $ext }}</span>
    @endforeach
    @if(!empty($env['disabled']))
      <div class="l muted" style="text-transform:uppercase;font-size:12px;letter-spacing:.5px;margin:12px 0 6px">Disabled functions</div>
      @foreach($env['disabled'] as $fn)<span class="pill warn" style="margin:2px">{{ $fn }}</span>@endforeach
      <p class="muted" style="font-size:12px;margin:8px 0 0">The runner boots in-process and does not require these.</p>
    @endif
  </div>
  <div class="card">
    <div class="l muted" style="text-transform:uppercase;font-size:12px;letter-spacing:.5px;margin-bottom:8px">Writable paths</div>
    @foreach($env['writable'] as $path => $ok)
      <div style="display:flex;justify-content:space-between;gap:8px"><span class="mono">{{ $path }}</span><span class="pill {{ $ok?'ok':'fail' }}">{{ $ok?'ok':'no' }}</span></div>
    @endforeach
  </div>
</div>

<h2>3 · Verify</h2>
<p class="muted">Runs live checks, including dispatching and processing a real test job.</p>
<button class="btn primary" id="verifyBtn" type="button">Run verification</button>
<div id="verifyOut" style="margin-top:14px"></div>

<script>
document.getElementById('verifyBtn').addEventListener('click', async function(){
  var btn = this, out = document.getElementById('verifyOut');
  btn.disabled = true; btn.textContent = 'Running…'; out.innerHTML = '';
  try {
    var res = await fetch(@json(route('runtime.setup.verify')), {
      method:'POST',
      headers:{'X-CSRF-TOKEN':@json(csrf_token()),'Accept':'application/json'}
    });
    var data = await res.json();
    var rows = (data.checks||[]).map(function(c){
      return '<tr><td class="mono">'+c.key+'</td><td><span class="pill '+c.status+'">'+c.status+'</span></td><td class="muted">'+
        (c.message||'').replace(/</g,'&lt;')+'</td></tr>';
    }).join('');
    out.innerHTML = '<table><thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead><tbody>'+rows+'</tbody></table>';
  } catch(e){
    out.innerHTML = '<div class="banner fail">Verification request failed: '+e+'</div>';
  } finally {
    btn.disabled = false; btn.textContent = 'Run verification';
  }
});
</script>
@endsection
