<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', 'Runtime') · Runtime Manager</title>
<style>
  :root{
    --bg:#f6f7f9; --panel:#ffffff; --ink:#1b1f24; --muted:#687078; --line:#e4e7eb;
    --accent:#2f6df6; --ok:#1a8a52; --okbg:#e6f5ec; --warn:#9a6700; --warnbg:#fff4d6;
    --fail:#c23030; --failbg:#fce8e8; --chip:#eef1f4;
  }
  @media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
    --bg:#15171b; --panel:#1d2026; --ink:#e8eaed; --muted:#9aa2ab; --line:#2b2f36;
    --accent:#5b8dff; --ok:#4cc38a; --okbg:#122a1e; --warn:#e3b341; --warnbg:#2a2310;
    --fail:#f1707a; --failbg:#2c1618; --chip:#262a31;
  }}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  a{color:var(--accent);text-decoration:none}
  a:hover{text-decoration:underline}
  header.top{display:flex;align-items:center;gap:16px;padding:12px 20px;background:var(--panel);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5;flex-wrap:wrap}
  header.top .brand{font-weight:700;letter-spacing:.2px}
  header.top nav{display:flex;gap:4px;flex-wrap:wrap}
  header.top nav a{padding:6px 12px;border-radius:8px;color:var(--muted)}
  header.top nav a.active{background:var(--chip);color:var(--ink)}
  header.top .spacer{flex:1}
  .wrap{max-width:1080px;margin:0 auto;padding:20px 16px 48px}
  h1{font-size:20px;margin:4px 0 16px}
  h2{font-size:15px;margin:24px 0 10px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px}
  .grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
  .card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px}
  .stat{display:flex;flex-direction:column;gap:4px}
  .stat .n{font-size:26px;font-weight:700}
  .stat .l{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.5px}
  table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line);border-radius:12px;overflow:hidden}
  th,td{text-align:left;padding:9px 12px;border-bottom:1px solid var(--line);vertical-align:top}
  th{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
  tr:last-child td{border-bottom:none}
  .pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600}
  .pill.ok,.pill.success{background:var(--okbg);color:var(--ok)}
  .pill.warn,.pill.partial{background:var(--warnbg);color:var(--warn)}
  .pill.fail,.pill.failed{background:var(--failbg);color:var(--fail)}
  .pill.skipped,.pill.muted{background:var(--chip);color:var(--muted)}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}
  .btn{display:inline-block;border:1px solid var(--line);background:var(--panel);color:var(--ink);padding:7px 13px;border-radius:8px;cursor:pointer;font:inherit}
  .btn:hover{border-color:var(--accent)}
  .btn.danger{color:var(--fail);border-color:var(--failbg)}
  .btn.primary{background:var(--accent);color:#fff;border-color:var(--accent)}
  .muted{color:var(--muted)}
  .banner{padding:12px 16px;border-radius:10px;margin-bottom:16px;border:1px solid}
  .banner.ok{background:var(--okbg);border-color:var(--ok);color:var(--ok)}
  .banner.warn{background:var(--warnbg);border-color:var(--warn);color:var(--warn)}
  .banner.fail{background:var(--failbg);border-color:var(--fail);color:var(--fail)}
  code.cron{display:block;background:var(--chip);padding:12px;border-radius:8px;overflow:auto;white-space:pre}
  form.inline{display:inline}
  input[type=password],input[type=text],input[type=date],select{padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--panel);color:var(--ink);font:inherit}
  .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
  .kv{display:grid;grid-template-columns:auto 1fr;gap:4px 14px}
  .kv div:nth-child(odd){color:var(--muted)}
</style>
</head>
<body>
@hasSection('chrome')
  @yield('content')
@else
  @php $cur = request()->route()?->getName(); @endphp
  <header class="top">
    <span class="brand">⚙ Runtime Manager</span>
    <nav>
      <a href="{{ route('runtime.dashboard') }}" class="{{ $cur==='runtime.dashboard'?'active':'' }}">Overview</a>
      <a href="{{ route('runtime.queue') }}" class="{{ $cur==='runtime.queue'?'active':'' }}">Queue</a>
      <a href="{{ route('runtime.scheduler') }}" class="{{ $cur==='runtime.scheduler'?'active':'' }}">Scheduler</a>
      @if(config('runtime-manager.http_monitor.enabled'))
        <a href="{{ route('runtime.http') }}" class="{{ $cur==='runtime.http'?'active':'' }}">HTTP</a>
      @endif
      @if(config('runtime-manager.mail_monitor.enabled'))
        <a href="{{ route('runtime.mail') }}" class="{{ $cur==='runtime.mail'?'active':'' }}">Mail</a>
      @endif
      <a href="{{ route('runtime.logs') }}" class="{{ $cur==='runtime.logs'?'active':'' }}">Logs</a>
      <a href="{{ route('runtime.setup') }}" class="{{ $cur==='runtime.setup'?'active':'' }}">Setup</a>
    </nav>
    <span class="spacer"></span>
    <form class="inline" method="post" action="{{ route('runtime.logout') }}">@csrf<button class="btn">Log out</button></form>
  </header>
  <main class="wrap">
    @if(session('status'))<div class="banner ok">{{ session('status') }}</div>@endif
    @yield('content')
  </main>
@endif
</body>
</html>
