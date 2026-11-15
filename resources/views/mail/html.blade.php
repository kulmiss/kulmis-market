<!doctype html>
<html lang="en"><body style="font-family:system-ui,sans-serif;background:#f2f8f4;padding:24px;color:#11201a">
<div style="max-width:560px;margin:auto">
<p style="margin:0 0 12px;font-family:Georgia,'Iowan Old Style',serif;font-size:20px;font-weight:bold;color:#135d33"><img src="{{ asset('brand/logo-96.png') }}" width="36" height="36" alt="" style="vertical-align:middle;margin-right:8px;border:0">{{ \App\Support\Settings::string('site.name') }}</p>
</div>
<div style="max-width:560px;margin:auto;background:#fff;border-radius:12px;padding:24px">
<p style="white-space:pre-line;line-height:1.6;margin:0 0 16px">{{ $body }}</p>
<p style="color:#5b6b61;margin:0">— {{ \App\Support\Settings::string('site.name') }}</p>
</div></body></html>
