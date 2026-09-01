<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CardPay — Setup</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        /* Self-contained installer chrome: strict-CSP safe, no external assets. */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f6fefd; color: #1d2625;
            display: flex; align-items: flex-start; justify-content: center;
            min-height: 100vh; padding: 40px 16px;
        }
        .card {
            position: relative; overflow: hidden;
            background: #fff; border-radius: 20px; max-width: 640px; width: 100%;
            padding: 32px; border: 1px solid #dce7e7;
            box-shadow: 0 8px 30px rgba(13,148,136,.10);
        }
        .card::before {
            content: ""; position: absolute; inset: 0 0 auto 0; height: 4px;
            background: linear-gradient(90deg, #0d9488, #06b6d4);
        }
        h1 { font-size: 1.35rem; color: #0d9488; margin-bottom: 4px; }
        .sub { color: #627b7a; font-size: .9rem; margin-bottom: 22px; }
        .steps { display: flex; gap: 6px; margin-bottom: 24px; }
        .step {
            flex: 1; height: 4px; border-radius: 2px; background: #dce7e7;
            font-size: 0; line-height: 0;
        }
        .step.active { background: #0d9488; }
        .step.done { background: #10b981; }
        h2 { font-size: 1.05rem; margin-bottom: 12px; }
        label { display: block; font-size: .85rem; font-weight: 600; margin: 12px 0 4px; }
        input[type=text], input[type=password], input[type=number] {
            width: 100%; padding: 9px 11px; font-size: .92rem;
            border: 1px solid #c2d4d3; border-radius: 8px;
        }
        input:focus { outline: none; border-color: #0d9488; box-shadow: 0 0 0 3px rgba(13,148,136,.18); }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        td { padding: 7px 6px; border-bottom: 1px solid #edf4f4; }
        td:last-child { text-align: right; font-weight: 600; }
        .ok { color: #059669; } .bad { color: #dc2626; }
        .error {
            background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;
            padding: 10px 14px; border-radius: 8px; font-size: .88rem; margin-bottom: 16px;
        }
        .success {
            background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857;
            padding: 10px 14px; border-radius: 8px; font-size: .88rem; margin-bottom: 16px;
        }
        .btn {
            display: inline-block; background: #0d9488; color: #fff; border: 0;
            padding: 10px 20px; border-radius: 8px; font-size: .95rem; font-weight: 700;
            cursor: pointer; text-decoration: none; margin-top: 18px;
        }
        .btn:hover { background: #0a7c70; }
        code, .mono { font-family: ui-monospace, monospace; font-size: .88rem; }
        .secret-box {
            background: #f7fafa; border: 1px dashed #9db4b3; border-radius: 8px;
            padding: 14px; margin: 14px 0; word-break: break-all;
        }
        .warn {
            background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
            padding: 10px 14px; border-radius: 8px; font-size: .88rem; margin-bottom: 16px;
        }
        ul.checks { list-style: none; }
        ul.checks li { padding: 6px 0; border-bottom: 1px solid #edf4f4; }
    </style>
</head>
<body>
<main class="card">
    <h1>CardPay Setup</h1>
    @yield('steps')
    @yield('content')
</main>
</body>
</html>
