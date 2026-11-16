<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $site }} — Super Admin guide</title>
<style>
    @page { margin: 22mm 18mm 20mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; line-height: 1.5; color: #13241a; }
    .cover { text-align: center; padding-top: 70mm; }
    .cover h1 { font-size: 30px; margin: 0; }
    .cover p { color: #5b6b61; font-size: 13px; }
    h1 { font-size: 20px; margin: 0 0 4px; color: #135d33; }
    .part { color: #5b6b61; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; margin: 0; }
    h2 { font-size: 14px; margin: 16px 0 6px; }
    h3 { font-size: 12px; margin: 14px 0 4px; }
    p, ul, ol { margin: 0 0 8px; }
    li { margin: 0 0 2px; }
    a { color: #135d33; text-decoration: none; }
    code { font-family: DejaVu Sans Mono, monospace; font-size: 9.5px; background: #f1f5f2; padding: 0 2px; }
    pre { background: #f1f5f2; padding: 8px; font-size: 9px; white-space: pre-wrap; }
    table { width: 100%; border-collapse: collapse; margin: 6px 0 10px; }
    th, td { border-bottom: 1px solid #dde5df; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #f4f8f5; }
    blockquote { margin: 8px 0; padding: 6px 10px; background: #f4f8f5; border-left: 3px solid #1f8a4c; }
    img { max-width: 100%; max-height: 105mm; border: 1px solid #dde5df; margin: 6px 0; }
    .page { page-break-before: always; }
    .toc li { margin-bottom: 3px; }
    li.task { list-style: none; margin-left: -14px; }
</style>
</head>
<body>
<div class="cover">
    <h1>{{ $site }}</h1>
    <p>Super Admin guide: set up and run your shop</p>
    <p>Made {{ now()->timezone(config('app.timezone'))->format('d M Y') }} · numbers from your settings on that day</p>
</div>
<div class="page">
    <h1>Contents</h1>
    @foreach (collect($pages)->groupBy('part') as $part => $items)
        <h3>{{ $part }}</h3>
        <ol class="toc">@foreach ($items as $p)<li><a href="#{{ $p['slug'] }}">{{ $p['title'] }}</a></li>@endforeach</ol>
    @endforeach
</div>
@foreach ($pages as $p)
<div class="page">
    <p class="part">{{ $p['part'] }}</p>
    <h1 id="{{ $p['slug'] }}">{{ $p['title'] }}</h1>
    {!! \App\Help\AdminGuide::html($p['body'], 'pdf') !!}
</div>
@endforeach
</body>
</html>
