<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>ReminderBot — статистика</title>
    <style>
        :root{color-scheme:dark;--bg:#0b1020;--card:#151c31;--line:#26304b;--text:#edf2ff;--muted:#91a0bf;--accent:#7c9cff;--green:#5ed6a5;--pink:#e87db3}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 15% 0,#162044 0,transparent 32%),var(--bg);color:var(--text);font:14px/1.45 system-ui,sans-serif}.wrap{max-width:1200px;margin:auto;padding:28px}.top{display:flex;justify-content:space-between;align-items:end;gap:20px}h1{margin:0;font-size:28px}.muted{color:var(--muted)}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:24px 0}.card,.panel{background:linear-gradient(145deg,#171f37,#12192b);border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 12px 32px #05081455}.value{font-size:31px;font-weight:750;margin-top:5px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.wide{grid-column:1/-1}h2{font-size:17px;margin:0 0 14px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line)}th{color:var(--muted);font-weight:600}tr:last-child td{border:0}.scroll{overflow:auto;max-height:540px}.badge{display:inline-block;padding:2px 8px;border-radius:99px;background:#253052}.command{color:#a9beff}.callback{color:#8be0bd}.legend{display:flex;gap:18px;flex-wrap:wrap;margin:-4px 0 18px}.legend span{display:flex;align-items:center;gap:7px}.dot{width:9px;height:9px;border-radius:50%;background:var(--accent)}.dot.green{background:var(--green)}.dot.pink{background:var(--pink)}.chart{height:230px;display:flex;align-items:stretch;gap:12px;padding-top:12px}.day{min-width:0;flex:1;display:flex;flex-direction:column;align-items:center}.columns{height:185px;width:100%;display:flex;align-items:end;justify-content:center;gap:4px;border-bottom:1px solid var(--line);background:repeating-linear-gradient(to top,transparent 0,transparent 45px,#26304b55 46px)}.bar{width:min(24px,38%);min-height:3px;border-radius:7px 7px 2px 2px;background:linear-gradient(to top,#5678ee,var(--accent));position:relative}.bar.green{background:linear-gradient(to top,#299d76,var(--green))}.bar.pink{background:linear-gradient(to top,#b64d83,var(--pink))}.bar:hover::after{content:attr(data-value);position:absolute;left:50%;top:-29px;transform:translateX(-50%);padding:3px 7px;border-radius:6px;background:#050814;color:#fff;font-size:12px;z-index:2}.day-label{margin-top:8px;color:var(--muted);font-size:12px}.hbars{display:grid;gap:13px}.hrow{display:grid;grid-template-columns:minmax(90px,150px) 1fr 40px;align-items:center;gap:10px}.track{height:11px;border-radius:99px;background:#252e49;overflow:hidden}.fill{height:100%;min-width:3px;border-radius:99px;background:linear-gradient(90deg,var(--accent),#b47cff)}.fill.green{background:linear-gradient(90deg,#37b98c,var(--green))}.hvalue{text-align:right;font-weight:700}@media(max-width:760px){.cards,.grid{grid-template-columns:1fr 1fr}.wrap{padding:16px}.wide{grid-column:1/-1}.chart{gap:5px}.hrow{grid-template-columns:100px 1fr 34px}}@media(max-width:480px){.cards,.grid{grid-template-columns:1fr}.wide{grid-column:auto}}
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
        <div class="card"><div class="muted">Создали напоминание</div><div class="value">{{ $totals['activationRate'] }}%</div></div>
        <div class="card"><div class="muted">Вернулись спустя сутки</div><div class="value">{{ $totals['returnRate'] }}%</div></div>
    </section>
    <section class="grid">
        @php
            $dailyMax = max(1, $daily->max(fn ($day) => max($day['users'], $day['active'])));
            $typeMax = max(1, (int) $activityTypes->max('total'));
            $sourceMax = max(1, (int) $sources->max('users'));
            $funnelMax = max(1, (int) collect($funnel)->max('value'));
        @endphp
        <div class="panel wide">
            <h2>Рост и активность за 7 дней</h2>
            <div class="legend"><span><i class="dot"></i>Новые пользователи</span><span><i class="dot green"></i>Активные пользователи</span><span><i class="dot pink"></i>Напоминания</span></div>
            <div class="chart" aria-label="График пользователей за 7 дней">
                @foreach($daily as $day)
                    <div class="day"><div class="columns"><div class="bar" data-value="Новых: {{ $day['users'] }}" style="height:{{ max(2, round($day['users'] / $dailyMax * 100)) }}%"></div><div class="bar green" data-value="Активных: {{ $day['active'] }}" style="height:{{ max(2, round($day['active'] / $dailyMax * 100)) }}%"></div><div class="bar pink" data-value="Напоминаний: {{ $day['reminders'] }}" style="height:{{ max(2, round($day['reminders'] / max($dailyMax, $daily->max('reminders'), 1) * 100)) }}%"></div></div><div class="day-label">{{ $day['label'] }}</div></div>
                @endforeach
            </div>
        </div>
        <div class="panel wide"><h2>Воронка использования</h2><div class="hbars">@foreach($funnel as $step)<div class="hrow"><span>{{ $step['label'] }}</span><div class="track"><div class="fill" style="width:{{ max(2, round($step['value'] / $funnelMax * 100)) }}%"></div></div><span class="hvalue">{{ $step['value'] }}</span></div>@endforeach</div><p class="muted">Возврат считается, если пользователь совершил действие не раньше чем через 24 часа после регистрации.</p></div>
        <div class="panel"><h2>Типы активности за 7 дней</h2><div class="hbars">@forelse($activityTypes as $row)<div class="hrow"><span>{{ $row->event_type }}</span><div class="track"><div class="fill" style="width:{{ max(2, round($row->total / $typeMax * 100)) }}%"></div></div><span class="hvalue">{{ $row->total }}</span></div>@empty<div class="muted">Пока нет данных</div>@endforelse</div></div>
        <div class="panel"><h2>Источники пользователей</h2><div class="hbars">@forelse($sources->take(8) as $row)<div class="hrow"><span title="{{ $row->source }}">{{ \Illuminate\Support\Str::limit($row->source, 18) }}</span><div class="track"><div class="fill green" style="width:{{ max(2, round($row->users / $sourceMax * 100)) }}%"></div></div><span class="hvalue">{{ $row->users }}</span></div>@empty<div class="muted">Пока нет данных</div>@endforelse</div></div>
        <div class="panel wide"><h2>Последние 7 дней</h2><div class="scroll"><table><thead><tr><th>Дата</th><th>Новые</th><th>Активные</th><th>Действия</th></tr></thead><tbody>@foreach($daily as $day)<tr><td>{{ $day['label'] }}</td><td>{{ $day['users'] }}</td><td>{{ $day['active'] }}</td><td>{{ $day['events'] }}</td></tr>@endforeach</tbody></table></div></div>
        <div class="panel"><h2>Команды за 7 дней</h2><table><thead><tr><th>Команда</th><th>Людей</th><th>Раз</th></tr></thead><tbody>@forelse($commands as $row)<tr><td class="command">{{ $row->event_name }}</td><td>{{ $row->users }}</td><td>{{ $row->total }}</td></tr>@empty<tr><td colspan="3" class="muted">Пока нет данных</td></tr>@endforelse</tbody></table></div>
        <div class="panel"><h2>Кнопки за 7 дней</h2><table><thead><tr><th>Кнопка</th><th>Людей</th><th>Раз</th></tr></thead><tbody>@forelse($callbacks as $row)<tr><td class="callback">{{ $row->event_name }}</td><td>{{ $row->users }}</td><td>{{ $row->total }}</td></tr>@empty<tr><td colspan="3" class="muted">Пока нет данных</td></tr>@endforelse</tbody></table></div>
        <div class="panel"><h2>Конверсия источников</h2><table><thead><tr><th>Метка</th><th>Пришли</th><th>Создали</th><th>Конверсия</th></tr></thead><tbody>@forelse($sources as $row)<tr><td>{{ $row->source }}</td><td>{{ $row->users }}</td><td>{{ $row->activated }}</td><td>{{ $row->users > 0 ? round($row->activated / $row->users * 100, 1) : 0 }}%</td></tr>@empty<tr><td colspan="4" class="muted">Пока нет данных</td></tr>@endforelse</tbody></table></div>
        <div class="panel wide"><h2>Последние действия</h2><div class="scroll"><table><thead><tr><th>Время</th><th>Пользователь</th><th>Тип</th><th>Действие</th><th>Источник</th></tr></thead><tbody>@forelse($recent as $event)<tr><td>{{ $event->created_at->format('d.m H:i:s') }}</td><td>{{ $event->user->username ? '@'.$event->user->username : trim($event->user->first_name.' '.$event->user->last_name) }} <span class="muted">#{{ $event->user->telegram_id }}</span></td><td><span class="badge">{{ $event->event_type }}</span></td><td>{{ $event->event_name }}</td><td>{{ $event->source ?? '—' }}</td></tr>@empty<tr><td colspan="5" class="muted">Сбор начнётся после развёртывания миграции</td></tr>@endforelse</tbody></table></div></div>
    </section>
</main>
<script>setTimeout(()=>location.reload(),15000)</script>
</body>
</html>
