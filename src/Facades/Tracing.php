<?php

namespace D076\Tracing\Facades;

use D076\Tracing\Context\Tags;
use Illuminate\Support\Facades\Facade;

/**
 * Публичная точка входа для управления тегами трассируемых записей из приложения.
 *
 * @method static void tag(string ...$tags)      Добавить теги поверх текущих
 * @method static void setTags(list<string> $tags)  Перезаписать набор тегов целиком
 * @method static void untag(string ...$tags)    Убрать конкретные теги
 * @method static void clearTags()               Сбросить все теги scope
 * @method static list<string> tags()            Текущие теги scope
 *
 * @see Tags
 */
final class Tracing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Tags::class;
    }
}
