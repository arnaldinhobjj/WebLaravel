<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $emailSubject }}</title></head>
<body style="margin:0;padding:24px;background:#f3f5f8;font:15px/1.55 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#1b2230;">
    <div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #d9dee6;border-radius:12px;padding:24px;">
        <p style="margin:0 0 16px;font-weight:600;color:#2456d6;">Fort Vale</p>
        <div>{!! nl2br(e($text)) !!}</div>
        @if ($link)
            <p style="margin:24px 0 0;"><a href="{{ $link }}" style="display:inline-block;padding:9px 16px;background:#2456d6;color:#ffffff;border-radius:8px;text-decoration:none;">Open in Fort Vale</a></p>
        @endif
    </div>
</body>
</html>
