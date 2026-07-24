<?php

use D076\Tracing\Context\Tags;
use Illuminate\Support\Facades\Context;

beforeEach(function () {
    $this->tags = new Tags();
    $this->tags->clearTags();
});

describe('Tags::tag (add on top)', function () {
    it('adds tags on top of existing ones', function () {
        $this->tags->tag('a');
        $this->tags->tag('b', 'c');

        expect($this->tags->tags())->toBe(['a', 'b', 'c']);
    });

    it('deduplicates, trims, and drops empty tags', function () {
        $this->tags->tag('  team:billing  ', 'team:billing', '', '   ');

        expect($this->tags->tags())->toBe(['team:billing']);
    });
});

describe('Tags::setTags (overwrite)', function () {
    it('replaces the whole set', function () {
        $this->tags->tag('a', 'b');
        $this->tags->setTags(['x', 'y']);

        expect($this->tags->tags())->toBe(['x', 'y']);
    });
});

describe('Tags::untag (remove)', function () {
    it('removes only the given tags', function () {
        $this->tags->setTags(['a', 'b', 'c']);
        $this->tags->untag('b');

        expect($this->tags->tags())->toBe(['a', 'c']);
    });
});

describe('Tags::clearTags / reset', function () {
    it('clears both the visible and hidden stores', function () {
        config()->set('tracing.tags.in_logs', true);
        $this->tags->tag('visible');
        config()->set('tracing.tags.in_logs', false);
        $this->tags->tag('hidden');

        $this->tags->reset();

        expect($this->tags->tags())->toBe([])
            ->and(Context::get('tracing.tags', []))->toBe([])
            ->and(Context::getHidden('tracing.tags', []))->toBe([]);
    });
});

describe('log visibility via tracing.tags.in_logs', function () {
    it('writes to the HIDDEN store by default (not in logs)', function () {
        config()->set('tracing.tags.in_logs', false);

        $this->tags->tag('secret-scope');

        expect(Context::getHidden('tracing.tags', []))->toBe(['secret-scope'])
            ->and(Context::get('tracing.tags', []))->toBe([]);
    });

    it('writes to the VISIBLE store when in_logs=true', function () {
        config()->set('tracing.tags.in_logs', true);

        $this->tags->tag('loggable');

        expect(Context::get('tracing.tags', []))->toBe(['loggable'])
            ->and(Context::getHidden('tracing.tags', []))->toBe([]);
    });

    it('tags() merges both stores so a config flip mid-request is not lossy', function () {
        config()->set('tracing.tags.in_logs', false);
        $this->tags->tag('from-hidden');
        // Simulate a flip: the previous write stays hidden, the next goes visible.
        Context::add('tracing.tags', ['from-visible']);

        expect($this->tags->tags())->toBe(['from-hidden', 'from-visible']);
    });
});
