<?php

namespace D076\Tracing\Context;

use Illuminate\Support\Facades\Context;

/**
 * Произвольные теги приложения на трассируемых записях — аналог Telescope::tag().
 *
 * Источник истины — Illuminate\Support\Facades\Context (как у {@see TraceId}):
 * Laravel сам сериализует Context при dispatch и восстанавливает в воркере, поэтому
 * теги наследуются джобами/событиями/чейнами без кода в приложении и работают из
 * CLI без входящего запроса. Singleton-кеш не используется намеренно.
 *
 * Видимость в логах управляется `tracing.tags.in_logs`: по умолчанию теги пишутся
 * в СКРЫТЫЙ сторадж Context (`addHidden`) и не подмешиваются в лог-записи; при
 * true — в видимый (`add`), тогда Laravel добавляет их в контекст каждого лога.
 *
 * Хранение — read-modify-write полного массива под одним ключом (а не push),
 * чтобы поддержать перезапись/удаление, а не только добавление.
 */
final class Tags
{
    private const CONTEXT_KEY = 'tracing.tags';

    /** Добавить теги поверх текущих. */
    public function tag(string ...$tags): void
    {
        $this->writeAll([...$this->tags(), ...$this->normalize($tags)]);
    }

    /**
     * Перезаписать набор тегов целиком.
     *
     * @param list<string> $tags
     */
    public function setTags(array $tags): void
    {
        $this->writeAll($this->normalize($tags));
    }

    /** Убрать конкретные теги. */
    public function untag(string ...$tags): void
    {
        $this->writeAll(array_values(array_diff($this->tags(), $this->normalize($tags))));
    }

    /** Сбросить все теги текущего scope. */
    public function clearTags(): void
    {
        Context::forget(self::CONTEXT_KEY);
        Context::forgetHidden(self::CONTEXT_KEY);
    }

    /**
     * Текущие теги scope. Читает оба стораджа и дедуплицирует — устойчиво к смене
     * `tracing.tags.in_logs` в рантайме.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        /** @var list<string> $hidden */
        $hidden = (array) Context::getHidden(self::CONTEXT_KEY, []);
        /** @var list<string> $visible */
        $visible = (array) Context::get(self::CONTEXT_KEY, []);

        return array_values(array_unique([...$hidden, ...$visible]));
    }

    /** Алиас clearTags() для симметрии с TraceId::reset() (зовётся middleware). */
    public function reset(): void
    {
        $this->clearTags();
    }

    /** @param list<string> $tags */
    private function writeAll(array $tags): void
    {
        $tags = array_values(array_unique($tags));

        if (config('tracing.tags.in_logs', false)) {
            Context::add(self::CONTEXT_KEY, $tags);
            Context::forgetHidden(self::CONTEXT_KEY);
        } else {
            Context::addHidden(self::CONTEXT_KEY, $tags);
            Context::forget(self::CONTEXT_KEY);
        }
    }

    /**
     * @param array<int, string> $tags
     * @return list<string>
     */
    private function normalize(array $tags): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            $tag = trim((string) $tag);

            if ($tag !== '') {
                $normalized[] = $tag;
            }
        }

        return array_values(array_unique($normalized));
    }
}
