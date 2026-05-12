<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login - Jojoapp</title>
    <style>
        :root {
            color-scheme: dark;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            align-items: center;
            background: #090b10;
            color: #f8fafc;
            display: flex;
            justify-content: center;
            margin: 0;
            min-height: 100vh;
            padding: 24px;
        }

        main {
            background: #17181c;
            border: 1px solid rgba(148, 163, 184, .16);
            border-radius: 16px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, .35);
            max-width: 420px;
            padding: 32px;
            width: 100%;
        }

        h1 {
            font-size: 28px;
            line-height: 1.1;
            margin: 0 0 8px;
        }

        p {
            color: #94a3b8;
            margin: 0 0 24px;
        }

        label {
            color: #cbd5e1;
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        input[type="email"],
        input[type="password"] {
            background: #0f172a;
            border: 1px solid rgba(148, 163, 184, .2);
            border-radius: 12px;
            color: #f8fafc;
            font: inherit;
            margin-bottom: 18px;
            outline: none;
            padding: 12px 14px;
            width: 100%;
        }

        input:focus {
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, .16);
        }

        .remember {
            align-items: center;
            display: flex;
            gap: 10px;
            margin-bottom: 22px;
        }

        .remember input {
            accent-color: #f59e0b;
            height: 16px;
            width: 16px;
        }

        .remember label {
            margin: 0;
        }

        button {
            background: #f59e0b;
            border: 0;
            border-radius: 12px;
            color: #111827;
            cursor: pointer;
            font: inherit;
            font-weight: 800;
            padding: 13px 16px;
            width: 100%;
        }

        button:hover {
            background: #fbbf24;
        }

        .error {
            background: rgba(239, 68, 68, .12);
            border: 1px solid rgba(248, 113, 113, .28);
            border-radius: 12px;
            color: #fecaca;
            margin-bottom: 18px;
            padding: 12px 14px;
        }
    </style>
</head>
<body>
    <main>
        <h1>Jojoapp Backend</h1>
        <p>Masuk ke panel CMS dan operasional.</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="/admin/login">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <div class="remember">
                <input id="remember" name="remember" type="checkbox" value="1">
                <label for="remember">Ingat perangkat ini</label>
            </div>

            <button type="submit">Masuk Backend</button>
        </form>
    </main>
</body>
</html>
