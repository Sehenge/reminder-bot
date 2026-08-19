<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Отметка об уже обработанном Telegram update.
 *
 * Используется исключительно для идемпотентности вебхука: перед обработкой
 * update мы пытаемся создать запись с его update_id, полагаясь на уникальный
 * индекс в БД. Если запись уже существует (повторная доставка от Telegram),
 * обработка пропускается.
 */
class TelegramUpdate extends Model
{
    /**
     * Строки не обновляются после создания.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'update_id',
    ];

    protected $casts = [
        'update_id' => 'integer',
    ];
}
