<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>ReminderBot — статистика</title>
    <style>
        :root{color-scheme:dark;--bg:#0b1020;--card:#151c31;--line:#26304b;--text:#edf2ff;--muted:#91a0bf;--accent:#7c9cff}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,sans-serif}.wrap{max-width:1200px;margin:auto;padding:28px}.top{display:flex;justify-content:space-between;align-items:end;gap:20px}h1{margin:0;font-size:28px}.muted{color:var(--muted)}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:24px 0}.card,.panel{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px}.value{font-size:31px;font-weight:750;margin-top:5px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.wide{grid-column:1/-1}h2{font-size:17px;margin:0 0 14px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line)}th{color:var(--muted);font-weight:600}tr:last-child td{border:0}.scroll{overflow:auto;max-height:540px}.badge{display:inline-block;padding:2px 8px;border-radius:99px;background:#253052}.command{color:#a9beff}.callback{color:#8be0bd}@media(max-width:760px){.cards,.grid{grid-template-columns:1fr 1fr}.wrap{padding:16px}.wide{grid-column:1/-1}}@media(max-width:480px){.cards,.grid{grid-template-columns:1fr}.wide{grid-column:auto}}
    </style>
</head>
<body>
<main class="wrap">
    <div class="top"><div><h1>ReminderBot</h1><div class="muted">Статистика обновляется каждые 15 секунд</div></div><div class="muted">{{ $generatedAt->format('d.m.Y H:i:s') }}</div></div>
    <section class="cards">
        <div class="card"><div class="muted">Всего пользователей</div><div class="value">{{ number_format($totals['users'], 0, ',', ' ') }}</div></div>
        <div class="card"><div class="muted">Новых сегодня</div><div class="value">{{ number_format($totals['newToday'], 0, ',', ' ') }}</div></div>
        <div class="card"><div class="muted">Активных сегодня</div><div class="value">{{ number_format($totals['activeToday'], 0, ',', ' ') }}</div></div>
        <div class="card"><div class="muted">Создано напоминаний</div><div class="value">{{ number_format($totals['reminders'], 0, ',', ' ') }}</div></div>
    </section>
    <section class="grid">
        <div class="panel wide"><h2>Последние 7 дней</h2><div class="scroll"><table><thead><tr><th>Дата</th><th>Новые</th><th>Активные</th><th>Действия</th></tr></thead><tbody>@foreach($daily as $day)<tr><td>{{ $day['label'] }}</td><td>{{ $day['users'] }}</td><td>{{ $day['active'] }}</td><td>{{ $day['events'] }}</td></tr>@endforeach</tbody></table></div></div>
        <div class="panel"><h2>Команды за 7 дней</h2><table><thead><tr><th>Команда</th><th>Людей</th><th>Раз</th></tr></thead><tbody>@forelse($commands as $row)<tr><td class="command">{{ $row->event_name }}</td><td>{{ $row->users }}</td><td>{{ $row->total }}</td></tr>@empty<tr><td colspan="3" class="muted">Пока нет данных</td></tr>@endforelse</tbody></table></div>
        <div class="panel"><h2>Кнопки за 7 дней</h2><table><thead><tr><th>Кнопка</th><th>Людей</th><th>Раз</th></tr></thead><tbody>@forelse($callbacks as $row)<tr><td class="callback">{{ $row->event_name }}</td><td>{{ $row->users }}</td><td>{{ $row->total }}</td></tr>@empty<tr><td colspan="3" class="muted">Пока нет данных</td></tr>@endforelse</tbody></table></div>
        <div class="panel"><h2>Источники /start</h2><table><thead><tr><th>Метка</th><th>Пользователей</th></tr></thead><tbody>@forelse($sources as $row)<tr><td>{{ $row->source }}</td><td>{{ $row->users }}</td></tr>@empty<tr><td colspan="2" class="muted">Пока нет данных</td></tr>@endforelse</tbody></table></div>
        <div class="panel wide"><h2>Последние действия</h2><div class="scroll"><table><thead><tr><th>Время</th><th>Пользователь</th><th>Тип</th><th>Действие</th><th>Источник</th></tr></thead><tbody>@forelse($recent as $event)<tr><td>{{ $event->created_at->format('d.m H:i:s') }}</td><td>{{ $event->user->username ? '@'.$event->user->username : trim($event->user->first_name.' '.$event->user->last_name) }} <span class="muted">#{{ $event->user->telegram_id }}</span></td><td><span class="badge">{{ $event->event_type }}</span></td><td>{{ $event->event_name }}</td><td>{{ $event->source ?? '—' }}</td></tr>@empty<tr><td colspan="5" class="muted">Сбор начнётся после развёртывания миграции</td></tr>@endforelse</tbody></table></div></div>
    </section>
</main>
<script>setTimeout(()=>location.reload(),15000)</script>
</body>
</html>
